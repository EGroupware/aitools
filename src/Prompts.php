<?php
/**
 * EGroupware AI Tools
 *
 * @package aitools
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

use EGroupware\Api;
use EGroupware\Api\Exception\WrongParameter;

class Prompts extends Api\Storage\Json
{
	const APP = 'aitools';
	const TABLE = 'egw_ai_prompts';
	const JSON_COLUMN = 'extra';    // without prefix!

	/**
	 * Constructor
	 */
	public function __construct()
	{
		parent::__construct(self::APP, self::TABLE, self::JSON_COLUMN, null,
			'prompt_', true, 'object',
			'/^(model|reasoning|timeout|temperature|max_tokens|tools|triggers)$/');

		$this->convert_all_timestamps();
	}

	/**
	 * @return static
	 */
	public static function instance()
	{
		static $instance;
		return $instance ??= new static();
	}

	const PROMPT_CACHE_LOCATION = 'prompts';

	/**
	 * Get all not disabled prompts for a given user
	 *
	 * @param int|null $account_id account_id of null for current user (not used for system-prompts)
	 * @param bool $return_system_prompts false (default) or true, return system prompts and translation templates
	 * @return array[] name => array with values for keys "name", "label", "text", ...
	 */
	public static function prompts(?int $account_id=null, bool $return_system_prompts=false) : array
	{
		// self::invalidate();
		$prompts = Api\Cache::getInstance(self::APP, self::PROMPT_CACHE_LOCATION, static function()
		{
			$instance = self::instance();
			$prompts = [];
			foreach ($instance->search(null, false, 'prompt_order,prompt_id', '', '', '', '', false,
				['(prompt_disabled IS NULL OR NOT prompt_disabled)']) as $prompt)
			{
				$prompts[$prompt['name']] = $prompt;
			}
			return $prompts;
		}, [], 86400);

		$prompts = array_filter($prompts??[], static fn($prompt) =>
			$return_system_prompts == str_starts_with($prompt['name'], 'system_prompt'));

		if (!$return_system_prompts)
		{
			if (empty($account_id))
			{
				$account_id = $GLOBALS['egw_info']['user']['account_id'];
			}
			$account_ids = Api\Accounts::getInstance()->memberships($account_id, true);
			$account_ids[] = $account_id;

			$prompts = array_filter($prompts, static fn($prompt) => (empty($prompt['account_id']) || array_intersect($prompt['account_id'], $account_ids)) &&
				(empty($prompt['triggers']) || in_array('menu', $prompt['triggers'])));
		}

		foreach ($prompts as &$prompt)
		{
			if (strpos($prompt['text'], '{{') !== false)
			{
				$prompt['text'] = preg_replace_callback('/{{(.*?)}}/', static function ($matches)
				{
					switch(strtolower($matches[1]))
					{
						case 'username':
							return $GLOBALS['egw_info']['user']['account_lid'];
						case 'userfullname':
							return $GLOBALS['egw_info']['user']['account_fullname'];
						case 'useremail':
							return $GLOBALS['egw_info']['user']['account_email'];
						case 'systemtime':
							return gmdate('Y-m-d H:i:sZ');
						case 'usertimezone':
							return $GLOBALS['egw_info']['user']['preferences']['common']['tz'] ?? 'UTC';
						case 'userdate':
							return Api\DateTime::to('now', true);
						case 'usertime':
							return Api\DateTime::to('now', false);
						case 'lang':
							return $GLOBALS['egw_info']['user']['preferences']['common']['lang'];
						case 'language':
							return Api\Translation::get_installed_langs()[$GLOBALS['egw_info']['user']['preferences']['common']['lang']] ??
								$GLOBALS['egw_info']['user']['preferences']['common']['lang'];
					}
				}, $prompt['text']);
			}
		}
		return $prompts;
	}

	public function db2data($data = null)
	{
		if (($intern = !is_array($data)))
		{
			$data = &$this->data;
		}
		$data = parent::db2data($intern ? null : $data);

		$data['account_id'] = empty($data['account_id']) ? null : explode(',', $data['account_id']);

		return $data;
	}

	public function data2db($data = null)
	{
		if (($intern = !is_array($data)))
		{
			$data = &$this->data;
		}
		$data = parent::data2db($intern ? null : $data);

		if (array_key_exists('account_id', $data))
		{
			$data['account_id'] = empty($data['account_id']) ? null : implode(',', $data['account_id']);
		}
		return $data;
	}

	/**
	 * Invalidate cached prompts
	 */
	protected function invalidate()
	{
		Api\Cache::unsetInstance(self::APP, self::PROMPT_CACHE_LOCATION);
	}

	/**
	 * Get the system prompt, both concatenated, if not disabled:
	 * - system_prompt
	 * - system_prompt_addition
	 *
	 * @param bool $translation true: system prompt for translation, false: system prompt for everything else
	 * @param bool $tools true: add "system_prompt_tools" to the system-prompt
	 * @return string
	 * @throws \Exception if there is no system prompt
	 */
	public static function systemPrompt(bool $translation=false, bool $tools=false) : string
	{
		$prompts = self::prompts(null, true);

		if ($translation)
		{
			return $prompts['system_prompt_translate']['text'] ?? $prompts['system_prompt']['text'] ??
				throw new \Exception('Missing system prompt!');
		}
		return ($prompts['system_prompt']['text'] ?? throw new \Exception('Missing system prompt!'))."\n".
			($prompts['system_prompt_addition']['text'] ?? '').
			($tools && !empty($prompts['system_prompt_tools']['text']) ? "\n".$prompts['system_prompt_tools']['text']."\n" : '');
	}

	/**
	 * Get translation prompt template (used "{lang}"), either:
	 * - aiassist.translate.custom or
	 * - aiassist.translate
	 *
	 * @return array|null
	 */
	public static function translationPromptTemplate(?array &$prompt=null) : ?string
	{
		$prompts = self::prompts();
		$prompt = $prompts['aiassist.translate.custom'] ?? $prompts['aiassist.translate'];

		return $prompt['text'] ?? null;
	}

	/**
	 * Save a prompt and invalidate the cache
	 *
	 * @param $keys
	 * @param $extra_where
	 * @return bool|int
	 */
	public function save($keys = null, $extra_where = null)
	{
		if (is_array($keys) && count($keys)) $this->data_merge($keys);

		$this->data['modifier'] = $GLOBALS['egw_info']['user']['account_id'];
		$this->data['modified'] = new Api\DateTime();
		$this->data['disabled'] = (int)!empty($this->data['disabled']);     // cast to int, as $this->empty_on_write==='NULL' will change false --> null

		// for stock entries keep disabled === NULL for not disabled, while custom entries should be 0=false
		if (!empty($this->data['id']) && !$this->data['disabled'])
		{
			$backup = $this->data;
			$old = $this->read($this->data['id']);
			$this->data = $backup;
			if (!isset($old['disabled']))
			{
				$this->data['disabled'] = null;
			}
		}

		$ret = parent::save(null, $extra_where);

		self::invalidate();

		self::updateTriggers($this->data['id'], $this->data['triggers'] ?? [], $this->data['apps'] ?? []);

		return $ret;
	}

	/**
	 * Reimplement delete to update triggers
	 *
	 * @param int|int[] $keys
	 * @param bool $only_return_query
	 * @return array|int
	 */
	public function delete($keys=null, $only_return_query=false)
	{
		if (!$only_return_query)
		{
			foreach($keys['id'] ?? (array)$keys as $id)
			{
				self::updateTriggers($id);
			}
		}
		return parent::delete($keys, $only_return_query);
	}

	/**
	 * Searches DB for rows matching search-criteria
	 * 
	 * Reimplemented to correctly handle prompt_disabled filter of '0'=activated to match NULL and false (MariaDB/MySQL 0).
	 *
	 * @param array|string $criteria array of key and data cols, OR string with search pattern (incl. * or ? as wildcards)
	 * @param boolean|string|array $only_keys True returns only keys, False returns all cols. or
	 *    comma separated list or array of columns to return
	 * @param string $order_by ='' field-names + {ASC|DESC} separated by colons ',', can also contain a GROUP BY (if it contains ORDER BY)
	 * @param string|array $extra_cols ='' string or array of strings to be added to the SELECT, eg. "count(*) as num"
	 * @param string $wildcard ='' appended befor and after each criteria
	 * @param boolean $empty False=empty criteria are ignored in query, True=empty have to be empty in row
	 * @param string $op ='AND' defaults to 'AND', can be set to 'OR' too, then criteria's are OR'ed together
	 * @param mixed $start if != false, return only maxmatch rows beginning with start, or array($start,$num), or 'UNION' for a part of a union query
	 * @param array $filter if set (!=null) col-data pairs, to be and-ed (!) into the query without wildcards
	 * @param string $join sql to do a join, added as is after the table-name, eg. "JOIN table2 ON x=y" or
	 *    "LEFT JOIN table2 ON (x=y AND z=o)", Note: there's no quoting done on $join, you are responsible for it!!!
	 * @param boolean $need_full_no_count If true an unlimited query is run to determine the total number of rows, default false
	 * @return array|NULL|true array of matching rows (the row is an array of the cols), NULL (nothing matched) or true (multiple union queries)
	 * @return array|Api\Storage\Db2DataIterator|true|NULL
	 */
	public function &search($criteria, $only_keys = True, $order_by = '', $extra_cols = '', $wildcard = '', $empty = False, $op = 'AND', $start = false, $filter = null, $join = '', $need_full_no_count = false)
	{
		if (isset($filter['disabled']) && $filter['disabled'] === '0')
		{
			unset($filter['disabled']);
			$filter[] = '(prompt_disabled IS NULL OR NOT prompt_disabled)';
		}
		return parent::search($criteria, $only_keys, $order_by, $extra_cols, $wildcard, $empty, $op, $start, $filter, $join, $need_full_no_count);
	}

	/**
	 * Optimized check if we have prompts with a trigger for the given app and trigger-type
	 *
	 * @param string $app app-name
	 * @param string $type "add", "edit" or "delete"
	 * @return int[] prompt-ids
	 */
	public static function checkTriggers(string $app, string $type) : array
	{
		$triggers = Api\Config::read(self::APP)['enabled_triggers'] ?? [];

		return array_merge($triggers[$type][$app] ?? [], $triggers[$type]['all'] ?? []);
	}

	/**
	 * Update enabled_triggers config to be able to react on triggers without having to query all prompts
	 *
	 * @param int $_prompt_id
	 * @param array $_types
	 * @param array $_apps
	 * @return void
	 */
	public static function updateTriggers(int $_prompt_id, array $_types=[], array $_apps=[]) : void
	{
		$triggers = $old_value = Api\Config::read(self::APP)['enabled_triggers'] ?? [];

		// remove $prompt_id from all triggers
		foreach($triggers as $type => &$apps)
		{
			foreach($apps as $app => &$ids)
			{
				if (($key = array_search($_prompt_id, $ids)) !== false)
				{
					unset($ids[$key]);
				}
			}
		}
		// if $_add add prompt_id to triggers
		foreach ($_types as $type)
		{
			$triggers[$type] ??= [];
			foreach ($_apps ?: ['all'] as $app)
			{
				$triggers[$type][$app] ??= [];
				$triggers[$type][$app][] = $_prompt_id;
			}
		}
		// if there's a change, store it
		if ($triggers != $old_value)
		{
			Api\Config::save_value('enabled_triggers', $triggers, self::APP);
		}
	}
}
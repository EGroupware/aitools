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
			'/^(model|reasoning|timeout|temperature|max_tokens)$/');

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

		$prompts = array_filter($prompts??[], static fn($prompt) => $return_system_prompts == in_array($prompt['name'],
			['system_prompt', 'system_prompt_addition', 'system_prompt_translate']));

		if (!$return_system_prompts)
		{
			if (empty($account_id))
			{
				$account_id = $GLOBALS['egw_info']['user']['account_id'];
			}
			$account_ids = Api\Accounts::getInstance()->memberships($account_id, true);
			$account_ids[] = $account_id;

			$prompts = array_filter($prompts, static fn($prompt) => empty($prompt['account_id']) || !in_array($prompt['account_id'], $account_ids));
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
					}
				}, $prompt['text']);
			}
		}
		return $prompts;
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
	 * @return string
	 * @throws \Exception if there is no system prompt
	 */
	public static function systemPrompt(bool $translation=false) : string
	{
		$prompts = self::prompts(null, true);

		if ($translation)
		{
			return $prompts['system_prompt_translate']['text'] ?? $prompts['system_prompt']['text'] ??
				throw new \Exception('Missing system prompt!');
		}
		return ($prompts['system_prompt']['text'] ?? throw new \Exception('Missing system prompt!'))."\n".
			($prompts['system_prompt_addition']['text'] ?? '');
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

		return $ret;
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
}
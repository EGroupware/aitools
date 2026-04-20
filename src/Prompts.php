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

class Prompts extends Api\Storage\Base
{
	const APP = 'aitools';
	const TABLE = 'egw_ai_prompts';

	/**
	 * Constructor
	 */
	public function __construct()
	{
		parent::__construct(self::APP, self::TABLE, null, 'prompt_', true, 'object');
	}

	/**
	 * @return static
	 */
	public static function instance()
	{
		static $instance;
		return $instance ??= new static();
	}

	/**
	 * Get all not disabled prompts for a given user
	 *
	 * @param int|null $account_id account_id of null for current user (not used for system-prompts)
	 * @param bool $return_system_prompts false (default) or true, return system prompts and translation templates
	 * @return array[] name => array with values for keys "name", "label", "text", ...
	 */
	public static function prompts(?int $account_id=null, bool $return_system_prompts=false) : array
	{
		Api\Cache::unsetInstance(self::APP, 'prompts');
		$prompts = Api\Cache::getInstance(self::APP, 'prompts', static function()
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

		$prompts = array_filter($prompts, static fn($prompt) => $return_system_prompts == in_array($prompt['name'],
			['system_prompt', 'system_prompt_addition']));

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
		return $prompts;
	}

	/**
	 * Get the system prompt, both concatenated, if not disabled:
	 * - system_prompt
	 * - system_prompt_addition
	 *
	 * @return string
	 */
	public static function systemPrompt()
	{
		$prompts = self::prompts(null, true);

		return ($prompts['system_prompt']['text'] ?? '')."\n".($prompts['system_prompt_addition']['text'] ?? '');
	}

	/**
	 * Get translation prompt template (used "{lang}"), either:
	 * - aiassist.translate.custom or
	 * - aiassist.translate
	 *
	 * @return array|null
	 */
	public static function translationPromptTemplate()
	{
		$prompts = self::prompts();

		return $prompts['aiassist.translate.custom'] ?? $prompts['aiassist.translate'] ?? null;
	}
}
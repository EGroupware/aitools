<?php
/**
 * EGroupware AiTools: an unconfigured main provider must not wipe the whole prompts list
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

/**
 * Bo::get_predefined_prompts()'s per-prompt 'timeout' fallback used to call
 * self::get_ai_config()['timeout'] directly, uncaught, for every prompt that doesn't carry its own
 * 'timeout' override. get_ai_config() throws when the main provider isn't configured (empty
 * ai_model with no ai_api_url/ai_custom_model) - so with the main provider unconfigured, listing
 * ANY prompt without an explicit override threw, uncaught, out of array_map(). api/user.php's own
 * bootstrap call (egw.set_prompts(...)) swallows \Throwable "to ignore not configured/installed",
 * so this silently emptied the ENTIRE client-side prompts registry - not just for prompts that
 * actually need the main provider, and regardless of DeepL-only mode or any caching.
 *
 * Confirmed live 2026-09-17 (ralf, boulder.egroupware.org) via a cache-bypassed fetch of
 * api/user.php: no egw.set_prompts(...) call at all, reproducing with the main provider
 * unconfigured (even with prompts genuinely present in the DB and DeepL configured).
 *
 * Fixed by resolving the config-provided timeout fallback once, tolerantly (try/catch), instead of
 * per-prompt and uncaught.
 */
class PredefinedPromptsTest extends \EGroupware\Api\AppTest
{
	protected $orig_config;
	protected $prompt_id;

	protected function setUp() : void
	{
		parent::setUp();
		$this->orig_config = Api\Config::read(Bo::APP);
	}

	protected function tearDown() : void
	{
		if ($this->prompt_id) (new Prompts())->delete(['id' => $this->prompt_id]);
		foreach (['ai_model', 'ai_api_url', 'ai_api_key', 'ai_custom_model'] as $key)
		{
			Api\Config::save_value($key, $this->orig_config[$key] ?? null, Bo::APP);
		}
		parent::tearDown();
	}

	public function testUnconfiguredMainProviderDoesNotWipePrompts()
	{
		foreach (['ai_model', 'ai_api_url', 'ai_api_key', 'ai_custom_model'] as $key)
		{
			Api\Config::save_value($key, null, Bo::APP);
		}

		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save([
			'name'  => 'predefined_prompts_test_'.uniqid(),
			'label' => 'PredefinedPromptsTest',
			'text'  => 'test',
		]), 'Could not create test prompt');
		$this->prompt_id = $prompts->data['id'];

		$result = (new Bo())->get_predefined_prompts(false);

		$this->assertNotEmpty($result,
			'An unconfigured main provider must not silently wipe every prompt lacking its own timeout override');
	}
}

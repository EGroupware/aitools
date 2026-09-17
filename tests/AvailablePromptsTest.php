<?php
/**
 * EGroupware AiTools: Bo::availablePrompts()/enabled() decide the et2-ai widget's visibility
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

/**
 * Moved here from Api\Etemplate\Widget\Ai (deleted, see ticket #124681 follow-up 2026-09-17):
 * whether the et2-ai widget shows its trigger button is now decided entirely by whether
 * availablePrompts() returns anything - delivered via egw.set_prompts() (api/user.php), the same
 * channel egw.set_user()/set_preferences() already use, so it reaches every widget regardless of
 * which template it's mounted from. That replaced a per-widget-instance server-side "disable"
 * modification, which never reached a widget mounted from a referenced sub-template (eg. mail's
 * preview pane, which never runs any server-side widget lifecycle code for its own content at all).
 */
class AvailablePromptsTest extends \EGroupware\Api\AppTest
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
		foreach (['ai_model', 'ai_api_url', 'ai_api_key', 'ai_custom_model', 'deepl_api_key', 'deepl_api_url'] as $key)
		{
			Api\Config::save_value($key, $this->orig_config[$key] ?? null, Bo::APP);
		}
		Api\Cache::unsetInstance(Bo::APP, 'configured-'.md5(json_encode(Api\Config::read(Bo::APP))));
		parent::tearDown();
	}

	public function testNoRunRightsMeansNoPrompts()
	{
		$apps =& $GLOBALS['egw_info']['user']['apps'];
		$had = array_key_exists('aitools', $apps);
		$backup = $apps['aitools'] ?? null;
		unset($apps['aitools']);

		try
		{
			$this->assertSame(0, Bo::enabled());
			$this->assertSame([], (new Bo())->availablePrompts());
		}
		finally
		{
			if ($had) $apps['aitools'] = $backup;
		}
	}

	public function testNothingConfiguredMeansNoPrompts()
	{
		$GLOBALS['egw_info']['user']['apps']['aitools'] = true;
		foreach (['ai_model', 'ai_api_url', 'ai_api_key', 'ai_custom_model', 'deepl_api_key', 'deepl_api_url'] as $key)
		{
			Api\Config::save_value($key, null, Bo::APP);
		}

		// a prompt existing must not matter on its own - nothing configured must still mean no icon
		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save([
			'name'  => 'available_prompts_test_'.uniqid(),
			'label' => 'AvailablePromptsTest',
			'text'  => 'test',
		]), 'Could not create test prompt');
		$this->prompt_id = $prompts->data['id'];

		$this->assertSame(0, Bo::enabled());
		$this->assertSame([], (new Bo())->availablePrompts());
	}

	public function testDeeplOnlyModeReturnsOnlyTranslatePrompts()
	{
		$GLOBALS['egw_info']['user']['apps']['aitools'] = true;
		foreach (['ai_model', 'ai_api_url', 'ai_api_key', 'ai_custom_model'] as $key)
		{
			Api\Config::save_value($key, null, Bo::APP);
		}

		// use the real "aiassist.translate" prompt if this instance already has one (eg. a live,
		// reinstalled AiTools app) rather than risk a duplicate-name collision - only seed a
		// throwaway one (and clean it up again) if genuinely missing
		if (!(new Prompts())->search(['name' => 'aiassist.translate'], false))
		{
			$prompts = new Prompts();
			$this->assertSame(0, $prompts->save([
				'name'  => 'aiassist.translate',
				'label' => 'Translate',
				'text'  => 'Translate the following text to {$lang}: {$text}',
			]), 'Could not create test "aiassist.translate" prompt');
			$this->prompt_id = $prompts->data['id'];
		}

		// force Bo::enabled() to 2 (DeepL-only) without a real DeepL account/network call - keyed
		// exactly like the real code (a hash of the actual config), so this is a real cache hit
		$config_hash = md5(json_encode(Api\Config::read(Bo::APP)));
		Api\Cache::setInstance(Bo::APP, 'configured-'.$config_hash, 2, 7200);

		$this->assertSame(2, Bo::enabled());
		$result = (new Bo())->availablePrompts();

		$this->assertNotEmpty($result, 'DeepL-only mode must still yield a usable (non-empty) prompt list');
		foreach ($result as $prompt)
		{
			$this->assertArrayHasKey('id', $prompt);
			$this->assertArrayHasKey('label', $prompt);
			$this->assertNotEmpty($prompt['id']);
		}
	}
}

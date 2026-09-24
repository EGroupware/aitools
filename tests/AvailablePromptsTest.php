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

	/**
	 * Regression test: stock prompt labels (eg. "Summarize text") only ever had lang() entries
	 * under the "aitools" app - but the et2-ai widget (and so this method) is designed to run with
	 * ANY other app as the current one, eg. mail's preview pane. Api\Translation::init() only ever
	 * auto-loads 'common'/'etemplate'/the CURRENT app/'custom', never an unrelated app just because
	 * a widget from it happens to be rendered - so every stock label came back UNTRANSLATED for any
	 * host app other than aitools itself. Reported live 2026-09-24 (ralf, boulder.egroupware.org):
	 * German UI, but "Summarize text"/"Generate a subject"/"Professional reply"/etc. all still
	 * showed in English in mail's AI menu - confirmed via egw.prompts() in the actual browser
	 * session, currentapp="mail". "Übersetzen" (Translate) happened to still work only because that
	 * exact word also has an unrelated 'common' app entry from elsewhere.
	 */
	public function testStockLabelsAreTranslatedRegardlessOfCurrentApp()
	{
		$orig_currentapp = $GLOBALS['egw_info']['flags']['currentapp'] ?? null;
		$orig_lang = $GLOBALS['egw_info']['user']['preferences']['common']['lang'] ?? null;
		try
		{
			// simulate the widget being rendered from within mail's preview pane, not aitools itself
			$GLOBALS['egw_info']['flags']['currentapp'] = 'mail';
			$GLOBALS['egw_info']['user']['preferences']['common']['lang'] = 'de';
			Api\Translation::init(true);

			$prompts = (new Bo())->get_predefined_prompts(false);

			$this->assertArrayHasKey('aiassist.summarize', $prompts);
			$this->assertSame('Text zusammenfassen', $prompts['aiassist.summarize']['label'],
				'a stock prompt label must be translated even when the widget is rendered from a '.
				'different app\'s page (eg. mail\'s preview pane)');
		}
		finally
		{
			$GLOBALS['egw_info']['flags']['currentapp'] = $orig_currentapp;
			$GLOBALS['egw_info']['user']['preferences']['common']['lang'] = $orig_lang;
			Api\Translation::init(true);
		}
	}
}

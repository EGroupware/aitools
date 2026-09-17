<?php
/**
 * EGroupware AiTools: Bo::deeplTargetLanguages() must be given the real config explicitly
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

/**
 * Bo::deeplTargetLanguages(array $config=null) only ever looks at its OWN $config parameter - it
 * never falls back to reading the real saved config itself (unlike eg. get_ai_config()/
 * test_api_connection(), which do). Api\Etemplate\Widget\Ai::enabled()'s DeepL-only fallback branch
 * used to call it bare (no args), so $config was always null and `empty($config['deepl_api_key'])`
 * was unconditionally true - it always returned [] and enabled() could never actually reach 2
 * (DeepL-only mode), even with a real, working DeepL API key saved. Found live 2026-09-17 (ralf,
 * boulder.egroupware.org): disabling the main AI provider while DeepL stayed configured left the
 * AI icon showing with a broken/empty menu (enabled() silently fell all the way to 0, hiding the
 * translate-only prompts entirely - a different symptom than the previously-fixed "#translate"
 * placeholder bug, but the same underlying "DeepL-only mode never actually triggers" root cause).
 *
 * Fixed by Ai::enabled() passing Api\Config::read(AiTools\Bo::APP) explicitly.
 */
class DeeplConfigTest extends \EGroupware\Api\AppTest
{
	protected $orig_config;

	protected function setUp() : void
	{
		parent::setUp();
		$this->orig_config = Api\Config::read(Bo::APP);
	}

	protected function tearDown() : void
	{
		foreach (['deepl_api_key', 'deepl_api_url'] as $key)
		{
			Api\Config::save_value($key, $this->orig_config[$key] ?? null, Bo::APP);
		}
		parent::tearDown();
	}

	/**
	 * The bare call (what Ai::enabled() used to do) must return [] even though a real DeepL key is
	 * saved - it never reads real config itself. A local, deliberately unreachable port proves this
	 * without depending on outside network/a real DeepL account.
	 */
	public function testBareCallIgnoresRealSavedConfig()
	{
		Api\Config::save_value('deepl_api_key', 'dummy-key-for-test', Bo::APP);
		Api\Config::save_value('deepl_api_url', 'http://127.0.0.1:1/unreachable', Bo::APP);

		$this->assertSame([], Bo::deeplTargetLanguages(),
			'A bare call (no $config passed) must never see the real saved config');
	}

	/**
	 * Passing the real config explicitly (what Ai::enabled() does now) must actually attempt to use
	 * it - proven by a real (if immediately failing) connection attempt to the configured endpoint,
	 * rather than silently short-circuiting to [] the way the bare call does.
	 */
	public function testExplicitConfigIsActuallyUsed()
	{
		Api\Config::save_value('deepl_api_key', 'dummy-key-for-test', Bo::APP);
		Api\Config::save_value('deepl_api_url', 'http://127.0.0.1:1/unreachable', Bo::APP);

		$this->expectException(\Throwable::class);
		Bo::deeplTargetLanguages(Api\Config::read(Bo::APP));
	}
}

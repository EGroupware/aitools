<?php
/**
 * EGroupware AiTools: outgoing /chat/completions request must send numbers as JSON numbers
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/FakeAiServerTestCase.php');

use EGroupware\Api;

/**
 * Bo::call_ai_api()/chatCompletions() build the outgoing JSON body straight from Api\Config values -
 * which are always plain strings, since egw_config is a varchar key/value store: whatever an
 * et2-number widget (see aitools/templates/default/config.xet's "temperature"/"max_tokens" fields)
 * submits comes back out of Api\Config::read() as a string, never a float/int.
 *
 * `max_tokens` is cast with (int) on the FIRST /chat/completions call, but NOT on the follow-up call
 * built inside the tool-call loop, and `temperature`/`top_p` are never cast at all - so a configured
 * (non-default) temperature/top_p, or a follow-up round's max_tokens, goes out as a JSON STRING, not
 * a number. Several OpenAI-compatible servers (eg. llama.cpp) validate the request against a JSON
 * schema and reject that with "Field 'temperature': type must be number, but is string.".
 *
 * Also covers the system-prompt caching fix (system_prompt/system_prompt_tools must never carry a
 * per-user/per-request value) and the getCurrentDateTime local tool.
 *
 * Reported live against a local llama.cpp endpoint, see
 * https://help.egroupware.org/t/aitools-mehrere-reproduzierbare-probleme-bei-tool-calls-und-openai-kompatiblen-apis/80042
 * (2026-09-24).
 */
class ChatCompletionsRequestTypesTest extends FakeAiServerTestCase
{
	protected function setUp() : void
	{
		parent::setUp();
		Api\Config::save_value('temperature', '0.42', Bo::APP);
		Api\Config::save_value('max_tokens', '321', Bo::APP);
	}

	public function testTemperatureIsSentAsJsonNumberNotString()
	{
		(new Bo())->process_predefined_prompt(['name' => 'test_prompt', 'text' => 'Say hi.'], 'irrelevant content');

		$requests = $this->loggedRequests();
		$this->assertNotEmpty($requests, 'AI endpoint was never called');
		$this->assertIsFloat($requests[0]['temperature'] ?? null,
			"configured temperature '0.42' must be sent as a JSON number, not the string EGroupware ".
			'stores it as - llama.cpp and other OpenAI-compatible servers reject a string here');
	}

	public function testMaxTokensIsSentAsJsonNumberOnFollowUpToolCallRound()
	{
		(new Bo())->process_predefined_prompt(
			['name' => 'test_prompt', 'text' => 'Search something.', 'tools' => ['searchContacts']],
			'irrelevant content'
		);

		$requests = $this->loggedRequests();
		$this->assertGreaterThanOrEqual(2, count($requests),
			'expected an initial call plus a follow-up call after the tool result');
		$this->assertIsInt($requests[1]['max_tokens'] ?? null,
			"configured max_tokens '321' must be sent as a JSON number on the follow-up call too, ".
			'not just the first one');
	}

	/**
	 * system_prompt/system_prompt_tools must never contain any per-user/per-request value - that's
	 * the actual root cause of the reported caching problem (system_prompt USED to embed
	 * {{userfullname}}/{{useremail}}/{{username}}/{{userdate}}/{{usertime}}/{{systemtime}}, which
	 * changed on every single request/user and defeated AI-server prefix caching entirely). Per-user
	 * context now lives on the "user" message instead (Prompts::userContext()), which was always
	 * per-request anyway, so nothing is lost - it's just no longer part of the (would-be-cached)
	 * "system" message.
	 */
	public function testSystemMessageCarriesNoPerUserContext()
	{
		(new Bo())->process_predefined_prompt(['name' => 'test_prompt', 'text' => 'Say hi.'], 'first call');
		(new Bo())->process_predefined_prompt(['name' => 'test_prompt', 'text' => 'Say hi.'], 'second call');

		$requests = $this->loggedRequests();
		$this->assertCount(2, $requests);
		$system_1 = $requests[0]['messages'][0]['content'] ?? null;
		$system_2 = $requests[1]['messages'][0]['content'] ?? null;

		$this->assertSame('system', $requests[0]['messages'][0]['role'] ?? null);
		$this->assertStringNotContainsString('{{', $system_1, 'an unresolved template variable leaked into the system prompt');
		$this->assertStringNotContainsString(
			$GLOBALS['egw_info']['user']['account_email'], $system_1,
			'the system prompt must not carry per-user context - it must stay identical for every user'
		);
		$this->assertSame($system_1, $system_2,
			'the system prompt must be byte-identical across requests to actually be cacheable');

		// the relocated context must still reach the AI somewhere - just on the "user" message
		$user_1 = $requests[0]['messages'][1]['content'] ?? '';
		$this->assertStringContainsString($GLOBALS['egw_info']['user']['account_email'], $user_1,
			'per-user context must still reach the AI, just via the user message, not the system one');
	}

	/**
	 * getCurrentDateTime must be offered whenever any tool is, and must be answered LOCALLY - not
	 * routed through Api\CalDAV\OpenAPI::toolCall() (which has no such REST operation and would
	 * answer "Invalid operationId").
	 */
	public function testGetCurrentDateTimeToolIsOfferedAndAnsweredLocally()
	{
		(new Bo())->process_predefined_prompt(
			['name' => 'test_prompt', 'text' => 'What time is it?', 'tools' => ['searchContacts']],
			'irrelevant content'
		);

		$requests = $this->loggedRequests();
		$this->assertGreaterThanOrEqual(2, count($requests));
		$tool_names = array_column(array_column($requests[0]['tools'] ?? [], 'function'), 'name');
		$this->assertContains('getCurrentDateTime', $tool_names);

		// round 2's messages include the tool result sent back for round 1's call - find it
		$tool_result = null;
		foreach ($requests[1]['messages'] ?? [] as $m)
		{
			if (($m['role'] ?? '') === 'tool') { $tool_result = $m['content'] ?? ''; break; }
		}
		$this->assertNotNull($tool_result, 'expected a tool-result message in the follow-up round');
		$this->assertStringNotContainsString('Invalid operationId', $tool_result,
			'getCurrentDateTime must be answered locally, not routed through the REST/OpenAPI tool-call machinery');
	}
}

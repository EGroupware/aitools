<?php
/**
 * EGroupware AiTools: a tool-call rejected for bad arguments must report 400, not 500
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

/**
 * AiTools' Bo::execute_tools() reports whatever HTTP status Api\CalDAV\OpenAPI::toolCall() returns
 * straight back to the LLM as the tool result's 'status' - a well-behaved client (incl. an LLM
 * following normal HTTP conventions) is expected to use it to tell "you (the LLM) got the arguments
 * wrong, fix and retry" (4xx) apart from "the server itself is broken, give up" (5xx).
 *
 * addressbook_groupdav (and, identically, infolog_groupdav and timesheet's ApiHandler) already
 * validates filters[linked] and throws a clean, actionable
 * Api\Exception("...should be '<app-name>:<numeric-ID>'!", 400) for a malformed value - but
 * Api\CalDAV::exception_handler()/runRequest() used to hardcode the reported HTTP status to 500 for
 * ANY caught exception (except JsParseException), discarding the exception's own ->getCode(), and
 * Api\CalDAV\OpenAPI::toolCall()'s own failure-message only ever echoed the exception_handler JSON
 * body's numeric 'error' code, never its human-readable 'message' text - so the LLM saw neither the
 * right status nor the actual explanation of what to fix.
 *
 * Reported live against a local llama.cpp endpoint (AI-generated searchContacts filter values),
 * see https://help.egroupware.org/t/aitools-mehrere-reproduzierbare-probleme-bei-tool-calls-und-openai-kompatiblen-apis/80042
 * (2026-09-24).
 */
class ToolCallStatusCodeTest extends \EGroupware\Api\AppTest
{
	public function testInvalidLinkedFilterReports400NotServerError()
	{
		$result = Api\CalDAV\OpenAPI::toolCall('searchContacts', ['filters[linked]' => 'not-a-valid-format']);

		$this->assertFalse($result['success'] ?? null, 'a malformed filter value must NOT be reported as success');
		$this->assertSame(400, $result['status'] ?? null,
			"a malformed filters[linked] value is the LLM's OWN mistake (bad arguments), not a ".
			'server failure - it must be reported as 400 so the LLM knows to fix and retry, not 500');
		$this->assertStringContainsString('app-name', $result['message'] ?? '',
			"the tool result's own 'message' must carry the actual explanation of the expected ".
			"format, not just the bare numeric error code");
	}
}

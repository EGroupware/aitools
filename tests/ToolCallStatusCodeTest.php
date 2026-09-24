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
 * addressbook_groupdav (and, identically, infolog_groupdav and timesheet's ApiHandler) validates
 * filters[linked] and throws a clean, actionable Api\Exception("...should be '<app-name>:<app-id>'!",
 * 400) for a malformed value - but Api\CalDAV::exception_handler()/runRequest() used to hardcode the
 * reported HTTP status to 500 for ANY caught exception (except JsParseException), discarding the
 * exception's own ->getCode(), and Api\CalDAV\OpenAPI::toolCall()'s own failure-message only ever
 * echoed the exception_handler JSON body's numeric 'error' code, never its human-readable 'message'
 * text - so the LLM saw neither the right status nor the actual explanation of what to fix.
 *
 * That same validation also used to reject an empty value outright (rather than treating it as "not
 * filtering by link") and only ever accepted a plain numeric ID, which mail's own linked-entry ids
 * (a colon-separated composite string, see Mail\Ui::generateRowID()) could never satisfy.
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

	/**
	 * filters[linked] isn't in the tool's own "required" list, but a local/small model's
	 * grammar-constrained tool-calling often can't express "omit this optional property" and sends
	 * it as an empty string instead - that must be treated as "not filtering by link", not an error,
	 * or every searchContacts call that happens to include it (even ones unrelated to linking) fails.
	 */
	public function testEmptyLinkedFilterIsIgnoredNotRejected()
	{
		$result = Api\CalDAV\OpenAPI::toolCall('searchContacts', ['filters[linked]' => '']);

		$this->assertSame(200, $result['status'] ?? null, 'an empty filters[linked] must be ignored, not rejected');
		$this->assertTrue($result['success'] ?? null);
	}

	/**
	 * The ID half of filters[linked] must accept a non-numeric, colon-separated composite id, not
	 * just a plain integer - eg. mail's own linked entries use
	 * "<account>:<profile>:<base64-folder>:<uid>" (Mail\Ui::generateRowID()), which the old
	 * `(\d+)`-only regex could never match.
	 */
	public function testMailStyleCompositeLinkedIdIsAccepted()
	{
		$result = Api\CalDAV\OpenAPI::toolCall('searchContacts',
			['filters[linked]' => 'mail:1:1:'.base64_encode('INBOX').':12345']);

		$this->assertSame(200, $result['status'] ?? null,
			'a non-numeric, colon-separated composite id (eg. mail\'s own linked-entry id format) '.
			'must be accepted, not rejected as "invalid"');
		$this->assertTrue($result['success'] ?? null);
	}
}

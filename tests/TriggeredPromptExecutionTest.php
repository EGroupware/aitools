<?php
/**
 * EGroupware AiTools: end-to-end coverage for Hooks::notifyAll()/runTriggeredPrompts()
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/FakeAiServerTestCase.php');

use EGroupware\Api;

/**
 * Hooks::runTriggeredPrompts() - the code that actually runs a prompt after an add/edit/delete
 * trigger fired - had no test coverage at all before this file: PromptsTriggerAndDeleteTest.php only
 * covers trigger *registration* (Prompts::checkTriggers()/updateTriggers()) and that execution is
 * deferred to Api\Egw::on_shutdown(), never the execution itself (the per-app JsTask/JsEvent/JsContact
 * conversion, the disabled/account_id filtering, or that it actually reaches the AI).
 */
class TriggeredPromptExecutionTest extends FakeAiServerTestCase
{
	protected $prompt_ids = [];
	protected $info_ids = [];
	protected $event_ids = [];
	protected $contact_ids = [];
	protected $timesheet_ids = [];
	protected $project_ids = [];
	protected $invoice_ids = [];
	protected $course_ids = [];

	protected function tearDown() : void
	{
		$prompts = new Prompts();
		foreach ($this->prompt_ids as $id)
		{
			$prompts->delete(['id' => $id]);
		}
		$this->prompt_ids = [];

		$infolog = new \infolog_bo();
		foreach ($this->info_ids as $id)
		{
			$infolog->delete($id);
		}
		$this->info_ids = [];

		$calendar = new \calendar_boupdate();
		foreach ($this->event_ids as $id)
		{
			$calendar->delete($id, 0, true);
		}
		$this->event_ids = [];

		$addressbook = new \addressbook_bo();
		foreach ($this->contact_ids as $id)
		{
			$addressbook->delete($id);
		}
		$this->contact_ids = [];

		$timesheet = new \timesheet_bo();
		foreach ($this->timesheet_ids as $id)
		{
			$timesheet->delete($id);
		}
		$this->timesheet_ids = [];

		$projectmanager = new \projectmanager_bo();
		foreach ($this->project_ids as $id)
		{
			$projectmanager->delete($id, true);
		}
		$this->project_ids = [];

		// only true if testInvoicesAddTriggerActuallyCallsTheAi() actually ran (ie. Invoices was
		// installed) - guards instantiating \EGroupware\Invoices\Bo, which does not exist at all
		// when the (EPL, non-GPL) Invoices app is not checked out alongside this repo
		if ($this->invoice_ids)
		{
			$invoices = new \EGroupware\Invoices\Bo();
			foreach ($this->invoice_ids as $id)
			{
				$invoices->delete($id);
			}
			$this->invoice_ids = [];
		}

		if ($this->course_ids)
		{
			// same in-process ACL grant as makeCourse() - deleteCourse() needs it too
			$account_id = $GLOBALS['egw_info']['user']['account_id'];
			$GLOBALS['egw']->acl->add_repository(\EGroupware\SmallParT\Bo::APPNAME, \EGroupware\SmallParT\Bo::ACL_ADMIN_LOCATION, $account_id, 1);
			try
			{
				$smallpart = new \EGroupware\SmallParT\Bo();
				foreach ($this->course_ids as $id)
				{
					$smallpart->deleteCourse($id);
				}
			}
			finally
			{
				$GLOBALS['egw']->acl->delete_repository(\EGroupware\SmallParT\Bo::APPNAME, \EGroupware\SmallParT\Bo::ACL_ADMIN_LOCATION, $account_id);
			}
		}
		$this->course_ids = [];

		parent::tearDown();
	}

	protected function makeInfolog(string $subject) : array
	{
		$infolog = new \infolog_bo();
		$values = [
			'info_type' => 'task',
			'info_subject' => $subject,
		];
		$info_id = $infolog->write($values, true, true, true, true);
		$this->info_ids[] = $info_id;

		return $infolog->read($info_id);
	}

	protected function makeCalendarEvent(string $title) : array
	{
		$calendar = new \calendar_boupdate();
		$start = new Api\DateTime('now', Api\DateTime::$server_timezone);
		$start->modify('+1 hour');
		$end = clone $start;
		$end->modify('+1 hour');
		$event_id = $calendar->save([
			'title' => $title,
			'owner' => $GLOBALS['egw_info']['user']['account_id'],
			'start' => $start,
			'end'   => $end,
		]);
		$this->event_ids[] = $event_id;

		return $calendar->read($event_id);
	}

	protected function makeContact(string $family_name) : array
	{
		$addressbook = new \addressbook_bo();
		$contact = [
			'n_family' => $family_name,
			'n_given'  => 'TriggeredPromptExecutionTest',
			'owner'    => $GLOBALS['egw_info']['user']['account_id'],
		];
		$contact_id = $addressbook->save($contact);
		$this->contact_ids[] = $contact_id;

		return $addressbook->read($contact_id);
	}

	protected function makeTimesheet(string $title) : array
	{
		$timesheet = new \timesheet_bo();
		$timesheet->data = [
			'ts_title'    => $title,
			'ts_start'    => time(),
			'ts_duration' => 60,
			'ts_quantity' => 1.0,
			'ts_owner'    => $GLOBALS['egw_info']['user']['account_id'],
		];
		$this->assertSame(0, $timesheet->save(), 'Could not create test timesheet entry');
		$this->timesheet_ids[] = $timesheet->data['ts_id'];

		return $timesheet->read($timesheet->data['ts_id']);
	}

	protected function makeProject(string $title) : array
	{
		$projectmanager = new \projectmanager_bo();
		$projectmanager->save([
			'pm_number'      => 'TRIGGER-TEST-'.uniqid(),
			'pm_title'       => $title,
			'pm_status'      => 'active',
			'pm_description' => 'TriggeredPromptExecutionTest',
		], true, false);
		$this->assertGreaterThan(0, (int)$projectmanager->data['pm_id'], 'Could not create test project');
		$this->project_ids[] = $projectmanager->data['pm_id'];

		return $projectmanager->read($projectmanager->data['pm_id']);
	}

	protected function makeInvoice(string $name) : array
	{
		$invoice = new \EGroupware\Invoices\Bo();
		$this->assertSame(0, $invoice->save([
			'invoice_name' => $name,
			'invoice_code' => 380,
			'invoice_buyer_name' => 'ACME Buyer Inc.',
			'invoice_buyer_address' => 'Buyer Street 1',
			'invoice_buyer_country' => 'DE',
			'invoice_buyer_postcode' => '12345',
			'invoice_buyer_city' => 'Berlin',
			'invoice_seller_name' => 'ACME Seller GmbH',
			'invoice_seller_address' => 'Seller Street 1',
			'invoice_seller_country' => 'DE',
			'invoice_seller_postcode' => '54321',
			'invoice_seller_city' => 'Hamburg',
			'invoice_grand_total' => 0,
			'invoice_due_payable' => 0,
		]), 'Could not create test invoice');
		$this->invoice_ids[] = $invoice->data['invoice_id'];

		return $invoice->data;
	}

	/**
	 * Bo::save() requires Bo::checkTeacher() for a NEW course - the default test user ("demo") is
	 * not one. Rather than switching to a real admin login (needs EGW_ADMIN_PASSWORD supplied
	 * out-of-band, see doc/ai/testing.md - not set up in every environment, and a wrong/empty
	 * password risks blocking the account after repeated attempts), grant+revoke the same
	 * smallpart-admin ACL right smallpart's own Bo::subscribe() uses internally
	 * (Bo::ACL_ADMIN_LOCATION via Acl::add_repository()/delete_repository()) directly, entirely
	 * in-process - the same effect as being a teacher, without a second login.
	 */
	protected function makeCourse(string $name) : array
	{
		$account_id = $GLOBALS['egw_info']['user']['account_id'];
		$GLOBALS['egw']->acl->add_repository(\EGroupware\SmallParT\Bo::APPNAME, \EGroupware\SmallParT\Bo::ACL_ADMIN_LOCATION, $account_id, 1);
		try
		{
			$smallpart = new \EGroupware\SmallParT\Bo();
			$course = $smallpart->save([
				'course_name'  => $name,
				'course_owner' => $account_id,
			]);
			$this->assertIsArray($course, 'Could not create test course');
			$this->course_ids[] = $course['course_id'];

			return $course;
		}
		finally
		{
			$GLOBALS['egw']->acl->delete_repository(\EGroupware\SmallParT\Bo::APPNAME, \EGroupware\SmallParT\Bo::ACL_ADMIN_LOCATION, $account_id);
		}
	}

	protected function makePrompt(array $data) : int
	{
		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save($data + [
			'name'  => 'triggered_prompt_test_'.uniqid(),
			'label' => 'TriggeredPromptExecutionTest',
			'text'  => 'Summarize the following:',
		]), 'Could not create test prompt');
		$this->prompt_ids[] = $id = $prompts->data['id'];

		return $id;
	}

	/**
	 * The actual, real end-to-end path: a real infolog entry gets converted (JsTask()) and reaches
	 * the AI - not just that the trigger got registered.
	 */
	public function testAddTriggerActuallyCallsTheAi()
	{
		$entry = $this->makeInfolog('TriggeredPromptExecutionTest end-to-end subject '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['infolog']]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'infolog', 'id' => $entry['info_id'], 'data' => $entry],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($entry['info_subject'], $requests[0]['messages'][1]['content'] ?? '',
			"the converted infolog entry's own subject must reach the AI in the user message");
	}

	/**
	 * Same end-to-end path, but for calendar (JsCalendar::JsEvent()) - the per-app conversion switch
	 * in runTriggeredPrompts() had only ever been exercised for infolog before this.
	 */
	public function testCalendarAddTriggerActuallyCallsTheAi()
	{
		$event = $this->makeCalendarEvent('TriggeredPromptExecutionTest calendar subject '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['calendar']]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'calendar', 'id' => $event['id'], 'data' => $event],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($event['title'], $requests[0]['messages'][1]['content'] ?? '',
			"the converted calendar event's own title must reach the AI in the user message");
	}

	/**
	 * Same end-to-end path, but for addressbook (JsContact::getJsCard()).
	 */
	public function testAddressbookAddTriggerActuallyCallsTheAi()
	{
		$contact = $this->makeContact('TriggeredPromptExecutionTest-'.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['addressbook']]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'addressbook', 'id' => $contact['id'], 'data' => $contact],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($contact['n_family'], $requests[0]['messages'][1]['content'] ?? '',
			"the converted contact's own family name must reach the AI in the user message");
	}

	/**
	 * Same end-to-end path, but for timesheet (JsTimesheet::JsTimesheet()).
	 */
	public function testTimesheetAddTriggerActuallyCallsTheAi()
	{
		$timesheet = $this->makeTimesheet('TriggeredPromptExecutionTest timesheet subject '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['timesheet']]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'timesheet', 'id' => $timesheet['ts_id'], 'data' => $timesheet],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($timesheet['ts_title'], $requests[0]['messages'][1]['content'] ?? '',
			"the converted timesheet entry's own title must reach the AI in the user message");
	}

	/**
	 * Same end-to-end path, but for projectmanager (JsObjects::JsProject()).
	 */
	public function testProjectmanagerAddTriggerActuallyCallsTheAi()
	{
		$project = $this->makeProject('TriggeredPromptExecutionTest project subject '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['projectmanager']]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'projectmanager', 'id' => $project['pm_id'], 'data' => $project],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($project['pm_title'], $requests[0]['messages'][1]['content'] ?? '',
			"the converted project's own title must reach the AI in the user message");
	}

	/**
	 * Same end-to-end path, but for invoices (JsObjects::JsInvoice()).
	 *
	 * Invoices is an EPL (non-GPL) app: unlike infolog/calendar/addressbook/timesheet/
	 * projectmanager (bundled in the main egroupware/egroupware repo, always present) it must never
	 * be assumed to be checked out alongside this (GPL, separately-repo'd) test suite - eg. the
	 * public repo's own CI never has it.
	 */
	public function testInvoicesAddTriggerActuallyCallsTheAi()
	{
		if (!class_exists(\EGroupware\Invoices\Bo::class))
		{
			$this->markTestSkipped('Invoices app not installed');
		}
		$invoice = $this->makeInvoice('TriggeredPromptExecutionTest invoice '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['invoices']]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'invoices', 'id' => $invoice['invoice_id'], 'data' => $invoice],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($invoice['invoice_name'], $requests[0]['messages'][1]['content'] ?? '',
			"the converted invoice's own name must reach the AI in the user message");
	}

	/**
	 * Same end-to-end path, but for smallpart (JsObjects::JsCourse()).
	 *
	 * SmallParT lives in its own repository, same as this one - it must never be assumed present
	 * just because this test suite is running.
	 */
	public function testSmallpartAddTriggerActuallyCallsTheAi()
	{
		if (!class_exists(\EGroupware\SmallParT\Bo::class))
		{
			$this->markTestSkipped('SmallParT app not installed');
		}
		$course = $this->makeCourse('TriggeredPromptExecutionTest course '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['smallpart']]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'smallpart', 'id' => $course['course_id'], 'data' => $course],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($course['course_name'], $requests[0]['messages'][1]['content'] ?? '',
			"the converted course's own name must reach the AI in the user message");
	}

	/**
	 * A "delete" trigger must fire too, converting the entry's data exactly as an "add"/"update" one
	 * would - infolog_bo::delete() itself calls Link::notify_update($app, $id, $info, 'delete') with
	 * $info being the entry's state captured just BEFORE the (usually soft-)delete, so the data given
	 * to runTriggeredPrompts() here is realistic even though this test never actually deletes the
	 * entry itself (consistent with the other tests in this file, which also call
	 * Hooks::runTriggeredPrompts() directly rather than exercising the full real add/edit/delete
	 * plumbing).
	 */
	public function testDeleteTriggerActuallyCallsTheAi()
	{
		$entry = $this->makeInfolog('TriggeredPromptExecutionTest delete-trigger subject '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['delete'], 'apps' => ['infolog']]);

		Hooks::runTriggeredPrompts(['type' => 'delete', 'app' => 'infolog', 'id' => $entry['info_id'], 'data' => $entry],
			[$prompt_id]);

		$requests = $this->loggedRequests();
		$this->assertCount(1, $requests, 'the delete-triggered prompt must have called the AI exactly once');
		$this->assertStringContainsString($entry['info_subject'], $requests[0]['messages'][1]['content'] ?? '',
			"the (pre-delete) converted infolog entry's own subject must reach the AI in the user message");
	}

	/**
	 * Unlike "edit" (translated to "update"), a "delete" event type must reach checkTriggers()/the
	 * deferred call UNCHANGED - Hooks::notifyAll()'s ternary only special-cases "edit".
	 */
	public function testDeleteEventTypeReachesDeferredCallUnchanged()
	{
		$prompt_id = $this->makePrompt(['triggers' => ['delete'], 'apps' => ['infolog']]);

		$this->assertContains($prompt_id, Prompts::checkTriggers('infolog', 'delete'));

		$callbacks = new \ReflectionProperty(Api\Egw::class, 'shutdown_callbacks');
		$callbacks->setAccessible(true);
		$before = $callbacks->getValue();

		Hooks::notifyAll(['type' => 'delete', 'app' => 'infolog', 'id' => 999999, 'data' => []]);

		$after = $callbacks->getValue();
		$this->assertCount(count($before) + 1, $after, 'a "delete" event for a "delete"-triggered prompt must defer a run');
		$this->assertContains($prompt_id, $after[0][2] ?? [],
			'the deferred call must be for the "delete"-registered prompt');

		// don't let it actually run (and call the AI) at process shutdown
		$callbacks->setValue(null, $before);
	}

	/**
	 * A disabled prompt must never fire, even if explicitly passed in $prompt_ids (eg. a stale list
	 * captured before the prompt got disabled, in the window before the deferred on_shutdown() runs).
	 */
	public function testDisabledPromptDoesNotFire()
	{
		$entry = $this->makeInfolog('TriggeredPromptExecutionTest disabled subject '.uniqid());
		$prompt_id = $this->makePrompt(['triggers' => ['add'], 'apps' => ['infolog'], 'disabled' => 1]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'infolog', 'id' => $entry['info_id'], 'data' => $entry],
			[$prompt_id]);

		$this->assertEmpty($this->loggedRequests(), 'a disabled prompt must never call the AI');
	}

	/**
	 * A prompt restricted to specific account_id(s) must only fire for one of those accounts, not
	 * for whoever happened to trigger the underlying add/edit/delete.
	 */
	public function testAccountRestrictedPromptOnlyFiresForMatchingAccount()
	{
		// a fixed sentinel like -1 is NOT safe here: EGroupware uses NEGATIVE ids for groups, so -1
		// can be a real, existing group the current user happens to belong to, depending on what's
		// seeded in a given environment (confirmed live: passed against the local docker test
		// instance, failed in CI - https://github.com/EGroupware/egroupware/actions/runs/35972208103/job/107544290911,
		// because CI's seed data makes the demo user a member of group -1). Compute an id that's
		// guaranteed to not be one of the CURRENT user's own memberships, the same way
		// Hooks::runTriggeredPrompts() itself does, instead of guessing a supposedly-impossible one.
		$own_account_ids = Api\Accounts::getInstance()->memberships($GLOBALS['egw_info']['user']['account_id'], true);
		$own_account_ids[] = $GLOBALS['egw_info']['user']['account_id'];
		$other_account_id = min($own_account_ids) - 1000000;
		$entry = $this->makeInfolog('TriggeredPromptExecutionTest account-restricted subject '.uniqid());
		$prompt_id = $this->makePrompt([
			'triggers' => ['add'], 'apps' => ['infolog'], 'account_id' => [$other_account_id],
		]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'infolog', 'id' => $entry['info_id'], 'data' => $entry],
			[$prompt_id]);
		$this->assertEmpty($this->loggedRequests(),
			'a prompt restricted to a DIFFERENT account must not fire for the current user');

		(new Prompts())->save(['id' => $prompt_id, 'account_id' => [$GLOBALS['egw_info']['user']['account_id']]]);

		Hooks::runTriggeredPrompts(['type' => 'add', 'app' => 'infolog', 'id' => $entry['info_id'], 'data' => $entry],
			[$prompt_id]);
		$this->assertCount(1, $this->loggedRequests(),
			'a prompt restricted to the CURRENT account must fire normally');
	}

	/**
	 * Hooks::notifyAll() translates a plain "edit" event type to the "update" trigger type
	 * (checkTriggers()/updateTriggers() only ever use 'add', 'update', 'delete') - a prompt
	 * registered for 'update' must be found for an "edit" event, not just literally-named "update"
	 * events.
	 */
	public function testEditEventTypeMapsToUpdateTrigger()
	{
		$prompt_id = $this->makePrompt(['triggers' => ['update'], 'apps' => ['infolog']]);

		$this->assertContains($prompt_id, Prompts::checkTriggers('infolog', 'update'));

		$callbacks = new \ReflectionProperty(Api\Egw::class, 'shutdown_callbacks');
		$callbacks->setAccessible(true);
		$before = $callbacks->getValue();

		Hooks::notifyAll(['type' => 'edit', 'app' => 'infolog', 'id' => 999999, 'data' => []]);

		$after = $callbacks->getValue();
		$this->assertCount(count($before) + 1, $after, 'an "edit" event for an "update"-triggered prompt must still defer a run');
		// on_shutdown() prepends (array_unshift) - the newest registration is always at index 0, and
		// its own stored shape is the flat [$callback, ...$args] = [$callback, $data, $prompt_ids]
		$this->assertContains($prompt_id, $after[0][2] ?? [],
			'the deferred call must be for the "update"-registered prompt');

		// don't let it actually run (and call the AI) at process shutdown
		$callbacks->setValue(null, $before);
	}
}

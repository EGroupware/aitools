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
		$other_account_id = -1; // no real account can ever match this
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

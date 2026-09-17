<?php
/**
 * Regression tests for ticket #124681:
 * - disabling a prompt did not stop it from being executed (Prompts::save() always re-registered
 *   the prompt's triggers, even when 'disabled' was just set)
 * - the admin list's "Delete" action was entirely unimplemented (Admin::action() unconditionally
 *   threw "To be implemented ;)")
 * - running a triggered prompt (an LLM call) synchronously inside Hooks::notifyAll() slowed down
 *   saving the unrelated entry (in another app) that triggered it - must be deferred to run via
 *   Api\Egw::on_shutdown(), ie. after the response was already sent to the user
 *
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

class PromptsTriggerAndDeleteTest extends \EGroupware\Api\AppTest
{
	protected $prompt_ids = [];

	protected function tearDown() : void
	{
		$prompts = new Prompts();
		foreach ($this->prompt_ids as $id)
		{
			// must be ['id' => $id], NOT a bare scalar - Storage\Base::delete() wraps a bare scalar
			// via the raw db column name (prompt_id), which data2db()/the column-lookup below then
			// fails to match back to the app-level 'id' key, silently deleting nothing (confirmed:
			// affected_rows()===0) - this leaked a row per test run before this fix
			$prompts->delete(['id' => $id]);
		}
		parent::tearDown();
	}

	public function testDisablingRemovesFromTriggers()
	{
		$prompts = new Prompts();
		$data = [
			'name'     => 'prompts_trigger_test_'.uniqid(),
			'label'    => 'PromptsTriggerAndDeleteTest',
			'text'     => 'test',
			'triggers' => ['add'],
			'apps'     => ['infolog'],
		];
		$this->assertSame(0, $prompts->save($data), 'Could not create test prompt');
		$id = $prompts->data['id'];
		$this->prompt_ids[] = $id;

		$this->assertContains($id, Prompts::checkTriggers('infolog', 'add'),
			'A newly created, enabled prompt must be registered as a trigger');

		// Disable it and save again - exactly like the real edit form, which always submits the
		// full content (including 'apps'/'triggers' as arrays from the multi-select widgets),
		// never a partial patch.
		(new Prompts())->save(['id' => $id] + $data + ['disabled' => 1]);

		$this->assertNotContains($id, Prompts::checkTriggers('infolog', 'add'),
			'A disabled prompt must not remain registered as a trigger, or it keeps firing');
	}

	/**
	 * Regression test for a mass-deletion bug found while investigating this same file's own
	 * (previously silently no-op'ing) tearDown() cleanup: Admin::action()'s 'delete' case used to
	 * call `$this->prompts->delete($selected)` with $selected a bare list of ids, eg. [75]. That
	 * hits Storage\Base::delete()'s "keep sql fragments (with integer key)" branch, which treats an
	 * integer-keyed array entry as a raw SQL WHERE fragment, not a primary-key value - so a bare [75]
	 * becomes the literal fragment "75" (a nonzero literal, always true in SQL), deleting every row
	 * in the WHOLE table, not just the one selected. Confirmed live via a throwaway 3-row/1-selected
	 * test: all 3 rows vanished, not just the selected one. Fixed by keying it ['id' => $selected].
	 */
	public function testActionDeleteOnlyRemovesSelectedEntry()
	{
		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save([
			'name'  => 'prompts_delete_test_'.uniqid(),
			'label' => 'PromptsTriggerAndDeleteTest delete',
			'text'  => 'test',
		]), 'Could not create test prompt');
		$id = $prompts->data['id'];
		$this->prompt_ids[] = $id;

		// a sibling that must survive - this is what the mass-deletion bug would wipe out too
		$survivor = new Prompts();
		$this->assertSame(0, $survivor->save([
			'name'  => 'prompts_survivor_test_'.uniqid(),
			'label' => 'PromptsTriggerAndDeleteTest survivor',
			'text'  => 'test',
		]), 'Could not create survivor test prompt');
		$survivor_id = $survivor->data['id'];
		$this->prompt_ids[] = $survivor_id;

		$admin = new Admin();
		$action = new \ReflectionMethod($admin, 'action');
		$action->setAccessible(true);
		$action->invoke($admin, 'delete', [$id], false);

		$this->assertFalse((new Prompts())->read($id), 'Prompt must be gone after the delete action');
		$this->assertNotFalse((new Prompts())->read($survivor_id),
			'delete action must only remove the selected entry, not every prompt in the table');
	}

	/**
	 * delete() (unlike save()) never invalidated the 24h-cached Prompts::prompts() list - a deleted
	 * prompt kept showing up (and remained runnable/triggerable) in prompt menus for up to a day.
	 * Found incidentally while investigating the mass-deletion bug above.
	 */
	public function testDeleteInvalidatesPromptsCache()
	{
		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save([
			'name'  => 'prompts_cache_invalidate_test_'.uniqid(),
			'label' => 'PromptsTriggerAndDeleteTest cache',
			'text'  => 'test',
		]), 'Could not create test prompt');
		$id = $prompts->data['id'];
		$name = $prompts->data['name'];
		$this->prompt_ids[] = $id;

		$this->assertArrayHasKey($name, Prompts::prompts(), 'Newly saved prompt must be in the cached list');

		(new Prompts())->delete(['id' => $id]);

		$this->assertArrayNotHasKey($name, Prompts::prompts(),
			'Deleted prompt must not remain in the cached prompts() list');
	}

	/**
	 * notifyAll() must NOT run the (potentially slow, LLM-calling) prompt inline - it must defer
	 * that to Api\Egw::on_shutdown(), so triggering a prompt from saving an entry in another app
	 * does not itself slow down that save.
	 */
	public function testPromptExecutionIsDeferredToShutdown()
	{
		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save([
			'name'     => 'prompts_defer_test_'.uniqid(),
			'label'    => 'PromptsTriggerAndDeleteTest defer',
			'text'     => 'test',
			'triggers' => ['add'],
			'apps'     => ['infolog'],
		]), 'Could not create test prompt');
		$this->prompt_ids[] = $prompts->data['id'];

		$callbacks = new \ReflectionProperty(Api\Egw::class, 'shutdown_callbacks');
		$callbacks->setAccessible(true);
		$before = $callbacks->getValue();

		Hooks::notifyAll(['type' => 'add', 'app' => 'infolog', 'id' => 999999, 'data' => []]);

		$after = $callbacks->getValue();
		$this->assertCount(count($before) + 1, $after,
			'notifyAll() must register exactly one new Egw::on_shutdown() callback, not run inline');
		$this->assertSame([Hooks::class, 'runTriggeredPrompts'], $after[0][0] ?? null,
			'The registered shutdown callback must be Hooks::runTriggeredPrompts');

		// clean up: don't let this callback actually run (and call an LLM) at process shutdown
		$callbacks->setValue(null, $before);
	}
}

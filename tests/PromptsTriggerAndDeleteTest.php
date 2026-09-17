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
			$prompts->delete($id);
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

	public function testActionDeleteWorks()
	{
		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save([
			'name'  => 'prompts_delete_test_'.uniqid(),
			'label' => 'PromptsTriggerAndDeleteTest delete',
			'text'  => 'test',
		]), 'Could not create test prompt');
		$id = $prompts->data['id'];
		$this->prompt_ids[] = $id;

		$admin = new Admin();
		$action = new \ReflectionMethod($admin, 'action');
		$action->setAccessible(true);
		$action->invoke($admin, 'delete', [$id], false);

		$this->assertFalse((new Prompts())->read($id), 'Prompt must be gone after the delete action');
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

<?php
/**
 * Test the ajax endpoint the prompt list's Delete action now calls
 *
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

/**
 * Admin::ajax_action() is a new endpoint: Delete used to submit the whole eTemplate, rebuilding
 * the list and losing its scroll position and selection.
 *
 * It also narrows "select all", which used to read every prompt in the table
 * (`$this->prompts->search(null, ...)`) regardless of what the list was filtered to - so
 * selecting all of a three-row search result deleted those three *and* everything the search had
 * filtered out. It now re-runs the query get_rows() cached, and refuses if there is none.
 *
 * PASS CRITERIA
 * The prompts really went away (read back through Prompts), the ones outside the filter did not,
 * and the response carries an egw.refresh call - without which the list drops no rows.
 */
class AjaxActionTest extends \EGroupware\Api\AppTest
{
	protected $prompt_ids = [];

	protected function setUp() : void
	{
		parent::setUp();
		Api\Json\Response::get()->initResponseArray();
		Api\Cache::unsetSession('aitools', 'index');
	}

	protected function tearDown() : void
	{
		$prompts = new Prompts();
		foreach ($this->prompt_ids as $id)
		{
			// must be ['id' => $id]: a bare scalar is wrapped via the raw column name and matches
			// nothing, silently leaking a row per run
			$prompts->delete(['id' => $id]);
		}
		$this->prompt_ids = [];
		Api\Cache::unsetSession('aitools', 'index');
		parent::tearDown();
	}

	/**
	 * A real eTemplate request id, the way the browser sends one along - the endpoint refuses
	 * without it, see Nextmatch::validateExecId().  Writing to the request is what persists it.
	 */
	protected function execId() : string
	{
		$request = \EGroupware\Api\Etemplate\Request::read();
		$id = $request->id();
		$request->content = ['nm' => []];
		unset($request);
		return $id;
	}

	/**
	 * The egw.refresh call the response should carry, or null
	 */
	protected function refreshCall() : ?array
	{
		$response = Api\Json\Response::get();
		$prop = (new \ReflectionClass($response))->getProperty('responseArray');
		$prop->setAccessible(true);
		foreach((array)$prop->getValue($response) as $chunk)
		{
			$chunk = (array)$chunk;
			if (($chunk['type'] ?? null) === 'apply' && (($chunk['data']['func'] ?? null) === 'egw.refresh'))
			{
				return (array)$chunk['data']['parms'];
			}
		}
		return null;
	}

	protected function makePrompt(string $label) : int
	{
		$prompts = new Prompts();
		$this->assertSame(0, $prompts->save([
			'name'  => 'ajax_action_test_'.uniqid(),
			'label' => $label,
			'text'  => 'created by aitools/tests/AjaxActionTest.php',
		]), 'could not create the test prompt');

		return $this->prompt_ids[] = $prompts->data['id'];
	}

	protected function exists($id) : bool
	{
		return (bool)(new Prompts())->read(['id' => $id]);
	}

	/**
	 * The regression shape: the endpoint has to reach action()'s body and really delete.
	 */
	public function testDeleteRemovesThePrompt()
	{
		$id = $this->makePrompt('AjaxActionTest delete');
		$this->assertTrue($this->exists($id), 'fixture was not created');

		(new Admin())->ajax_action($this->execId(), 'delete', [$id]);

		$this->assertFalse($this->exists($id), 'delete must remove the prompt');
		$this->assertNotNull($this->refreshCall(),
			'the endpoint must answer with egw.refresh, or the list drops no rows');
	}

	/**
	 * Without a valid exec id the endpoint must do nothing at all.
	 */
	public function testABogusExecIdDeletesNothing()
	{
		$id = $this->makePrompt('AjaxActionTest bogus exec id');

		(new Admin())->ajax_action('aitools_nobody_not-a-real-request-id', 'delete', [$id]);

		$this->assertTrue($this->exists($id), 'a rejected request must not run the action');
		$this->assertNull($this->refreshCall(), 'and must not answer with egw.refresh either');
	}

	/**
	 * "Select all" acts on what the list was filtered to, not on the whole table.
	 */
	public function testSelectAllUsesTheCachedQuery()
	{
		$match = $this->makePrompt('AjaxActionTest SELECTALL match');
		$control = $this->makePrompt('AjaxActionTest control');

		// what Admin::get_rows() caches when the list is searched for "SELECTALL"
		$admin = new Admin();
		$rows = $readonlys = [];
		$query = ['search' => 'SELECTALL', 'start' => 0, 'num_rows' => 25, 'col_filter' => []];
		$admin->get_rows($query, $rows, $readonlys);

		$admin->ajax_action($this->execId(), 'delete', [], true);

		$this->assertFalse($this->exists($match), 'select all must delete what the search matched');
		$this->assertTrue($this->exists($control), 'and must not touch what it did not');
	}

	/**
	 * With nothing cached there is no way to know what the user was looking at, and the old
	 * behaviour - every prompt in the table - is not a safe default.
	 */
	public function testSelectAllRefusesWithoutACachedQuery()
	{
		$id = $this->makePrompt('AjaxActionTest no cache');
		Api\Cache::unsetSession('aitools', 'index');

		(new Admin())->ajax_action($this->execId(), 'delete', [], true);

		$this->assertTrue($this->exists($id), 'nothing may be deleted without a cached query');
		$parms = $this->refreshCall();
		$this->assertSame('error', $parms[7] ?? null, 'and the user has to be told');
	}

	/**
	 * _targetapp must be a real app name: egw.refresh() resolves it before its msg-only
	 * early-return, and a name that is not an app throws in the kdots framework.
	 */
	public function testRefreshNamesTheAppInBothSlots()
	{
		$id = $this->makePrompt('AjaxActionTest refresh args');

		(new Admin())->ajax_action($this->execId(), 'delete', [$id]);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms);
		$this->assertSame('aitools', $parms[1],
			'aitools sends no push, so it cannot use the msg-only sentinel');
		$this->assertSame('aitools', $parms[4], 'never the msg-only-push-refresh sentinel');
	}

	/**
	 * More than one row changed means no id at all: egw.refresh() takes a single id, and
	 * Et2Nextmatch.refresh(id, null) only defaults its type when that type is undefined - a
	 * literal null falls through and updates nothing.
	 */
	public function testAMultiRowDeleteAsksForAFullReload()
	{
		$first = $this->makePrompt('AjaxActionTest multi 1');
		$second = $this->makePrompt('AjaxActionTest multi 2');

		(new Admin())->ajax_action($this->execId(), 'delete', [$first, $second]);

		$parms = $this->refreshCall();
		$this->assertNotNull($parms);
		$this->assertNull($parms[2], 'no single id for a multi-row action');
		$this->assertNull($parms[3], 'and no type, so egw.refresh reloads the list');
	}
}

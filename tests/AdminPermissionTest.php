<?php
/**
 * EGroupware AI Tools - prompts are edited by admins only
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once __DIR__.'/../../api/tests/LoggedInTest.php';

use EGroupware\Api;
use EGroupware\Api\LoggedInTest;

/**
 * Admin::index()/edit() are public functions, reachable by every user with run rights on aitools,
 * and a prompt runs with the rights of the user running it - so a non-admin editing one acted
 * through everyone using it.
 */
class AdminPermissionTest extends LoggedInTest
{
	private $apps_backup;

	protected function setUp() : void
	{
		parent::setUp();
		$this->apps_backup = $GLOBALS['egw_info']['user']['apps'];
		unset($GLOBALS['egw_info']['user']['apps']['admin']);
	}

	protected function tearDown() : void
	{
		$GLOBALS['egw_info']['user']['apps'] = $this->apps_backup;
		parent::tearDown();
	}

	public function testNonAdminCannotListPrompts()
	{
		$this->expectException(Api\Exception\NoPermission\Admin::class);
		$rows = [];
		(new Admin())->get_rows(['start' => 0, 'num_rows' => 10, 'col_filter' => []], $rows);
	}

	public function testNonAdminCannotEditPrompts()
	{
		$this->expectException(Api\Exception\NoPermission\Admin::class);
		(new Admin())->edit(['name' => 'x', 'text' => 'x']);
	}

	public function testNonAdminCannotOpenThePromptList()
	{
		$this->expectException(Api\Exception\NoPermission\Admin::class);
		(new Admin())->index(['nm' => []]);
	}

	public function testAdminCanListPrompts()
	{
		$GLOBALS['egw_info']['user']['apps'] = $this->apps_backup;
		if (empty($GLOBALS['egw_info']['user']['apps']['admin']))
		{
			$this->markTestSkipped('EGW_USER is no admin');
		}
		$rows = [];
		$total = (new Admin())->get_rows(['start' => 0, 'num_rows' => 10, 'col_filter' => []], $rows);
		$this->assertGreaterThanOrEqual(0, (int)$total);
		$this->assertIsArray($rows);
	}
}

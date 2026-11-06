<?php
/**
 * EGroupware AI Tools - Admin UI to create custom prompts
 *
 * @package aitools
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

use EGroupware\Api;

class Admin
{
	const APP = 'aitools';
	/**
	 * Methods callable via menuaction GET parameter
	 *
	 * @var array
	 */
	public $public_functions = [
		'index' => true,
		'edit'  => true,
	];

	/**
	 * Instance of our business object
	 *
	 * @var Prompts
	 */
	protected $prompts;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->prompts = new Prompts();

		Api\Translation::add_app(self::APP);
	}

	/**
	 * Edit a host
	 *
	 * @param ?array $content =null
	 */
	public function edit(?array $content=null)
	{
		if (!is_array($content))
		{
			if (!empty($_GET['prompt_id']))
			{
				if (!($content = $this->prompts->read((int)$_GET['prompt_id'])))
				{
					Api\Framework::window_close(lang('Entry not found!'));
				}
				if (!isset($content['disabled']))
				{
					Api\Framework::message(lang('Please do NOT modify stock prompts, disable them instead and add your own.'), 'info');
				}
			}
			else
			{
				$content = $this->prompts->init();
			}
		}
		else
		{
			$button = key($content['button'] ?? []);
			unset($content['button']);
			switch($button)
			{
				case 'save':
				case 'apply':
					if (empty($content['id']) && $this->prompts->not_unique($content))
					{
						Api\Etemplate::set_validation_error('name', lang('This ID is already in use!'));
						break;
					}
					elseif (!$this->prompts->save($content))
					{
						Api\Framework::refresh_opener(lang('Entry saved.'),
							self::APP, $this->prompts->data['id'],
							empty($content['id']) ? 'add' : 'edit');

						$content = array_merge($content, $this->prompts->data);
					}
					else
					{
						Api\Framework::message(lang('Error storing entry!'));
						unset($button);
					}
					if ($button === 'save')
					{
						Api\Framework::window_close();	// does NOT return
					}
					Api\Framework::message(lang('Entry saved.'));
					break;

				case 'delete':
					if (!$this->prompts->delete(['id' => $content['id']]))
					{
						Api\Framework::message(lang('Error deleting entry!'));
					}
					else
					{
						Api\Framework::refresh_opener(lang('Entry deleted.'),
							self::APP, $content['id'], 'delete');

						Api\Framework::window_close();	// does NOT return
					}
			}
		}
		$readonlys = [
			'button[delete]' => empty($content['id']),
		];
		$sel_options = [
			'tools' => Api\CalDAV\OpenAPI::operationIds(),
		];
		$tmpl = new Api\Etemplate(self::APP.'.prompt');
		$tmpl->exec(self::APP.'.'.self::class.'.edit', $content, $sel_options, $readonlys, $content, 2);
	}

	/**
	 * Fetch rows to display
	 *
	 * @param array $query
	 * @param ?array& $rows =null
	 * @param ?array& $readonlys =null
	 */
	public function get_rows($query, ?array &$rows=null, ?array &$readonlys=null)
	{
		if (!empty($query['order']) && $query['order'] !== 'account_id' && !str_starts_with($query['order'], 'prompt_'))
		{
			$query['order'] = 'prompt_'.$query['order'];
		}
		// Remember what the list is showing, so action() can expand a "select all" to the rows the
		// user can actually see - it is handed only the ids the client sent
		if (empty($query['csv_export']))
		{
			Api\Cache::setSession(self::APP, 'index',
				array_intersect_key($query, array_flip(['search', 'col_filter', 'order', 'sort'])));
		}
		$total = $this->prompts->get_rows($query, $rows, $readonlys);
		foreach($rows as &$row)
		{
			if (!empty($row['disabled']))
			{
				$row['class'] = 'promptDisabled';
			}
		}
		return $total;
	}

	/**
	 * Index
	 *
	 * @param ?array $content =null
	 */
	public function index(?array $content=null)
	{
		if (!is_array($content) || empty($content['nm']))
		{
			$content = [
				'nm' => [
					'get_rows'       =>	self::APP.'.'.self::class.'.get_rows',
					'no_filter'      => true,	// disable the diverse filters we not (yet) use
					'no_filter2'     => true,
					'no_cat'         => true,
					'order'          =>	'prompt_id',// IO name of the column to sort after (optional for the sortheaders)
					'sort'           =>	'ASC',// IO direction of the sort: 'ASC' or 'DESC'
					'row_id'         => 'id',
					'row_modified'   => 'modified',
					'actions'        => $this->get_actions(),
					'placeholder_actions' => array('add')
				]
			];
		}
		elseif(!empty($content['nm']['action']))
		{
			try {
				Api\Framework::message($this->action($content['nm']['action'],
					$content['nm']['selected'], $content['nm']['select_all']));
			}
			catch (\Exception $ex) {
				Api\Framework::message($ex->getMessage(), 'error');
			}
		}
		$sel_options = [
			'disabled' => ['0' => 'Enabled', '1' => 'Disabled'],
		];
		$tmpl = new Api\Etemplate('aitools.prompts');
		$tmpl->exec(self::APP.'.'.self::class.'.index', $content, $sel_options, [], ['nm' => $content['nm']]);
	}

	/**
	 * Return actions for cup list
	 *
	 * @param array $cont values for keys license_(nation|year|cat)
	 * @return array
	 */
	protected function get_actions()
	{
		return [
			'edit' => [
				'caption' => 'Open',
				'default' => true,
				'allowOnMultiple' => false,
				'url' => 'menuaction='.self::APP.'.'.self::class.'.edit&prompt_id=$id',
				'popup' => '640x540',
				'group' => $group=0,
			],
			'add' => [
				'caption' => 'Add',
				'url' => 'menuaction='.self::APP.'.'.self::class.'.edit',
				'popup' => '640x500',
				'group' => $group,
			],
			'delete' => [
				'caption' => 'Delete',
				'confirm' => 'Delete this prompt(s)',
				'group' => $group=5,
				'onExecute' => 'javaScript:app.aitools.ajax_action',
				// the class is namespaced, so the "<app>.<app>_ui.ajax_action" convention the
				// client falls back to would not find it
				'data' => ['menuaction' => self::APP.'.'.self::class.'.ajax_action'],
			],
		];
	}

	/**
	 * Run the prompt list's context-menu actions over ajax, so the list keeps its scroll position
	 * and selection instead of being rebuilt
	 *
	 * @param string $exec_id eTemplate request this came from - the only thing saying the caller
	 *	had one of our pages open, see Nextmatch::validateExecId()
	 * @param string $action 'delete'
	 * @param string[] $selected prompt ids
	 * @param bool $all_selected expanded by action() from the filters the list last ran
	 */
	public function ajax_action($exec_id, $action, array $selected, $all_selected = false)
	{
		if (!Api\Etemplate\Widget\Nextmatch::validateExecId($exec_id))
		{
			return;
		}
		$failed = false;
		try
		{
			$msg = $this->action($action, $selected, $all_selected);
		}
		catch (\Exception $e)
		{
			$msg = $e->getMessage();
			$failed = true;
		}
		// Naming the app in the 2nd argument makes egw.refresh() update the list itself: the
		// "message only, a push will carry the change" sentinel needs something to send that
		// push, and aitools never calls Link::notify_update().  Only one id fits in the 3rd
		// argument, so the single-row update is only on when exactly one row changed; for
		// anything more it gets no id at all, which reloads the list.
		$single = !$all_selected && count($selected) === 1;
		Api\Json\Response::get()->call('egw.refresh', $msg, self::APP,
			$single ? $selected[0] : null, $single ? 'delete' : null, self::APP, null, null,
			$failed ? 'error' : 'success');
	}

	/**
	 * Execute action on list
	 *
	 * @param string $action
	 * @param array|int $selected
	 * @param boolean $select_all
	 * @returns string with success message
	 * @throws Api\Exception\AssertionFailed
	 */
	protected function action($action, $selected, $select_all)
	{
		if ($select_all)
		{
			// the filters the list last ran, not every prompt in the table: without them a search
			// that shows three rows would delete all of them plus everything it filtered out
			$query = Api\Cache::getSession(self::APP, 'index');
			if (!is_array($query))
			{
				throw new Api\Exception\AssertionFailed(
					lang('Could not determine the current selection, please try again.'));
			}
			@set_time_limit(0);
			$query['num_rows'] = -1;
			$rows = $readonlys = [];
			$this->get_rows($query, $rows, $readonlys);
			$selected = array_column($rows, 'id');
		}
		$selected = (array)$selected;

		switch ($action)
		{
			case 'delete':
				// must be keyed ['id' => $selected], NOT a bare list - Storage\Base::delete() treats
				// an integer-keyed array as raw SQL WHERE fragments, not primary-key values: a bare
				// list of ids there turns into eg. "WHERE 75" (a nonzero literal, always true),
				// deleting every row in the table instead of just the selected one(s)
				if (!$this->prompts->delete(['id' => $selected]))
				{
					throw new Api\Exception\AssertionFailed(lang('Error deleting entry!'));
				}
				Api\Framework::refresh_opener(lang('%1 prompt(s) deleted.', count($selected)),
					self::APP, $selected, 'delete');
				return lang('%1 prompt(s) deleted.', count($selected));
		}
		throw new Api\Exception\AssertionFailed(lang("Unknown action '%1'!", $action));
	}
}
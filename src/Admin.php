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
					if (!$this->prompts->delete($content['id']))
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
		$tmpl = new Api\Etemplate(self::APP.'.prompt');
		$tmpl->exec(self::APP.'.'.self::class.'.edit', $content, [], $readonlys, $content, 2);
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
				'popup' => '640x480',
				'group' => $group=0,
			],
			'add' => [
				'caption' => 'Add',
				'url' => 'menuaction='.self::APP.'.'.self::class.'.edit',
				'popup' => '640x320',
				'group' => $group,
			],
			'delete' => [
				'caption' => 'Delete',
				'confirm' => 'Delete this prompt(s)',
				'group' => $group=5,
			],
		];
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
		unset($action, $selected, $select_all);

		throw new Api\Exception\AssertionFailed('To be implemented ;)');
	}
}
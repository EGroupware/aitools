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
		'placeholders' => true,
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
			'tools' => self::toolOptions(),
		];
		$tmpl = new Api\Etemplate(self::APP.'.prompt');
		$tmpl->exec(self::APP.'.'.self::class.'.edit', $content, $sel_options, $readonlys, $content, 2);
	}

	/**
	 * The merge class of an application, if it has its own (not the addressbook fallback of get_app_class())
	 *
	 * Checked by class name first, so no merge class of another app gets constructed.
	 *
	 * @param string $app
	 * @return string|null class name
	 */
	protected static function mergeClass(string $app) : ?string
	{
		if ($app === 'addressbook')
		{
			return Api\Contacts\Merge::class;
		}
		foreach ([$app.'_merge', 'EGroupware\\'.ucfirst($app).'\\Merge'] as $class)
		{
			if (class_exists($class) && is_subclass_of($class, Api\Storage\Merge::class))
			{
				return $class;
			}
		}
		return null;
	}

	/**
	 * Applications whose record placeholders a prompt can use, with the link to their list
	 *
	 * The record placeholders are those of document merge (see Bo::mergeRecord()), the list is the merge
	 * class' show_replacements(), shown by placeholders() - many merge classes don't allow it as menuaction.
	 *
	 * @param string[]|string|null $apps applications of the prompt, none: all the user may run
	 * @return array[] with values for keys "app", "label" and "url", sorted by label
	 */
	protected static function placeholderPages($apps=null) : array
	{
		$apps = array_filter(is_array($apps) ? $apps : explode(',', (string)$apps));
		if (!$apps)
		{
			$apps = array_keys($GLOBALS['egw_info']['user']['apps'] ?? []);
		}
		$pages = [];
		foreach ($apps as $app)
		{
			if (is_string($app) && preg_match('/^[a-z0-9_-]+$/i', $app) && !empty($GLOBALS['egw_info']['user']['apps'][$app]) &&
				self::mergeClass($app))
			{
				$pages[] = [
					'app'   => $app,
					'label' => preg_replace('/\s+/', ' ', trim($GLOBALS['egw_info']['apps'][$app]['title'] ?? lang($app))),
					'url'   => Api\Egw::link('/index.php', ['menuaction' => self::APP.'.'.self::class.'.placeholders', 'placeholder_app' => $app]),
				];
			}
		}
		usort($pages, static fn($a, $b) => strcasecmp($a['label'], $b['label']));

		return $pages;
	}

	/**
	 * Placeholders popup of the prompt editor: what a prompt text can use (a window, not a dialog, so it
	 * can stay open beside the editor)
	 *
	 * With placeholder_app: the record placeholders of that application, its merge class' show_replacements().
	 * Without: the reference - the <content> tags, the placeholders Prompts::prompts() fills, {$lang} of the
	 * translation prompt, and a link per application (of the prompt: apps, or all) to its record placeholders.
	 */
	public function placeholders()
	{
		if (isset($_GET['placeholder_app']))
		{
			$app = (string)$_GET['placeholder_app'];
			if (!preg_match('/^[a-z0-9_-]+$/i', $app) || empty($GLOBALS['egw_info']['user']['apps'][$app]) ||
				!($class = self::mergeClass($app)))
			{
				Api\Framework::window_close(lang('Permission denied!'));
			}
			Api\Translation::add_app($app);
			(new $class())->show_replacements();
			return;
		}
		$h = static fn($text) => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		$section = static function(string $title, array $rows) use ($h) : string
		{
			$html = '<h3>'.$h(lang($title))."</h3>\n<table class=\"egwGridView_grid\" style=\"width: 100%\">\n";
			foreach ($rows as [$code, $text])
			{
				$html .= '<tr><td style="vertical-align: top; white-space: nowrap; padding-right: 1em; font-family: monospace">'.
					$h($code).'</td><td>'.(is_array($text) ? $text[0] : $h(lang($text)))."</td></tr>\n";
			}
			return $html."</table>\n";
		};
		$links = '';
		foreach (self::placeholderPages($_GET['apps'] ?? null) as $page)
		{
			$links .= '<li><a href="'.$h($page['url']).'" target="aitools_placeholders_'.$h($page['app']).'">'.$h($page['label'])."</a></li>\n";
		}
		$GLOBALS['egw_info']['flags']['app_header'] = lang('Placeholders');
		echo $GLOBALS['egw']->framework->header();
		echo '<div style="padding: 0 1em 1em; overflow: auto; height: 100%; box-sizing: border-box">'."\n";
		echo $section('The text to work on', [
			['<content>…</content>', 'The text of the field, or its selected part, is added after the prompt inside these tags - refer to it as "the text inside the <content> tags".'],
		]);
		echo $section('Replaced in every prompt', [
			['{{username}}', 'Login name of the user'],
			['{{userfullname}}', 'Full name of the user'],
			['{{useremail}}', 'Email address of the user'],
			['{{userdate}} {{usertime}}', 'Current date and time of the user'],
			['{{usertimezone}}', 'Timezone of the user'],
			['{{systemtime}}', 'Current time in UTC, ISO 8601'],
			['{{lang}} {{language}}', 'Language of the user: code and name'],
		]);
		echo $section('Translation prompt only', [
			['{$lang}', 'The target language, in the prompt "Translate" or "Custom translation prompt"'],
		]);
		echo $section('Fields of the entry the text belongs to', [
			['{{id}} {{subject}} …', [$h(lang('The placeholders of the application, as for documents, written {{name}} or $$name$$. Not replaced for a new entry, or without the right to read it.')).
				'<ul>'.($links ?: '<li>'.$h(lang('No application with placeholders')).'</li>').'</ul>']],
		]);
		echo $section('Added automatically', [
			['System prompt', 'Rules for every request, and the user context (name, language, timezone) - see the system prompts in the list'],
			['Tools', 'The tools chosen in the tab "Tools" are offered to the AI'],
		]);
		echo "</div>\n";
		echo $GLOBALS['egw']->framework->footer();
	}

	/**
	 * Tools as select-options, labelled "<Application>: <summary>" and sorted by application
	 *
	 * Like Api\CalDAV\OpenAPI::operationIds(), which does not tell the application: without it
	 * "Create a document" or "Delete an entry" could be from any of them. The application is the
	 * first segment of the path, or the second after "{username}"; "{app}" paths are the links API.
	 *
	 * @return array operationId => array with values for keys value, label and title
	 */
	public static function toolOptions() : array
	{
		$options = [];
		foreach (Api\CalDAV\OpenAPI::scan()['paths'] as $path => $methods)
		{
			$segments = explode('/', trim($path, '/'));
			$app = $segments[0] === '{username}' ? ($segments[1] ?? '') : $segments[0];
			// the title the navigation shows, as Api\Framework does; some carry padding spaces
			$app_label = $app === '{app}' ? lang('Links') :
				preg_replace('/\s+/', ' ', trim($GLOBALS['egw_info']['apps'][$app]['title'] ?? lang($app)));

			foreach ($methods as $data)
			{
				// skip path-level fields like "parameters", which are no operations
				if (!is_array($data) || empty($data['operationId'])) continue;

				$options[$data['operationId']] = [
					'value' => $data['operationId'],
					'label' => $app_label.': '.lang($data['summary'] ?? $data['operationId']),
					'title' => $data['description'] ?? '',
				];
			}
		}
		// stable sort by application only, operations keep the order of their description
		uasort($options, static fn($a, $b) => strcasecmp(strstr($a['label'], ': ', true), strstr($b['label'], ': ', true)));

		return $options;
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
		// prompt_apps is empty for "all applications", otherwise a comma-separated list: a plain
		// comparison only found prompts for exactly the one app, and nothing for most apps
		if (!empty($apps = $query['col_filter']['apps'] ?? null))
		{
			$db = $GLOBALS['egw']->db;
			$query['col_filter'][] = "(prompt_apps IS NULL OR prompt_apps='' OR ".implode(' OR ', array_map(
				static fn($app) => 'FIND_IN_SET('.$db->quote($app).', prompt_apps)', (array)$apps)).')';
		}
		unset($query['col_filter']['apps']);
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
				'popup' => '640x710',
				'group' => $group=0,
			],
			'add' => [
				'caption' => 'Add',
				'url' => 'menuaction='.self::APP.'.'.self::class.'.edit',
				'popup' => '640x710',
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
		if ($select_all)
		{
			$selected = array_column($this->prompts->search(null, false, '', '', '', false, 'AND', false), 'id');
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
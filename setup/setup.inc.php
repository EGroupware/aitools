<?php
/**
 * EGroupware AI Tools
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Amir Mo Dehestani <amir@egroupware.org>
 * @author Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

$setup_info['aitools']['name']      = 'aitools';
$setup_info['aitools']['version']   = '26.1.001';
$setup_info['aitools']['app_order'] = 5;
$setup_info['aitools']['tables']    = [];
$setup_info['aitools']['enable']    = 5;        // hidden from navbar, but framework app without index
$setup_info['aitools']['autoinstall'] = true;   // install automatic on update

$setup_info['aitools']['author'] = array(
	'name'  => 'Amir Mo Dehestani',
	'email' => 'amir@egroupware.org',
);
$setup_info['aitools']['maintainer'] = array(
	'name'  => 'Ralf Becker',
	'email' => 'rb@egroupware.org',
);
$setup_info['aitools']['description'] = 'AI tools for EGroupware';

/* The hooks this app includes, needed for hooks registration */
$setup_info['aitools']['hooks'] = array();
$setup_info['aitools']['hooks']['admin'] = 'EGroupware\\AiTools\\Hooks::allHooks';
$setup_info['aitools']['hooks']['sidebox_menu'] = 'EGroupware\\AiTools\\Hooks::allHooks';
$setup_info['aitools']['hooks']['config'] = 'EGroupware\AiTools\\Hooks::config';
$setup_info['aitools']['hooks']['config_validate'] = 'EGroupware\AiTools\Hooks::configValidate';
$setup_info['aitools']['hooks']['settings'] = 'EGroupware\AiTools\Hooks::preferences';

/* Dependencies for this app to work */
$setup_info['aitools']['depends'][] = array(
	 'appname' => 'api',
	 'versions' => Array('26.1')
);
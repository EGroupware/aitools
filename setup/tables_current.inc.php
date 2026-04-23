<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package aitools
 * @subpackage setup
 */


$phpgw_baseline = array(
	'egw_ai_prompts' => array(
		'fd' => array(
			'prompt_id' => array('type' => 'auto','nullable' => False),
			'prompt_name' => array('type' => 'ascii','precision' => '64','nullable' => False,'comment' => 'internally used identifier'),
			'prompt_label' => array('type' => 'varchar','precision' => '64','comment' => 'label shown to the user'),
			'prompt_text' => array('type' => 'text','nullable' => False,'comment' => 'prompt itself'),
			'prompt_apps' => array('type' => 'ascii','precision' => '1024','comment' => 'null, or comma-separated list of apps for which the prompt is shown'),
			'account_id' => array('type' => 'ascii','meta' => 'account-commasep','precision' => '256','comment' => 'null, or comma-separated list of account_id the prompt is shown/allowed'),
			'prompt_disabled' => array('type' => 'bool','comment' => 'allows to disable a prompt without deleting it'),
			'prompt_remark' => array('type' => 'varchar','precision' => '2048','comment' => 'notes/remarks about the prompt'),
			'prompt_modified' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp','comment' => 'when the prompt was last updated'),
			'prompt_modifier' => array('type' => 'int','meta' => 'user','precision' => '4','nullable' => False,'comment' => '0: system, or account_id of updating user'),
			'prompt_order' => array('type' => 'int', 'precision' => '1', 'comment' => 'order of the prompt'),
		),
		'pk' => array('prompt_id'),
		'fk' => array(),
		'ix' => array('prompt_order'),
		'uc' => array('prompt_name')
	)
);
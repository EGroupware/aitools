/**
 * EGroupware AI Tools
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Amir Mo Dehestani <amir@egroupware.org>
 * @author Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

import {EgwApp} from '../../api/js/jsapi/egw_app';
import {app} from "../../api/js/jsapi/egw_global";
import type {Et2Select} from "../../api/js/etemplate/Et2Select/Et2Select";
import type {Et2Template} from "../../api/js/etemplate/Et2Template/Et2Template";

/**
 * UI for EGroupware AI Assistant application
 */
export class AIToolsApp extends EgwApp
{
	/**
	 * AI model changed
	 *
	 * config runs as admin, not aitools, therefore this.et2 is never set.
	 *
	 * @param _ev
	 * @param _widget
	 */
	configModelChanged(_ev? : Event, _widget : Et2Select|Et2Template)
	{
		if (!this.et2) this.et2 = _widget.getRoot();
		const model = _ev.type === 'load' ? this.et2.getInputWidgetById('newsettings[ai_model]') : _widget;
		const custom_model = this.et2.getWidgetById('newsettings[ai_custom_model]');
		custom_model.hidden = model.value !== 'custom';
		custom_model.required = model.value && model.value !== 'custom' && model.value !== 'ollama';
		const custom_url = this.et2.getWidgetById('newsettings[ai_api_url]');
		custom_url.required = model.value === 'custom';
	}
}

// Register the app with EGroupware
app.classes.aitools = AIToolsApp;
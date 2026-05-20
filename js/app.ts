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
import type {etemplate2} from "../../api/js/etemplate/etemplate2";
import type {Et2SelectApp} from "../../api/js/etemplate/Et2Select/Select/Et2SelectApp";

/**
 * UI for EGroupware AI Assistant application
 */
export class AIToolsApp extends EgwApp
{
	et2_ready(et2: etemplate2, name: string)
	{
		super.et2_ready(et2, name);

		switch (name)
		{
			case 'aitools.prompts':
				app.admin?.enableAppToolbar(et2, name);
				break;
			case 'aitools.prompt':
				this.appChanged();
				break;
		}
	}

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
		custom_model.hidden = model?.value !== 'custom';
		custom_model.required = model?.value && model.value === 'custom';
		const custom_url = this.et2.getWidgetById('newsettings[ai_api_url]');
		custom_url.required = model?.value === 'custom';
	}

	/**
	 * Change handler for app-selection in prompt editing: enable/disable possible triggers
	 *
	 * @param _ev
	 * @param _widget
	 */
	appChanged(_ev? : Event, _widget? : Et2SelectApp)
	{
		const apps : Et2SelectApp = _widget || <Et2SelectApp><any>this.et2.getInputWidgetById('apps');
		const trigger : Et2Select = <Et2Select><any>this.et2.getInputWidgetById('trigger');

		let supported = undefined;
		apps.value.forEach((app: string) =>
		{
			if (!this.egw.link_get_registry(app))
			{
				if (_widget) this.egw.message(this.egw.lang('Application %1 does NOT support triggers!', this.egw.lang(app)));
				if (!supported) supported = false;
			}
		});
		// disable trigger selection only if all selected apps are not supporting links
		if (supported === false)
		{
			trigger.value = '';
			trigger.disabled = true;
		}
		else
		{
			trigger.disabled = false;
		}
	}
}

// Register the app with EGroupware
app.classes.aitools = AIToolsApp;
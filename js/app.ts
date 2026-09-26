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
import type {Et2Select} from "../../api/js/etemplate/Et2Select/Et2Select";
import type {Et2Template} from "../../api/js/etemplate/Et2Template/Et2Template";
import type {etemplate2} from "../../api/js/etemplate/etemplate2";
import type {Et2SelectApp} from "../../api/js/etemplate/Et2Select/Select/Et2SelectApp";
import type {Et2Button} from "../../api/js/etemplate/Et2Button/Et2Button";
import {Et2Dialog} from "../../api/js/etemplate/Et2Dialog/Et2Dialog";

/**
 * Result of Bo::ajaxTestConnection()
 */
type ConnectionTest = {
	ok : boolean,
	steps : {title : string, ok : boolean|null, summary : string, details : string}[]
};
// egw/app are ambient globals (declare global {} in egw_global.d.ts, unconditionally included
// via tsconfig's "**/*.d.ts") - no import needed or possible.

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
				// app.admin is typed generically as EgwApp; enableAppToolbar() is AdminApp-specific
				(<any>app.admin)?.enableAppToolbar(et2, name);
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
	configModelChanged(_ev? : Event, _widget? : Et2Select|Et2Template)
	{
		if (!this.et2) this.et2 = <Et2Template><unknown>_widget.getRoot();
		const model = <Et2Select><unknown>(_ev.type === 'load' ? this.et2.getInputWidgetById('newsettings[ai_model]') : _widget);
		const custom_model = this.et2.getWidgetById('newsettings[ai_custom_model]');
		custom_model.hidden = model?.value !== 'custom';
		custom_model.required = model?.value && model.value === 'custom';
		const custom_url = this.et2.getWidgetById('newsettings[ai_api_url]');
		custom_url.required = model?.value === 'custom';
	}

	/**
	 * Test connection button of the config: test the current form values and show the result in a popup
	 *
	 * The values need not be saved; an empty API key uses the stored one server-side.
	 *
	 * @param _ev
	 * @param _widget
	 */
	testConnection(_ev? : Event, _widget? : Et2Button)
	{
		if (!this.et2) this.et2 = <Et2Template><unknown>_widget.getRoot();
		const settings = {};
		['ai_model', 'ai_custom_model', 'ai_api_url', 'ai_api_key', 'reasoning', 'max_tokens', 'timeout', 'temperature'].forEach(name =>
		{
			// getValue(), not value: et2-number's value is the localized display text ("10.000", "0,1")
			settings[name] = (<any>this.et2.getInputWidgetById('newsettings['+name+']'))?.getValue() ?? '';
		});

		const content = document.createElement('div');
		content.style.minWidth = 'min(45em, 90vw)';
		content.textContent = this.egw.lang('Testing connection, this can take up to the configured timeout ...');

		const dialog = new Et2Dialog(this.egw);
		dialog.transformAttributes({
			title: this.egw.lang('Test connection'),
			buttons: Et2Dialog.BUTTONS_OK,
			isModal: true,
		});
		dialog.appendChild(content);
		document.body.appendChild(<any>dialog);

		_widget.disabled = true;
		this.egw.request('EGroupware\\AiTools\\Bo::ajaxTestConnection', [settings]).then((result : ConnectionTest) =>
		{
			content.replaceChildren(...this.connectionTestNodes(result));
		}).catch((error) =>
		{
			content.textContent = error?.message ?? String(error);
		}).finally(() =>
		{
			_widget.disabled = false;
		});
		return false;
	}

	/**
	 * Popup content for a connection test result: one line per step, details folded, a copy button
	 *
	 * Everything goes in as text, the details contain raw server responses.
	 *
	 * @param result
	 */
	protected connectionTestNodes(result : ConnectionTest) : HTMLElement[]
	{
		const nodes : HTMLElement[] = [];
		const overall = document.createElement('p');
		overall.style.fontWeight = 'bold';
		overall.style.color = result.ok ? 'var(--sl-color-success-600)' : 'var(--sl-color-danger-600)';
		overall.textContent = this.egw.lang(result.ok ? 'Connection OK' : 'Connection failed');
		nodes.push(overall);

		result.steps.forEach(step =>
		{
			const details = document.createElement('details');
			details.open = step.ok === false;
			details.style.marginBottom = '.5em';
			const summary = document.createElement('summary');
			summary.style.cursor = 'pointer';
			const mark = step.ok === null ? 'ℹ' : (step.ok ? '✔' : '✘');
			const title = document.createElement('b');
			title.textContent = mark+' '+step.title;
			title.style.color = step.ok === null ? '' : (step.ok ? 'var(--sl-color-success-600)' : 'var(--sl-color-danger-600)');
			summary.append(title, ': '+step.summary);
			const pre = document.createElement('pre');
			pre.textContent = step.details;
			pre.style.cssText = 'max-height: 20em; overflow: auto; white-space: pre-wrap; word-break: break-all; font-size: 90%; '+
				'background: var(--sl-color-neutral-100); padding: .5em; margin: .25em 0 0 0; user-select: text;';
			details.append(summary, pre);
			nodes.push(details);
		});

		const copy = document.createElement('et2-button');
		copy.setAttribute('label', this.egw.lang('Copy debug information'));
		copy.setAttribute('image', 'clipboard');
		copy.setAttribute('noSubmit', 'true');
		copy.addEventListener('click', (ev) =>
		{
			this.egw.copyTextToClipboard(result.steps.map(step =>
				'== '+step.title+(step.ok === null ? '' : (step.ok ? ' OK' : ' FAILED'))+': '+step.summary+'\n'+step.details
			).join('\n\n'), <HTMLElement>ev.target, ev);
		});
		nodes.push(copy);

		return nodes;
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
		apps.getValue().forEach((app: string) =>
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
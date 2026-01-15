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
import {app, egw} from "../../api/js/jsapi/egw_global";
import type {Et2Select} from "../../api/js/etemplate/Et2Select/Et2Select";
import type {Et2Template} from "../../api/js/etemplate/Et2Template/Et2Template";
import {Et2Textarea} from "../../api/js/etemplate/Et2Textarea/Et2Textarea";
import {et2_htmlarea} from "../../api/js/etemplate/et2_widget_htmlarea";

/**
 * UI for EGroupware AI Assistant application
 */
class AIToolsApp extends EgwApp
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
		custom_model.required = model.value === 'custom';
		const custom_url = this.et2.getWidgetById('newsettings[ai_api_url]');
		custom_url.required = model.value === 'custom';
	}
	getTextareaPromptList(widget? : Et2Textarea | et2_htmlarea)
	{
		if(widget instanceof Et2Textarea)
		{
			// Plain text box - return simplified menu options
			return this._getTextAreaPrompts(widget);
		}
		else if(widget.getType && widget.getType() == "htmlarea")
		{
			// Widget is RTEditor, give it what it wants for toolbar
			return this._getHtmlAreaPrompts(widget);
		}

		return [];
	}

	/**
	 * Get prompts for plain textarea elements
	 */
	_getTextAreaPrompts(widget : Et2Textarea)
	{
		// For plain text areas, we return a simpler set of options
		// These could be used in a context menu or button dropdown
		return [
			{
				id: 'aiassist.summarize',
				label: 'Summarize text',
				action: () => this.handleTextboxPrompt('aiassist.summarize', widget)
			},
			{
				id: 'aiassist.formal',
				label: 'Make more formal',
				action: () => this.handleTextboxPrompt('aiassist.formal', widget)
			},
			{
				id: 'aiassist.grammar',
				label: 'Fix grammar & spelling',
				action: () => this.handleTextboxPrompt('aiassist.grammar', widget)
			},
			{
				id: 'aiassist.concise',
				label: 'Make concise',
				action: () => this.handleTextboxPrompt('aiassist.concise', widget)
			}
		];
	}

	/**
	 * Get the list of pre-configured prompts we allow on textarea / htmlarea elements formatted to be displayed in the htmlarea toolbar
	 *
	 * @see https://www.tiny.cloud/docs/tinymce/latest/custom-toolbarbuttons/
	 *
	 * @param {et2_htmlarea} widget
	 * @return {({type : string, text : string, onAction : (action) => void} | {type : string, text : string, onAction : (action) => void} | {type : string, text : string, getSubmenuItems : () => [{type : string, text : string, onAction : () => void}]})[]}
	 */
	_getHtmlAreaPrompts(widget : et2_htmlarea)
	{
		// The toolbar needs the text to display, and the action to perform when clicked
		return [
			{
				type: 'menuitem',
				text: 'Summarize text',
				onAction: (action) => this.handleTextboxPrompt('aiassist.summarize', widget)
			},
			{
				type: 'menuitem',
				text: 'Make more formal',
				onAction: (action) => this.handleTextboxPrompt('aiassist.formal', widget)
			},
			{
				type: 'menuitem',
				text: 'Make more casual',
				onAction: (action) => this.handleTextboxPrompt('aiassist.casual', widget)
			},
			{
				type: 'menuitem',
				text: 'Fix grammar & spelling',
				onAction: (action) => this.handleTextboxPrompt('aiassist.grammar', widget)
			},
			{
				type: 'menuitem',
				text: 'Make concise',
				onAction: (action) => this.handleTextboxPrompt('aiassist.concise', widget)
			},
			{
				type: 'nestedmenuitem',
				text: 'Translate',
				getSubmenuItems: () =>
				{
					// This should come from getInstalledLanguages or \Translation::list_langs()
					return [
						{
							type: 'menuitem',
							text: 'English',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-en', widget)}
						},
						{
							type: 'menuitem',
							text: 'German',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-de', widget)}
						},
						{
							type: 'menuitem',
							text: 'French',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-fr', widget)}
						},
						{
							type: 'menuitem',
							text: 'Spanish',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-es', widget)}
						},
						{
							type: 'menuitem',
							text: 'Italian',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-it', widget)}
						},
						{
							type: 'menuitem',
							text: 'Portuguese',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-pt', widget)}
						},
						{
							type: 'menuitem',
							text: 'Dutch',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-nl', widget)}
						},
						{
							type: 'menuitem',
							text: 'Russian',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ru', widget)}
						},
						{
							type: 'menuitem',
							text: 'Chinese',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-zh', widget)}
						},
						{
							type: 'menuitem',
							text: 'Japanese',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ja', widget)}
						},
						{
							type: 'menuitem',
							text: 'Korean',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ko', widget)}
						},
						{
							type: 'menuitem',
							text: 'Arabic',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-ar', widget)}
						},
						{
							type: 'menuitem',
							text: 'Persian',
							onAction: () => {this.handleTextboxPrompt('aiassist.translate-fa', widget)}
						}
					];
				}
			},
			{
				type: 'nestedmenuitem',
				text: 'Generate',
				getSubmenuItems: () =>
				{
					return [
						{
							type: 'menuitem',
							text: 'Professional reply',
							onAction: () => {this.handleTextboxPrompt('aiassist.generate_reply', widget)}
						},
						{
							type: 'menuitem',
							text: 'Meeting follow-up',
							onAction: () => {this.handleTextboxPrompt('aiassist.meeting_followup', widget)}
						},
						{
							type: 'menuitem',
							text: 'Thank you note',
							onAction: () => {this.handleTextboxPrompt('aiassist.thank_you', widget)}
						}
					];
				}
			}
		];
	}

	/**
	 * A widget has requested a predefined prompt be done to it
	 *
	 * @param promptID
	 * @param widget
	 */
	handleTextboxPrompt(promptID : string, widget : any)
	{
		console.log(`Predefined prompt called: ${promptID} with input ` + widget.get_value(), widget);

		const originalValue = widget.get_value();

		// Don't process if there's no content
		if (!originalValue || originalValue.trim() === '') {
			egw.message('Please enter some text first', 'info');
			return;
		}

		// Show loading state
		const loadingMessage = this.getLoadingMessage(promptID);
		widget.set_value(loadingMessage);

		// Make the AI call
		egw.request('EGroupware\\AiTools\\Bo::ajax_api', [
			'process_prompt',
			promptID,
			originalValue
		]).then((response) => {
			this.handlePromptResponse(response, widget, originalValue);
		});
	}
	/**
	 * Get appropriate loading message based on prompt type
	 */
	getLoadingMessage(promptID: string): string
	{
		const loadingMessages = {
			'aiassist.summarize': 'Summarizing your text...',
			'aiassist.formal': 'Making text more formal...',
			'aiassist.casual': 'Making text more casual...',
			'aiassist.grammar': 'Checking grammar and spelling...',
			'aiassist.concise': 'Making text more concise...',
			'aiassist.generate_reply': 'Generating professional reply...',
			'aiassist.meeting_followup': 'Creating meeting follow-up...',
			'aiassist.thank_you': 'Composing thank you note...'
		};

		// Handle translation prompts
		if (promptID.startsWith('aiassist.translate-')) {
			const langCode = promptID.split('-')[1];
			const langNames = {
				'en': 'English',
				'de': 'German',
				'fr': 'French',
				'es': 'Spanish',
				'it': 'Italian',
				'pt': 'Portuguese',
				'nl': 'Dutch',
				'ru': 'Russian',
				'zh': 'Chinese',
				'ja': 'Japanese',
				'ko': 'Korean',
				'ar': 'Arabic',
				'fa': 'Persian'
			};
			const langName = langNames[langCode] || langCode.toUpperCase();
			return `🌐 Translating to ${langName}...`;
		}

		return loadingMessages[promptID] || '🤖 AI is processing...';
	}

	/**
	 * Handle the response from AI prompt processing
	 */
	handlePromptResponse(response: any, widget: any, originalValue: string)
	{
		if (response.error) {
			// Restore original content on error
			widget.set_value(originalValue);
			egw.message('AI processing failed: ' + response.error, 'error');
			return;
		}

		if (response.success && response.result) {
			// Set the AI-processed content
			widget.set_value(response.result);

			// Show success message
			egw.message('Text processed successfully', 'success');
		} else {
			// Restore original content if no result
			widget.set_value(originalValue);
			egw.message('No result received from AI', 'warning');
		}
	}
}

// Register the app with EGroupware
app.classes.aitools = AIToolsApp;
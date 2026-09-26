<?php
/**
 * EGroupware AI Tools
 *
 * @package aitools
 * @link https://www.egroupware.org
 * @author Amir Mo Dehestani <amir@egroupware.org>
 * @author Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

use DeepL\DeepLException;
use DeepL\Language;
use DeepL\TranslateTextOptions;
use DeepL\Translator;
use EGroupware\Api;

/**
 * Business logic for AI Tools
 * 
 * SCOPE: AI Tools operates ONLY on text content provided to it.
 
 */
class Bo
{
	const APP = 'aitools';
	
	/**
	 * Maximum content length in bytes to prevent abuse (500KB)
	 */
	const MAX_CONTENT_LENGTH = 512000;

	/**
	 * Prefix for all translation prompts (lang-code to be added to get the full prompt-id)
	 */
	const TRANSLATION_PROMPT_PREFIX = 'aiassist.translate-';

	/**
	 * Name of the local (non-REST) getCurrentDateTime tool, see call_ai_api()/execute_tools()
	 */
	const CURRENT_DATE_TIME_TOOL = 'getCurrentDateTime';

	/**
	 * Process predefined prompts for text widgets
	 * 
	 * @param string|array $prompt predefined prompt or it's ID
	 * @param string $content The text content to process
	 * @param array $options options including is_html
	 * @return array The processed content and additional details
	 */
	public function process_predefined_prompt($prompt, $content, $options = [])
	{
		if(!is_array($options))
		{
			$options = ['is_html' => (bool)$options];
		}
		$is_html = $options['is_html'] ?? $options['is_markup'] ?? false;

		// Security: Validate content length to prevent abuse
		if (strlen($content) > self::MAX_CONTENT_LENGTH)
		{
			throw new \Exception('Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / 1024) . ' KB.');
		}
		$prompt_id = $prompt['name'] ?? $prompt;
		
		// Check if this is a translation task for optimizations
		$is_translation = str_starts_with($prompt_id, self::TRANSLATION_PROMPT_PREFIX);
		if($is_translation)
		{
			[, $target_lang] = explode('-', $prompt_id, 2);
			$source_lang = $options['source_lang'] ?? null;
			return $this->translate($content, $target_lang, $source_lang, null, $is_html);
		}

		if (!is_array($prompt))
		{
			// get predefined prompts
			$prompts = $this->get_predefined_prompts();

			// Security: Sanitize prompt_id to prevent XSS in error messages
			if (empty($prompts[$prompt_id]['text']))
			{
				throw new \Exception('Unknown prompt ID: ' . htmlspecialchars($prompt_id, ENT_QUOTES, 'UTF-8'));
			}
			$prompt = $prompts[$prompt_id];
		}
		// For other tasks: use full system prompt with all protections
		$messages = [
			[
				'role'    => 'system',
				'content' => Prompts::systemPrompt(false, !empty($prompt['tools'])),
			],
			[
				'role'    => 'user',
				// per-user/per-request context (name, language, timezone, ...) is deliberately part
				// of the "user" message, not the cached "system" one - see Prompts::userContext()
				'content' => (($context = Prompts::userContext()) ? $context."\n\n" : '') .
					$prompt['text'] . "\n\n" . self::wrapContent($content)
			]
		];

		// Call AI API with task-specific optimizations
		$ai_response = $this->call_ai_api($prompt, $messages, $is_translation);

		// Return just the processed content, not the full response structure
		$response = self::removeContentTags($ai_response['content']) ?? $content;

		// either purify html or strip_tags plain-text response
		// to guard gainst XSS via prompt injection and the like
		if (preg_match_all('/<[^>]+>/', $response, $matches) && count($matches[0]) > 3)
		{
			$response = Api\Html\HtmLawed::purify($response);
		}
		else
		{
			$response = strip_tags($response);
		}
		// in case the response is in md --> html
		if (preg_match('/\*\*.*\*\*/', $response))
		{
			$response = str_replace("\n", "<br/>\n",
				preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $response));
		}
		return [
			'content' => $response,
			'usage'   => $ai_response['usage'] ?? null,
		];
	}

	/**
	 * Wrap content in <content> tags
	 *
	 * Always wrap user content in XML tags for anti-injection protection.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function wrapContent(string $content) : string
	{
		return "<content>" . self::removeContentTags($content) . "</content>";
	}

	/**
	 * Wrap content in <content> tags
	 *
	 * Always wrap user content in XML tags for anti-injection protection.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function removeContentTags(string $content) : string
	{
		return trim(preg_replace('#</?content.*?>#i', '', $content));
	}

	/**
	 * Check and cache, if AI texttools are available / configured and enabled for the user
	 *
	 * @return int 0: NOT enabled, 1: fully enabled, 2: only translations / DeepL supported options
	 */
	public static function enabled() : int
	{
		// user has no run-rights for the provider
		if (empty($GLOBALS['egw_info']['user']['apps'][self::APP]))
		{
			return 0;
		}
		return Api\Cache::getInstance(self::APP, 'configured-'.md5(json_encode(Api\Config::read(self::APP))),
			static function ()
		{
			try {
				return (int)self::test_api_connection();
			}
			catch (\Exception $e) {
				try {
					return self::deeplTargetLanguages(Api\Config::read(self::APP)) ? 2 : 0;
				}
				catch (\Exception $e) {}
			}
			return 0;
		}, [], 7200);
	}

	/**
	 * The prompts actually available to the current user right now
	 *
	 * Deciding whether AiTools is enabled/configured at all lives HERE, not in the widget - a
	 * widget showing its trigger button is then purely a function of "are there any prompts to
	 * show" (see Et2Ai.ts's transformAttributes()), which can't go wrong regardless of which
	 * template/app a given et2-ai instance is mounted from (ticket #124681 follow-up, 2026-09-17:
	 * a per-widget-instance server-side "disable" signal never reached a widget mounted from a
	 * referenced sub-template, eg. mail's preview pane, since that never runs any server-side
	 * widget lifecycle code for its own content at all).
	 *
	 * @return array name => value pairs, see get_predefined_prompts()'s own $return_prompt=false shape
	 */
	public function availablePrompts() : array
	{
		if (!($enabled = self::enabled()))
		{
			return [];
		}
		return $this->get_predefined_prompts(false, $enabled == 2);
	}

	/**
	 * Get predefined prompt templates
	 *
	 * The system prompt handles global rules (markup preservation, etc.)
	 *
	 * @param bool $return_prompt true: return just the prompt, false: return array with values for value, label, children and apps
	 * @return array name => value pairs, see $return_prompt
	 */
	public function get_predefined_prompts(bool $return_prompt=true, bool $only_translation=false) : array
	{
		// Stock prompt labels (eg. "Summarize text") only have lang() entries under THIS app - but
		// this method can run with any other app as the current one (the et2-ai widget is designed
		// to be embedded in any app's template, eg. mail's preview pane - see availablePrompts()'s
		// own docblock on ticket #124681), and Api\Translation::init() only ever auto-loads
		// 'common'/'etemplate'/the CURRENT app/'custom', never an unrelated app just because a
		// widget from it happens to be rendered. Without this, every stock label came back
		// untranslated for any host app other than aitools itself - "Übersetzen" only ever worked by
		// accident, because that exact word also has an unrelated 'common' entry from elsewhere.
		Api\Translation::add_app(self::APP);

		// get_ai_config() throws when the main provider isn't configured (eg. DeepL-only or nothing
		// configured at all) - computed once, tolerantly, so an unconfigured main provider does NOT
		// silently wipe the ENTIRE prompts list (every prompt without its own 'timeout' override
		// used to trigger this inside array_map() below, uncaught - api/user.php's own bootstrap
		// call swallows \Throwable, so the browser's global egw.prompts() ended up completely empty
		// regardless of enabled()/DeepL state, whenever just the main provider was unconfigured)
		$default_timeout = null;
		try { $default_timeout = self::get_ai_config()['timeout'] ?? null; } catch (\Throwable $e) {}

		// return either just the prompt-text or id, label and apps
		$map = static fn($prompts) => array_map(static fn($prompt) => $return_prompt ? $prompt : [
			'id' => $prompt['name'],
			'label' => lang($prompt['label']),
			'apps' => !empty($prompt['apps']) ? explode(',', $prompt['apps']) : null,
			'timeout' => $prompt['timeout'] ?? $default_timeout ?? ($only_translation ? 90 : 60),
		]+(isset($prompt['children']) ? ['children' => $prompt['children']] : []), $prompts);

		if ($only_translation)
		{
			$prompts = $this->get_translation_prompts();
		}
		else
		{
			$prompts = Prompts::prompts();
			if (($translation = $prompts['aiassist.translate.custom'] ?? $prompts['aiassist.translate'] ?? null))
			{
				$prompts['aiassist.translate'] = [
					'name' => 'aiassist.translate',
					'children' => $this->get_predefined_prompts($return_prompt, true),
				]+$translation;
				unset($prompts['aiassist.translate.custom']);
			}
			// filter aiassist.generate.* prompts into a Generate sub-menu
			if (!$return_prompt)
			{
				$generate = array_filter($prompts, static fn($prompt) => str_starts_with($prompt['name'], 'aiassist.generate.'));
				$prompts = array_filter($prompts, static fn($prompt) => !str_starts_with($prompt['name'], 'aiassist.generate.'))+ [
					'aiassist.generate' => [
						'name' => 'aiassist.generate',
						'label' => 'Generate',
						'children' => $map($generate),
					]
				];
			}
		}
		return $map($prompts);
	}

	/**
	 * Get translation prompts for major languages only
	 *
	 * @param ?array &$prompt on return full translation prompt incl. timeout and other overrides
	 * @return array name => value pairs, see $return_prompt
	 */
	protected function get_translation_prompts(?array &$prompt=null) : array
	{
		$prompts = [];
		// Optimized prompt for faster translation - direct and concise
		if (($template = Prompts::translationPromptTemplate($prompt)))
		{
			// Get user's preferred translation languages from preferences, always include user's language
			$pref_langs = $GLOBALS['egw_info']['user']['preferences']['aitools']['languages'] ?? '';
			$lang_codes = array_filter(
				array_merge([$GLOBALS['egw_info']['user']['preferences']['common']['lang'] ?? "en"], explode(',', $pref_langs))
			);

			// If no preferences set, use a small default set
			if (empty($lang_codes))
			{
				// Start with user's current language
				$lang_codes = [$GLOBALS['egw_info']['user']['preferences']['common']['lang'] ?? 'en'];
				// Add major languages
				$lang_codes = array_merge($lang_codes, ['en', 'de', 'fr', 'it']);
				$lang_codes = array_unique($lang_codes);
			}

			// configured timeout, unless the translation prompt has its own - tolerantly, like in
			// get_predefined_prompts(), get_ai_config() throws for a DeepL-only installation
			$timeout = $prompt['timeout'] ?? null;
			if (!isset($timeout))
			{
				try { $timeout = self::get_ai_config()['timeout']; } catch (\Throwable $e) {}
			}

			$all_langs = Api\Translation::get_installed_langs();
			foreach ($lang_codes as $code)
			{
				if (isset($all_langs[$code]))
				{
					$prompts[self::TRANSLATION_PROMPT_PREFIX . $code] = [
						'name' => self::TRANSLATION_PROMPT_PREFIX . $code,
						'label' => $all_langs[$code],
						'timeout' => $timeout ?? 90,
						'text' => str_replace('{$lang}', $all_langs[$code], $template),
					];
				}
			}
		}
		return $prompts;
	}

	/**
	 * Get AI configuration
	 *
	 * @return array with values for keys "api_url", "api_key", "model", "provider", "max_token"
	 */
	public static function get_ai_config()
	{
		$config = Api\Config::read(self::APP);
		// split off provider prefix
		[$provider, $model] = explode(':', $config['ai_model'], 2)+[null, null];

		return [
			'api_url' => $config['ai_api_url'] ?? Hooks::getProviderUrlMapping()[$provider] ??
				throw new Api\Exception(lang('Missing AI configuration: API URL or Model!')),
			'api_key' => trim($config['ai_api_key'] ?? ''),
			'model'   => $model ?? $config['ai_custom_model'] ??
				throw new Api\Exception(lang('Missing AI configuration: API URL or Model!')),
			'provider' => $provider,
			'max_tokens' => $config['max_tokens'] ?? null,
			'temperature' => $config['temperature'] ?? null,
			'reasoning' => $config['reasoning'] ?? null,
			// seconds, null: chatCompletions()' default (60, 90 for translations)
			'timeout' => ($config['timeout'] ?? '') !== '' ? (int)$config['timeout'] : null,
		];
	}
	


	/**
	 * Test API connection
	 *
	 * @param ?array $config values for keys "api_url", "model" and optional "api_key"
	 * @throws \Exception with error message
	 * @return bool true on success
	 */
	public static function test_api_connection(?array $config=null) : bool
	{
		$config ??= self::get_ai_config();

		if (empty($config['api_url']) || empty($config['model']))
		{
			throw new Api\Exception(lang('Missing AI configuration: API URL or Model!'));
		}
		if (!in_array($config['model'], self::models(false, $config)))
		{
			throw new \Exception(lang("Invalid model %1, not supported by endpoint!", $config['model']));
		}

		return true;
	}

	/**
	 * Test connection button of the config page: run the connection test on the (maybe unsaved) form values
	 *
	 * Admins only - it sends requests to whatever URL the form holds.
	 *
	 * @param array $settings values of the config form, keys without "newsettings[]"
	 */
	public static function ajaxTestConnection(array $settings)
	{
		if (empty($GLOBALS['egw_info']['user']['apps']['admin']))
		{
			throw new Api\Exception\NoPermission\Admin();
		}
		Api\Translation::add_app(self::APP);
		Api\Json\Response::get()->data(self::debugConnection(self::configFromSettings($settings)));
	}

	/**
	 * Config as get_ai_config() returns it, from config form values
	 *
	 * An empty API key, or the asterisks Api\Etemplate\Widget\Password sends instead of the stored
	 * one, uses the stored key, so the test works without typing it again.
	 *
	 * @param array $settings values of the config form
	 * @return array
	 */
	public static function configFromSettings(array $settings) : array
	{
		$stored = Api\Config::read(self::APP);
		[$provider, $model] = explode(':', (string)($settings['ai_model'] ?? ''), 2)+[null, null];
		$number = static fn($value) => isset($value) && $value !== '' ? $value : null;
		$key = trim((string)($settings['ai_api_key'] ?? ''));

		return [
			'api_url' => rtrim(trim((string)($settings['ai_api_url'] ?? '')) ?: (Hooks::getProviderUrlMapping()[$provider] ?? ''), '/'),
			'api_key' => $key === '' || preg_match('/^\*+$/', $key) ? trim($stored['ai_api_key'] ?? '') : $key,
			'model'   => $provider === 'custom' || !isset($model) ? trim((string)($settings['ai_custom_model'] ?? '')) : $model,
			'provider' => $provider,
			'reasoning' => ($settings['reasoning'] ?? '') ?: null,
			'max_tokens' => $number($settings['max_tokens'] ?? null),
			'temperature' => $number($settings['temperature'] ?? null),
			'timeout' => (int)($number($settings['timeout'] ?? null) ?? 60),
		];
	}

	/**
	 * Connection test with debug information: models list, then a short chat completion
	 *
	 * The chat completion is built by chatCompletionsData(), so it carries the same parameters
	 * as a real request and fails the same way.
	 *
	 * @param array $config see configFromSettings()
	 * @return array values for keys "ok" (bool) and "steps", each with keys "title", "ok" (bool or
	 *  null for info only), "summary" and "details" (plain text)
	 */
	public static function debugConnection(array $config) : array
	{
		$key = $config['api_key'] ?? '';
		$steps = [[
			'title' => lang('Configuration'),
			'ok' => !empty($config['api_url']) && !empty($config['model']) ? null : false,
			'summary' => !empty($config['api_url']) && !empty($config['model']) ? $config['model'].' @ '.$config['api_url'] :
				lang('Missing AI configuration: API URL or Model!'),
			'details' => implode("\n", [
				'Provider: '.($config['provider'] ?: '-'),
				'Model: '.($config['model'] ?: '-'),
				'API URL: '.($config['api_url'] ?: '-'),
				// last 4 characters only of a key long enough that they give nothing away
				'API key: '.($key === '' ? 'none' : strlen($key).' characters'.(strlen($key) >= 16 ? ', ...'.substr($key, -4) : '')),
				'Reasoning effort: '.($config['reasoning'] ?? 'default (not sent)'),
				'Max tokens: '.($config['max_tokens'] ?? 'default'),
				'Temperature: '.($config['temperature'] ?? 'default'),
				'Timeout: '.$config['timeout'].'s',
				'PHP '.PHP_VERSION.', curl '.(curl_version()['version'] ?? '?').', '.(curl_version()['ssl_version'] ?? ''),
			]),
		]];
		if (empty($config['api_url']) || empty($config['model']))
		{
			return ['ok' => false, 'steps' => $steps];
		}

		// 1. GET /models, as models() does
		$headers = ['Content-Type: application/json'];
		if ($key !== '') $headers[] = 'Authorization: Bearer '.preg_replace('/[\r\n]/', '', $key);
		$res = self::debugRequest($config['api_url'].'/models', $headers, null, 15);
		$models = null;
		if ($res['http_code'] === 200 && is_array($json = json_decode($res['body'], true)))
		{
			$models = array_map(static fn($model) => $model['id'] ?? '?', $json['data'] ?? []);
		}
		$steps[] = [
			'title' => 'GET /models',
			'ok' => isset($models) && in_array($config['model'], $models, true),
			'summary' => !isset($models) ? self::debugFailure($res) :
				(in_array($config['model'], $models, true) ? lang('Model %1 found (%2 models)', $config['model'], count($models)) :
					lang("Invalid model %1, not supported by endpoint!", $config['model'])),
			'details' => self::debugRequestDetails($res).(isset($models) ? "\n\nModels:\n".implode("\n", $models) : ''),
		];

		// 2. POST /chat/completions, as chatCompletions() does
		$data = self::chatCompletionsData($config, [['role' => 'user', 'content' => 'Reply with the single word: OK']]);
		$res = self::debugRequest($config['api_url'].'/chat/completions', [
			'Content-Type: application/json',
			'Authorization: Bearer '.preg_replace('/[\r\n]/', '', $key),
			'Expect:',
		], json_encode($data), $config['timeout']);
		$json = json_decode($res['body'] ?? '', true);
		$message = $json['choices'][0]['message'] ?? null;
		$finish_reason = $json['choices'][0]['finish_reason'] ?? null;
		$ok = $res['http_code'] === 200 && isset($message) && trim((string)($message['content'] ?? '')) !== '';
		$steps[] = [
			'title' => 'POST /chat/completions',
			'ok' => $ok,
			'summary' => $ok ? lang('Answer: %1', mb_substr(trim($message['content']), 0, 100)) :
				($res['http_code'] === 200 && isset($message) ? lang('Empty answer, finish reason: %1', $finish_reason ?? '-') :
					self::debugFailure($res)),
			'details' => "Request body:\n".json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).
				"\n\n".self::debugRequestDetails($res).
				(isset($message) ? "\n\nContent: ".($message['content'] ?? '').
					(!empty($message['reasoning']) || !empty($message['reasoning_content']) ?
						"\nReasoning: ".mb_substr($message['reasoning'] ?? $message['reasoning_content'], 0, 2000) : '').
					"\nFinish reason: ".($finish_reason ?? '-').
					"\nUsage: ".json_encode($json['usage'] ?? null) : ''),
		];

		return ['ok' => $steps[1]['ok'] && $steps[2]['ok'], 'steps' => $steps];
	}

	/**
	 * Run one request for the connection test and collect what curl knows about it
	 *
	 * @param string $url
	 * @param array $headers
	 * @param ?string $body null for GET, else POST
	 * @param int $timeout
	 * @return array values for keys "url", "http_code", "body", "error", "time", "ip", "content_type"
	 */
	protected static function debugRequest(string $url, array $headers, ?string $body, int $timeout) : array
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		if (isset($body))
		{
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$response = curl_exec($ch);
		$result = [
			'url' => $url,
			'http_code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
			'body' => is_string($response) ? $response : null,
			'error' => curl_error($ch),
			'time' => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2),
			'ip' => curl_getinfo($ch, CURLINFO_PRIMARY_IP),
			'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
		];
		return $result;
	}

	/**
	 * One line on why a connection test request failed
	 *
	 * @param array $res see debugRequest()
	 * @return string
	 */
	protected static function debugFailure(array $res) : string
	{
		if ($res['error']) return $res['error'];

		$json = json_decode($res['body'] ?? '', true);
		$error = $json['error']['message'] ?? $json['messages']['message'] ?? $json['error'] ?? null;
		return 'HTTP '.$res['http_code'].(is_string($error) ? ': '.$error : (($res['body'] ?? '') !== '' ? ': '.mb_substr($res['body'], 0, 200) : ''));
	}

	/**
	 * Plain text details of a connection test request
	 *
	 * @param array $res see debugRequest()
	 * @return string
	 */
	protected static function debugRequestDetails(array $res) : string
	{
		return implode("\n", array_filter([
			'URL: '.$res['url'],
			'IP: '.($res['ip'] ?: '-'),
			'HTTP status: '.($res['http_code'] ?: '-'),
			'Content-Type: '.($res['content_type'] ?: '-'),
			'Time: '.$res['time'].'s',
			$res['error'] ? 'curl error: '.$res['error'] : null,
			"Response:\n".(isset($res['body']) ? mb_substr($res['body'], 0, 4000).(strlen($res['body']) > 4000 ? "\n[...]" : '') : '-'),
		]));
	}


	/**
	 * Get DeepL Translator object
	 *
	 * @param array|null $config
	 * @return Translator
	 * @throws DeepLException
	 */
	public static function deeplTranslator(?array $config=null) : Translator
	{
		if (!isset($config))
		{
			$config = Api\Config::read(self::APP);
		}
		return new Translator($config['deepl_api_key'], (empty($config['deepl_api_url']) ? [] :
			['server_url' => $config['deepl_api_url']])+[
				'send_platform_info' => false,  // seems to give an error with PHP 8.5 at least :(
			]);
	}

	/**
	 * Test deepl config by querying the available target languages
	 * @param array|null $config
	 * @return Language[]
	 * @throws DeepLException
	 */
	public static function deeplTargetLanguages(array $config=null) : array
	{
		if (empty($config['deepl_api_key'])) return [];
		return self::deeplTranslator($config)->getTargetLanguages();
	}

	/**
	 * Translate via DeepL
	 *
	 * @param string $content html or plain-text to translate
	 * @param string $target_lang
	 * @param string|null $source_lang on return source-language
	 * @param boolean|null $is_html content is HTML/XML/markup, default null: detect from content
	 * @return string
	 * @throws DeepLException
	 */
	public static function deeplTranslate(string $content, string $target_lang, ?string &$source_lang = null, ?bool $is_html = null)
	{
		switch ($target_lang)
		{
			case 'en':
				$target_lang = 'en-GB'; // gives error "en" is deprecated use "en-GB" or "en-US"
				break;
			case 'es-es':
				$target_lang = 'es';    // "es-419" for Latin American
				break;
			case 'pt':
				$target_lang = 'pt-PT';
				break;
		}
		if($is_html === null)
		{
			$is_html = preg_match_all('/<[^>]+>/', $content, $matches) && count($matches[0]) > 3;
		}

		// plain-text formatting with newlines is NOT preserved :(
		if (!$is_html)
		{
			$content = '<p>'.strtr($content, [
					'<' => '&lt;',
					"\n\n" => '</p><p>',
					"\n" => '<br/>',
				]).'</p>';
		}

		$result = self::deeplTranslator()->translateText($content, $source_lang, $target_lang, [
			// seems to be not supported in 1.4: 'model_type' => 'prefer_quality_optimized',
			TranslateTextOptions::PRESERVE_FORMATTING => true,
		]);
		$source_lang = $result->detectedSourceLang;
		$translation = (string)$result;

		if (!$is_html)
		{
			$translation = html_entity_decode(strip_tags(strtr($translation, [
				'</p><p>' => "\n\n",
				'<br/>' => "\n",
				'<br>' => "\n",
			])), ENT_COMPAT, 'UTF-8');
		}

		return $translation;
	}

	/**
	 * Static AJAX API endpoint for chat interactions
	 *
	 * This is the concrete method AiAssistantController.ts hardcodes as its default `endpoint`
	 * (used by the et2-ai widget - a pure client-side web-component with no server-side widget
	 * class of its own anymore, see ticket #124681 follow-up, 2026-09-17).
	 *
	 * @param string $action
	 * @param ...$params
	 */
	public static function ajaxApi(string $action, ...$params)
	{
		if (empty($GLOBALS['egw_info']['user']['apps'][self::APP]))
		{
			throw new Api\Exception\NoPermission\App();
		}
		(new self())->ajax_api($action, ...$params);
	}

	/**
	 * AJAX API endpoint for chat interactions
	 */
	public function ajax_api()
	{
		// Security: Verify this is a valid EGroupware AJAX request
		// The Api\Json\Response framework should handle CSRF protection
		Api\Json\Response::get();

		// Get parameters from egw.json call
		$params = func_get_args();
		$action = $params[0] ?? $_REQUEST['action'] ?? '';

		// The content being processed is HTML / XML / markup and needs special handling
		$is_markup = (bool)($_REQUEST['is_html']) ?? null;

		try {
			switch ($action)
			{
				case 'process_prompt':
					$prompt_id = $params[1] ?? $_REQUEST['prompt_id'] ?? '';
					$content = $params[2] ?? $_REQUEST['content'] ?? '';
					$options = $params[3] ?? [];
					if(!is_array($options))
					{
						$options = [
							'is_html' => $is_markup
						];
					}
					elseif(!isset($options['is_html']))
					{
						$options['is_html'] = $is_markup;
					}

					// Security: Validate inputs
					if (empty($prompt_id) || !is_string($prompt_id))
					{
						throw new \Exception('Valid prompt ID is required');
					}
					if (!is_string($content))
					{
						throw new \Exception('Valid content is required');
					}

					$result = $this->process_predefined_prompt($prompt_id, $content, $options);
					$response = [
						'success' => true,
					];
					if(is_array($result))
					{
						$response['result'] = $result['content'];
						unset($result['content']);
						$response += $result;
					}
					else
					{
						$response['result'] = $result;
					}
					Api\Json\Response::get()->data($response);
					break;

				default:
					throw new \Exception('Unknown action: ' . $action);
			}
		} catch (\Exception $e) {
			Api\Json\Response::get()->data([
				'success' => false,
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Chat completion request
	 *
	 * @param array $data data to send as JSON
	 * @param array $config
	 * @param array|null &$usage on return usage information
	 * @param bool $is_translation
	 * @return array result or for $path === '/chat/completion' $result['choices'][0]['message']
	 * @throws \Exception
	 */
	protected function chatCompletions(array $data, array $config, ?array &$usage=null, bool $is_translation=false)
	{
		// Security: Sanitize API key to prevent HTTP header injection
		$safe_api_key = preg_replace('/[\r\n]/', '', $config['api_key']);

		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $safe_api_key
		];

		// Make API request
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $config['api_url'] . '/chat/completions');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Translation tasks get longer timeout due to processing complexity
		curl_setopt($ch, CURLOPT_TIMEOUT, $config['timeout'] ?? ($is_translation ? 90 : 60));
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Connection timeout
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		// Enable HTTP/1.1 keep-alive for faster subsequent requests
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		// Disable Expect: 100-continue header for faster POST requests
		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Expect:']));

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error)
		{
			throw new \Exception('API request failed: ' . $curl_error);
		}
		if ($http_code !== 200)
		{
			$error_details = '';
			if ($response)
			{
				$error_response = json_decode($response, true);
				$error_details = $error_response['messages']['message'] ?? $error_response['error']['message'] ?? $response;
			}

			// Security: Log detailed errors but show generic message to users
			// Skip verbose logging for faster error handling
			if ($error_details || !$is_translation)
			{
				$detailed_error = "AI API request failed with status: $http_code";
				if ($error_details)
				{
					$detailed_error .= " - " . $error_details;
				}
				if (isset($config['api_url']))
				{
					$detailed_error .= " (URL: " . $config['api_url'] . ")";
				}
				error_log($detailed_error);
			}

			// User-friendly messages without exposing internal details
			$error_message = 'AI service request failed. ';
			switch($http_code)
			{
				case 403:   // Token budget is used up
					$error_message .= lang('Usage limit reached. Please contact your administrator.');
					break;
				case 400:   // model not on price-list
				case 456:   // over budget
					$error_message .= $error_details.' Please contact your administrator.';
					break;
				case 401:
					$error_message .= 'Authentication error. Please contact your administrator.';
					break;
				case 404:
					$error_message .= 'Service endpoint not found. Please contact your administrator.';
					break;
				case 429:
					$error_message .= 'Rate limit exceeded. Please try again later.';
					break;
				case 500:
					$error_message .= 'Service temporarily unavailable. Please try again later.';
					break;
				default:
					$error_message .= 'Please contact your administrator.';
					break;
			}

			throw new \Exception($error_message, $http_code);
		}

		// Security: Validate response content type
		if ($content_type && strpos($content_type, 'application/json') === false)
		{
			error_log('Unexpected content type from AI API: ' . $content_type);
			throw new \Exception('Invalid response format from AI service.');
		}

		$result = json_decode($response, true);
		if (!$result || !is_array($result))
		{
			error_log('Failed to decode AI API response: ' . substr($response, 0, 200));
			throw new \Exception('Invalid response from AI service.');
		}

		// aggregate usage
		$usage ??= [];
		foreach($result['usage'] ?? [] as $key => $value)
		{
			if (!isset($usage[$key]) || !is_numeric($value))
			{
				$usage[$key] = $value;
			}
			else
			{
				$usage[$key] += $value;
			}
		}

		if (!isset($result['choices'][0]['message']))
		{
			error_log('AI API response missing expected structure');
			throw new \Exception('Unexpected response format from AI service.');
		}

		$ai_message = $result['choices'][0]['message'] ?? null;
		if (empty($ai_message['tool_calls']) || empty($config['tools']))
		{
			$status = $this->openAiResponseStatus($result);

			if (!$status['ok'])
			{
				throw new \Exception($this->openAiResponseStatus($result)['message']);
			}
		}

		return $ai_message;
	}

	/**
	 * Call AI API
	 *
	 * @param array $config config values for keys "api_url", "api_key", "model", "tools", "reasoning", "temperature", "max_tokens", "top_p"
	 * @param array $messages
	 * @param bool $is_translation
	 * @return array values for keys "content", "tool_calls", "usage"
	 */
	protected function call_ai_api(array $config, array $messages, bool $is_translation=false)
	{
		// add what's not explicitly given from our config
		$config += self::get_ai_config();

		// check if tools are supported for this call
		$tools = [];
		if (!empty($config['tools']))
		{
			// getCurrentDateTime is always offered alongside whatever REST tools the prompt allows -
			// it's read-only, side-effect-free and handled locally (execute_tools()), not via a REST
			// round-trip like the rest, replacing the {{systemtime}}/{{userdate}}/{{usertime}} that
			// used to be baked into the (would-be-cached) system prompt on every single call
			$tools = [
				'tools' => array_merge([self::getCurrentDateTimeTool()], Api\CalDAV\OpenAPI::tools($config['tools'])),
				'tool_choice' => 'auto',
			];
		}

		$data = self::chatCompletionsData($config, $messages, $is_translation, $tools);

		// loop for tool-calls
		$max_calls = 5;
		do
		{
			$ai_message = $this->chatCompletions($data, $config, $usage, $is_translation);

			// Execute tools if requested and get a follow-up response
			if (!empty($ai_message['tool_calls']) && !empty($config['tools']))
			{
				error_log("AI Assistant Debug - Tool calls detected: " . count($ai_message['tool_calls']));

				$tool_results = $this->execute_tools($ai_message['tool_calls'], $config['tools']);

				error_log("AI Assistant Debug - Tool results count: " . count($tool_results));

				// Add the assistant's tool call message
				$messages[] = [
					'role' => 'assistant',
					'content' => $ai_message['content'] ?? '',
					'tool_calls' => $ai_message['tool_calls']
				];

				// Add tool results as tool messages
				foreach ($tool_results as $tool_result)
				{
					$result_content = '';
					if (isset($tool_result['result']['message']))
					{
						$result_content = $tool_result['result']['message'];
						error_log("AI Assistant Debug - Tool result message: " . substr($result_content, 0, 1024) . "...");
					}
					elseif (isset($tool_result['result']['success']) && $tool_result['result']['success'])
					{
						$result_content = 'Operation completed successfully';
					}
					elseif (isset($tool_result['result']['error']))
					{
						$result_content = 'Error: ' . $tool_result['result']['error'];
					}
					else
					{
						$result_content = json_encode($tool_result['result']);
					}

					$messages[] = [
						'role' => 'tool',
						'tool_call_id' => $tool_result['id'],
						'content' => $result_content,
					];
				}
				$ai_message['tool_calls'] = $tool_results;

				// Continue with tools still available so AI can chain sequential tool calls
				$data = self::chatCompletionsData($config, $messages, $is_translation, $tools);
			}
		}
		while ($max_calls-- > 0 && !empty($ai_message['tool_calls']) && !empty($config['tools']));

		return [
			'content' => $ai_message['content'] ?? 'I processed your request.',
			'tool_calls' => $ai_message['tool_calls'] ?? null,
			'usage' => $usage ?? null
		];
	}

	/**
	 * Body of a /chat/completions request, as call_ai_api() and the connection test send it
	 *
	 * @param array $config values for keys "model", "reasoning", "temperature", "max_tokens", "top_p"
	 * @param array $messages
	 * @param bool $is_translation
	 * @param array $tools values for keys "tools" and "tool_choice", or empty
	 * @return array
	 */
	protected static function chatCompletionsData(array $config, array $messages, bool $is_translation=false, array $tools=[]) : array
	{
		$data = array_filter($tools+[
			'model' => $config['model'],
			'messages' => $messages,
			// /chat/completions takes the effort as flat "reasoning_effort" - "reasoning" is an object
			// ({"effort": ...}) there, Ollama rejects a string with "cannot unmarshal string"
			'reasoning_effort' => $config['reasoning'] ?? null,
			// Translation is deterministic - use low temperature for faster, more consistent results
			'temperature' => (float)($config['temperature'] ?? ($is_translation ? 0.1 : 0.7)),
			// Translations typically match input length - reduce tokens for faster processing
			'max_tokens' => (int)($config['max_tokens'] ?? ($is_translation ? 4000 : 10000)),
		]);
		if (isset($config['top_p']))
		{
			$data['top_p'] = (float)$config['top_p'];
		}
		return $data;
	}

	/**
	 * Execute tool calls using EGroupware REST APIs
	 */
	private function execute_tools(array $tool_calls, ?array $tool_filter=null) : array
	{
		$results = [];

		foreach ($tool_calls as $tool_call)
		{
			$function_name = $tool_call['function']['name'];
			$arguments = json_decode($tool_call['function']['arguments'], true);

			try {
				// Add timeout protection for each tool call
				$start_time = microtime(true);

				// getCurrentDateTime is a local, non-REST tool (see call_ai_api()) - not routed
				// through OpenAPI::toolCall(), which would only ever find "Invalid operationId" for it
				$result = $function_name === self::CURRENT_DATE_TIME_TOOL ? self::getCurrentDateTimeInternal() :
					Api\CalDAV\OpenAPI::toolCall($function_name, $arguments, $tool_filter??[], !isset($tool_filter));

				$execution_time = round((microtime(true) - $start_time) * 1000);
				error_log("AI Assistant Debug - Tool $function_name executed in {$execution_time}ms");

				$results[] = [
					'id' => $tool_call['id'],
					'function' => $tool_call['function'],
					'result' => $result
				];

			}
			catch (\Throwable $e) {
				error_log("AI Assistant Debug - Tool $function_name failed: " . $e->getMessage());
				$results[] = [
					'id' => $tool_call['id'],
					'function' => $tool_call['function'],
					'result' => ['error' => $e->getMessage()]
				];
			}
		}

		return $results;
	}

	/**
	 * OpenAI tool description for the local (non-REST) getCurrentDateTime tool
	 *
	 * @return array
	 */
	protected static function getCurrentDateTimeTool() : array
	{
		return [
			'type' => 'function',
			'function' => [
				'name' => self::CURRENT_DATE_TIME_TOOL,
				'description' => 'Get the current date and time, in UTC and in the user\'s own '.
					'timezone/preferred format. Call this whenever you need "today", "now", or to '.
					'resolve a relative date/time reference - never guess or rely on stale training data.',
				'parameters' => [
					'type' => 'object',
					'properties' => (object)[],
					'required' => [],
				],
			],
		];
	}

	/**
	 * Execute the local (non-REST) getCurrentDateTime tool
	 *
	 * @return array
	 */
	protected static function getCurrentDateTimeInternal() : array
	{
		$tz = $GLOBALS['egw_info']['user']['preferences']['common']['tz'] ?? 'UTC';

		return [
			'success' => true,
			'utc' => gmdate('Y-m-d\TH:i:s\Z'),
			'user_timezone' => $tz,
			'user_date' => Api\DateTime::to('now', true),
			'user_time' => Api\DateTime::to('now', false),
			'message' => sprintf('Current time: %s UTC (%s %s in timezone %s).',
				gmdate('Y-m-d H:i:s'), Api\DateTime::to('now', true), Api\DateTime::to('now', false), $tz),
		];
	}

	/**
	 * Format tool results as fallback when AI follow-up fails
	 */
	protected function format_tool_results_fallback($tool_results)
	{
		$response = "Here are the results from your request:\n\n";

		foreach ($tool_results as $tool_result)
		{
			$function_name = $tool_result['function']['name'] ?? 'Unknown';
			$result = $tool_result['result'];

			if (isset($result['message']) && is_string($result['message']))
			{
				$response .= $result['message'] . "\n\n";
			}
			elseif (isset($result['success']) && $result['success'])
			{
				$response .= "✅ $function_name completed successfully\n\n";
			}
			elseif (isset($result['error']))
			{
				$response .= "❌ Error in $function_name: " . $result['error'] . "\n\n";
			}
		}

		return $response;
	}

	/**
	 * Process a translation prompt, either via DeepL or a LLM call
	 *
	 * @param $content
	 * @param $target_lang
	 * @param $source_lang
	 * @param $context
	 * @param $is_html
	 * @return array
	 * @throws DeepLException
	 */
	function translate($content, $target_lang, &$source_lang = null, $context = null, $is_html = null)
	{
		if(!empty(Api\Config::read(self::APP)['deepl_api_key']))
		{
			$content = self::deeplTranslate($content, $target_lang, $source_lang, $is_html);
		}
		else
		{
			$messages = [
				[
					'role'    => 'system',
					'content' => Prompts::systemPrompt(true),
				],
				[
					'role'    => 'user',
					'content' => $this->get_translation_prompts($prompt)[self::TRANSLATION_PROMPT_PREFIX.$target_lang]['text'] .
						"\n\n" . self::wrapContent($content),
				]
			];
			// Call AI API with task-specific optimizations
			$response = $this->call_ai_api($prompt, $messages, true);

			// Return just the processed content, not the full response structure
			$content = self::removeContentTags($response['content']) ?? $content;
		}
		return [
			'content'     => $content,
			'source_lang' => $source_lang,
		];
	}

	/**
	 * Return a simple, user-friendly status message for an OpenAI response.
	 *
	 * @param array $response Decoded JSON response from OpenAI
	 * @return array {
	 *   ok: bool,
	 *   message: string
	 * }
	 */
	protected function openAiResponseStatus(array $response) : array
	{
		// API / model error
		if(isset($response['error']))
		{
			return [
				'ok'      => false,
				'message' => 'The AI service could not process your request. Please try again later.'
			];
		}

		// Find finish_reason
		$finishReason = null;

		if(isset($response['choices'][0]['finish_reason']))
		{
			$finishReason = $response['choices'][0]['finish_reason'];
		}
		elseif(isset($response['output'][0]['finish_reason']))
		{
			$finishReason = $response['output'][0]['finish_reason'];
		}

		// Success
		if($finishReason === null || $finishReason === 'stop')
		{
			return [
				'ok'      => true,
				'message' => 'Request completed successfully.'
			];
		}

		// User-friendly failures
		switch($finishReason)
		{
			case 'length':
				$msg = 'The response was too long to complete. Please try a shorter or more specific request.';
				break;

			case 'content_filter':
				$msg = 'The request could not be completed due to content restrictions.';
				break;

			case 'tool_calls':
				$msg = 'The AI could not return a final answer for this request.';
				break;

			default:
				$msg = 'The request could not be completed. Please try again.';
		}

		return [
			'ok'      => false,
			'message' => $msg
		];
	}

	/**
	 * Get available models
	 *
	 * @param bool $use_cache true: use cached data, false: request now
	 * @param array|null $config default use data from self::get_ai_config
	 * @return string[]
	 */
	public static function models(bool $use_cache=true, ?array $config=null)
	{
		// query models and cache them for 120s
		if (!$use_cache) Api\Cache::unsetInstance(__CLASS__, 'models');
		return Api\Cache::getInstance(__CLASS__, 'models', static function() use ($config)
		{
			$config ??= self::get_ai_config();
			if (empty($config['api_url']))
			{
				throw new Api\Exception(lang('Missing AI configuration: API URL or Model!'));
			}
			$headers = [
				'Content-Type: application/json',
			];
			if (!empty($config['api_key']))
			{
				// Security: Sanitize API key to prevent HTTP header injection
				$safe_api_key = preg_replace('/[\r\n]/', '', $config['api_key']);
				$headers[] = 'Authorization: Bearer ' . $safe_api_key;
			}

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $config['api_url'] . '/models');
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 15);

			$response = curl_exec($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($http_code !== 200)
			{
				throw new \Exception('HTTP ' . $http_code . ': ' . $response);
			}

			$result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

			return array_map(static fn($model) => $model['id'], $result['data'] ?? []);
		}, [], 3600);
	}

	/**
	 * Search available models on configured endpoint
	 *
	 * @param ?string $search_text
	 * @param array $search_options
	 * @return array
	 */
	public static function ajax_model_search(?string $search_text=null, array $search_options = []) : array
	{
		$query = $search_text ?? $_REQUEST['query'];

		$models = self::models();

		$results = $models ? [] : ['' => lang('No models found, maybe endpoint not correctly configured!')];
		foreach ($models as $model)
		{
			if (empty($query) || stripos($model, $query) !== false)
			{
				$results[] = ['id' => $model, 'label' => $model];
			}
		}

		// switch regular JSON response handling off
		Api\Json\Request::isJSONRequest(false);

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($results);
		exit;
	}
}
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

	const PRESERVE_MARKUP = "\n- If the content contains HTML or markup you should use it in the response.  ";

	/**
	 * Prefix for all translation prompts (lang-code to be added to get the full prompt-id)
	 */
	const TRANSLATION_PROMPT_PREFIX = 'aiassist.translate-';

	/**
	 * Process predefined prompts for text widgets
	 * 
	 * @param string $prompt_id The predefined prompt ID
	 * @param string $content The text content to process
	 * @param array $options options including is_html
	 * @return array The processed content and additional details
	 */
	public function process_predefined_prompt($prompt_id, $content, $options = [])
	{
		if(!is_array($options))
		{
			$options = ['is_html' => (bool)$options];
		}
		$is_html = $options['is_html'] ?? $options['is_markup'] ?? false;

		// Security: Validate content length to prevent abuse
		if (strlen($content) > self::MAX_CONTENT_LENGTH) {
			throw new \Exception('Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / 1024) . ' KB.');
		}
		
		// Check if this is a translation task for optimizations
		$is_translation = str_starts_with($prompt_id, self::TRANSLATION_PROMPT_PREFIX);
		if($is_translation)
		{
			[, $target_lang] = explode('-', $prompt_id, 2);
			$source_lang = $options['source_lang'] ?? null;
			return $this->translate($content, $target_lang, $source_lang, null, $is_html);
		}
		
		// Define predefined prompts
		$prompts = $this->get_predefined_prompts();
		
		// Security: Sanitize prompt_id to prevent XSS in error messages
		if (!isset($prompts[$prompt_id]))
		{
			throw new \Exception('Unknown prompt ID: ' . htmlspecialchars($prompt_id, ENT_QUOTES, 'UTF-8'));
		}
		
		// Get task-specific instruction
		$task_instruction = $prompts[$prompt_id];

		if($is_html)
		{
			$task_instruction .= self::PRESERVE_MARKUP;
		}

		// Security: Always wrap user content in XML tags for anti-injection protection
		$wrapped_content = "<content>\n" . $content . "\n</content>";

		// For other tasks: use full system prompt with all protections
		$messages = [
			[
				'role'    => 'system',
				'content' => Prompts::systemPrompt(),
			],
			[
				'role'    => 'user',
				'content' => $task_instruction . "\n\n" . $wrapped_content
			]
		];

		// Call AI API with task-specific optimizations
		$ai_response = $this->call_ai_api([], $messages, $prompt_id);

		// Return just the processed content, not the full response structure
		$response = $ai_response['content'] ?? $content;

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
		return [
			'content' => $response,
			'usage'   => $ai_response['usage'] ?? null,
		];
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
		// return either just the prompt-text or id, label and apps
		$map = static fn($prompts) => array_map(static fn($prompt) => $return_prompt ? $prompt['text'] : [
			'id' => $prompt['name'],
			'label' => $prompt['label'],
			'apps' => $prompt['apps'] ? explode(',', $prompt['apps']) : null,
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
	 * @return array name => value pairs, see $return_prompt
	 */
	protected function get_translation_prompts() : array
	{
		$prompts = [];
		// Optimized prompt for faster translation - direct and concise
		if (($template = Prompts::translationPromptTemplate()))
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

			$all_langs = Api\Translation::get_installed_langs();
			foreach ($lang_codes as $code)
			{
				if (isset($all_langs[$code]))
				{
					$prompts[self::TRANSLATION_PROMPT_PREFIX . $code] = [
						'name' => self::TRANSLATION_PROMPT_PREFIX . $code,
						'label' => $all_langs[$code],
					] + str_replace('{$lang}', $all_langs[$code], $template);
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
			'max_tokens' => $config['ai_max_tokens'] ?? null,
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
	 * Call AI API
	 */
	protected function call_ai_api(array $config, array $messages, ?string $prompt_id = '')
	{
		// add what's not explicitly given from our config
		$config += self::get_ai_config();

		// Optimize parameters based on task type
		$is_translation = str_starts_with($prompt_id, self::TRANSLATION_PROMPT_PREFIX);
		
		$data = [
			'model' => $config['model'],
			'messages' => $messages,
			// Translation is deterministic - use low temperature for faster, more consistent results
			'temperature' => $config['temperature'] ?? $is_translation ? 0.1 : 0.7,
			// Translations typically match input length - reduce tokens for faster processing
			'max_tokens' => $config['max_tokens'] ?? $is_translation ? 4000 : (int)($config['max_tokens'] ?? 10000),
		];
		if (isset($config['top_p']))
		{
			$data['top_p'] = $config['top_p'];
		}
		
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
		curl_setopt($ch, CURLOPT_TIMEOUT, $is_translation ? 90 : 60);
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
					$error_message = $detailed_error;
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
		if ($content_type && strpos($content_type, 'application/json') === false) {
			error_log('Unexpected content type from AI API: ' . $content_type);
			throw new \Exception('Invalid response format from AI service.');
		}
		
		$result = json_decode($response, true);
		if (!$result || !is_array($result)) {
			error_log('Failed to decode AI API response: ' . substr($response, 0, 200));
			throw new \Exception('Invalid response from AI service.');
		}
		
		if (!isset($result['choices'][0]['message'])) {
			error_log('AI API response missing expected structure');
			throw new \Exception('Unexpected response format from AI service.');
		}
		
		$status = $this->openAiResponseStatus($result);
		if(!$status['ok'])
		{
			throw new \Exception($this->openAiResponseStatus($result)['message']);
		}

		$ai_message = $result['choices'][0]['message'];
		
		return [
			'content' => $ai_message['content'] ?? 'I processed your request.',
			'usage' => $result['usage'] ?? null
		];
	}

	function translate($content, $target_lang, &$source_lang = null, $context = null, $is_html = null)
	{
		if(!empty(Api\Config::read(self::APP)['deepl_api_key']))
		{
			$content = self::deeplTranslate($content, $target_lang, $source_lang, $is_html);
		}
		else
		{
			// Security: Always wrap user content in XML tags for anti-injection protection
			$wrapped_content = "<content>\n" . $content . "\n</content>";

			$messages = [
				[
					'role'    => 'system',
					'content' => 'You are a professional translator. ONLY process text inside <content> tags. Return ONLY the translated text, no explanations.' . PHP_EOL .
						($is_html ? self::PRESERVE_MARKUP : "")
				],
				[
					'role'    => 'user',
					'content' => $this->get_translation_prompts()[self::TRANSLATION_PROMPT_PREFIX.$target_lang]['text'] .
						"\n\n" . $wrapped_content
				]
			];
			// Call AI API with task-specific optimizations
			$response = $this->call_ai_api([], $messages, "aiassist.translate-" . $target_lang);

			// Return just the processed content, not the full response structure
			$content = $response['content'] ?? $content;
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

			$result = json_decode($response, true, JSON_THROW_ON_ERROR);

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
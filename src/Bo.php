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

namespace EGroupware\AiTools;

use EGroupware\Api;

/**
 * Business logic for AI Assistant
 */
class Bo
{
	const APP = 'aitools';

	const PRESERVE_MARKUP = "\n- If the content contains HTML or markup you should use it in the response.  ";

	/**
	 * Constructor
	 */
	public function __construct()
	{

	}
	
	/**
	 * Process predefined prompts for text widgets
	 * 
	 * @param string $prompt_id The predefined prompt ID
	 * @param string $content The text content to process
	 * @return string The processed content
	 */
	public function process_predefined_prompt($prompt_id, $content)
	{
		// Get AI configuration
		$api_config = $this->get_ai_config();
		if (empty($api_config['api_key'])) {
			throw new \Exception('AI API not configured. Please contact your administrator.');
		}
		
		// Define predefined prompts
		$prompts = $this->get_predefined_prompts();
		
		if (!isset($prompts[$prompt_id])) {
			throw new \Exception('Unknown prompt ID: ' . $prompt_id);
		}
		
		$prompt_template = $prompts[$prompt_id];
		$system_message = str_replace('{content}', $content, $prompt_template);
		
		// Prepare messages for AI API call
		$messages = [
			[
				'role' => 'system',
				'content' => $system_message
			],
			[
				'role' => 'user', 
				'content' => $content
			]
		];
		
		// Call AI API
		$response = $this->call_ai_api($api_config, $messages);
		
		// Return just the processed content, not the full response structure
		return $response['content'] ?? $content;
	}
	
	/**
	 * Get predefined prompt templates
	 */
	protected function get_predefined_prompts()
	{
		return [
				'aiassist.summarize'        => 'Please summarize the following text concisely while preserving the key information and main points. Return only the summary without any additional commentary.' . self::PRESERVE_MARKUP,
				'aiassist.formal'           => 'Please rewrite the following text to make it more professional and formal while maintaining the original meaning. Return only the revised text.',
				'aiassist.casual'           => 'Please rewrite the following text to make it more casual and friendly while maintaining the original meaning. Return only the revised text.',
				'aiassist.grammar'          => 'Please correct any grammar, spelling, and punctuation errors in the following text while preserving the original meaning and tone. Return only the corrected text.',
				'aiassist.concise'          => 'Please make the following text more concise and to-the-point while preserving all important information. Return only the condensed text.',
				'aiassist.generate_reply'   => 'Based on the following text, generate a professional email reply. Return only the reply content.',
				'aiassist.meeting_followup' => 'Based on the following content, create a professional meeting follow-up message. Return only the follow-up content.',
				'aiassist.thank_you'        => 'Based on the following context, create a professional thank you note. Return only the thank you message.',
				'aiassist.generate_subject' => 'Based on the following content, generate a clear and concise subject line that accurately summarizes the main topic or purpose. Return only the subject line without quotes or additional text.',
			] + $this->get_translation_prompts();
	}

	protected function get_translation_prompts()
	{
		$prompts = [];
		$prompt = 'Please translate the following text to {$lang}. Return only the translated text.  Match the formatting of the response as closely to the original as possible.' . self::PRESERVE_MARKUP;
		foreach(Api\Translation::get_installed_langs() as $code => $lang)
		{
			$prompts['aiassist.translate-' . $code] = str_replace('{$lang}', $lang, $prompt);
		}
		return $prompts;
	}

	/**
	 * Get AI configuration
	 */
	function get_ai_config()
	{
		$config = Api\Config::read(self::APP);
		// splitt off provider prefix
		[$provider, $model] = explode(':', $config['ai_model'], 2)+[null, null];

		return [
			'api_url' => $config['ai_api_url'] ?? Hooks::getProviderUrlMapping()[$provider],
			'api_key' => trim($config['ai_api_key'] ?? ''),
			'model'   => $model ?? $config['ai_custom_model'] ?? null,
			'provider' => $provider,
			'max_tokens' => $config['ai_max_tokens'] ?? null,
		];
	}
	
	/**
	 * Get system prompt for AI
	 */
	protected function get_system_prompt()
	{
		$user_name = $GLOBALS['egw_info']['user']['account_fullname'] ?: $GLOBALS['egw_info']['user']['account_lid'];
		
		return "You are an AI assistant integrated into EGroupware, helping user '{$user_name}' with their daily business tasks. " .
			   "CRITICAL WORKFLOW INSTRUCTIONS - FOLLOW THESE EXACTLY:\n" .
			   "1. Present all results clearly with proper formatting\n" .
			   "2. If no results found, state clearly and offer next steps\n\n" .
			   "RESPONSE FORMAT:\n" .
			   "- Present complete results immediately\n" .
			   "- Use clear headings and formatting\n" .
			   "- Include all requested information in one comprehensive response\n\n" .
			   "EXAMPLE: User asks 'What's my schedule for today?'\n" .
			   "YOUR RESPONSE: Call get_current_date + search_calendar_events, then immediately show:\n" .
			   "'### Today's Schedule (August 19, 2025)\n[Complete calendar results here]'";
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
		if (!isset($config))
		{
			$config = (new self)->get_ai_config();
		}
		if (empty($config['api_url']) || empty($config['model']))
		{
			throw new Api\Exception('Missing configuration: API URL or Model!');
		}
		$headers = [
			'Content-Type: application/json',
		];
		if (!empty($config['api_key']))
		{
			$headers[] = 'Authorization: Bearer ' . $config['api_key'];
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

		if (!array_filter($result['data'] ?? [], fn($model) => $model['id'] === $config['model']))
		{
			throw new \Exception("Invalid model $config[model], not supported by endpoint!");
		}

		return true;
	}

	/**
	 * AJAX API endpoint for chat interactions
	 */
	public function ajax_api()
	{
		Api\Json\Response::get();

		// Get parameters from egw.json call
		$params = func_get_args();
		$action = $params[0] ?? $_REQUEST['action'] ?? '';

		try {
			switch ($action)
			{
				case 'process_prompt':
					$prompt_id = $params[1] ?? $_REQUEST['prompt_id'] ?? '';
					$content = $params[2] ?? $_REQUEST['content'] ?? '';

					if (empty($prompt_id) || empty($content))
					{
						throw new \Exception('Both prompt ID and content are required');
					}

					$result = $this->process_predefined_prompt($prompt_id, $content);
					Api\Json\Response::get()->data([
						'success' => true,
						'result' => $result
					]);
					break;

				case 'test_api':
					// Handle AJAX API testing
					$this->test_api_ajax();
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
	protected function call_ai_api($config, $messages)
	{
		$data = [
			'model' => $config['model'],
			'messages' => $messages,
			'temperature' => 0.7,
			'max_tokens' => (int)($config['max_tokens'] ?? 10000),
		];
		
		$headers = [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $config['api_key']
		];
		
		// Make API request
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $config['api_url'] . '/chat/completions');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout for tool execution
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);
		
		if ($curl_error) {
			throw new \Exception('API request failed: ' . $curl_error);
		}
		
		if ($http_code !== 200) {
			$error_details = '';
			if ($response) {
				$error_response = json_decode($response, true);
				$error_details = $error_response['error']['message'] ?? $response;
			}
			
			$error_message = "AI API request failed with status: $http_code";
			if ($error_details) {
				$error_message .= " - " . $error_details;
			}
			
			// Add debugging info for common errors
			if ($http_code === 401) {
				$error_message .= "\nPlease verify your API key is correct and has the necessary permissions.";
			} elseif ($http_code === 404) {
				$error_message .= "\nPlease check the API URL: " . $config['api_url'];
			}
			
			throw new \Exception($error_message);
		}
		
		$result = json_decode($response, true);
		if (!$result || !isset($result['choices'][0]['message'])) {
			throw new \Exception('Invalid AI API response format');
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
	/**
	 * Get current date and time information for debugging
	 */
	protected function get_current_date_internal($args)
	{
		$system_time = time();
		$user_tz = $GLOBALS['egw_info']['user']['preferences']['common']['tz'] ?? 'UTC';
		
		return [
			'success' => true,
			'message' => sprintf(
				"**Current Date & Time Information:**\n\n" .
				"🕒 **System Time:** %s UTC\n" .
				"🌍 **User Timezone:** %s\n" .
				"📅 **Today's Date:** %s\n" .
				"⏰ **Current Time:** %s\n" .
				"📆 **Week Info:** Week of %s",
				date('Y-m-d H:i:s', $system_time),
				$user_tz,
				date('Y-m-d', $system_time),
				date('H:i:s', $system_time),
				date('Y-m-d', strtotime('monday this week', $system_time))
			),
			'timestamp' => $system_time,
			'user_timezone' => $user_tz
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
}
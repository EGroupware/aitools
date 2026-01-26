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
 * diverse hooks as static methods
 *
 */
class Hooks
{
	const APP = 'aitools';

	/**
	 * Hooks to build RAGs sidebox-menu plus the admin and Api\Preferences sections
	 *
	 * @param string|array $args hook args
	 */
	static function allHooks($args)
	{
		$appname = self::APP;
		$location = is_array($args) ? $args['location'] : $args;

		if ($location == 'sidebox_menu')
		{

		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site Configuration' => Api\Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname.'&ajax=true'),
			);
			if ($location == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				//$GLOBALS['egw']->framework->sidebox($appname, lang('Configuration'), $file);
			}
		}
	}

	/**
	 * Get URL mapping for different AI providers
	 *
	 * @return array
	 */
	public static function getProviderUrlMapping()
	{
		return array(
			'egroupware'=> 'https://ai-proxy.egroupware.org/v1',
			'ionos'     => 'https://openai.inference.de-txl.ionos.com/v1',
			'ollama'    => 'http://172.17.0.1:11434/v1',
			'openai'    => 'https://api.openai.com/v1',
			'anthropic' => 'https://api.anthropic.com/v1',
			'google'    => 'https://generativelanguage.googleapis.com/v1',
			'azure'     => 'https://models.inference.ai.azure.com',
		);
	}

	/**
	 * Hook called for config values
	 *
	 * @param array $data
	 * @return array with config values or sel_options
	 */
	public static function config($data)
	{
		// Load current configuration values from standard EGroupware config
		$config = Api\Config::read(self::APP);

		// Return select options for dropdowns and current/default values
		return [
			'sel_options' => [
				'ai_model' => [
					'egroupware:openai/gpt-oss-120b' => 'EGroupware/IONOS OpenAI GPT 4 120B',
					'egroupware:meta-llama/Llama-3.3-70B-Instruct' => 'EGroupware/IONOS Meta Llama 3.3 70B',
					'egroupware:mistralai/Mistral-Small-24B-Instruct' => 'EGroupware/IONOS Mistral Small 24B',
					'ollama:mistral-small3.1:24b-instruct-2503-q4_K_M' => 'Ollama Mistral 3.1 Small (24b-instruct-2503-q4_K_M)',
					/* no need to promote these
					'openai:gpt-4o' => 'OpenAI GPT-4o',
					'openai:gpt-4o-mini' => 'OpenAI GPT-4o Mini',
					'openai:gpt-4-turbo' => 'OpenAI GPT-4 Turbo',
					'openai:gpt-3.5-turbo' => 'OpenAI GPT-3.5 Turbo',
					'anthropic:claude-3-5-sonnet-20241022' => 'Anthropic Claude 3.5 Sonnet',
					'anthropic:claude-3-5-haiku-20241022' => 'Anthropic Claude 3.5 Haiku',
					'anthropic:claude-3-opus-20240229' => 'Anthropic Claude 3 Opus',
					'google:gemini-1.5-pro' => 'Google Gemini 1.5 Pro',
					'google:gemini-1.5-flash' => 'Google Gemini 1.5 Flash',
					'azure:gpt-4o' => 'Azure OpenAI GPT-4o',
					'azure:gpt-4o-mini' => 'Azure OpenAI GPT-4o Mini',*/
					'custom' => 'Custom',
				],
			],
		];
	}

	/**
	 * Hook called for config validation
	 *
	 * @param array $data
	 * @return ?string with error-message or NULL on success
	 */
	public static function configValidate($data)
	{
		// unset marker from Api\Etemplate\Widget\Ai
		Api\Cache::unsetInstance(self::APP, 'configured');

		// Autopopulate API URL based on a selected model
		if (!empty($data['ai_model']))
		{
			[$provider, $model] = explode(':', $data['ai_model']);
			$urlMapping = self::getProviderUrlMapping();

			if (isset($urlMapping[$provider]) && empty($data['ai_api_url']))
			{
				$data['ai_api_url'] = $urlMapping[$provider];
			}

			try {
				Bo::test_api_connection([
					'api_url' => $data['ai_api_url'],
					'api_key' => $data['ai_api_key'],
					'model' => $data['ai_custom_model'] ?: $model,
					'provider' => $provider,
				]);
			}
			catch (\Exception $e) {
				return $e->getMessage();
			}
		}
		if (!empty($data['deepl_api_key']))
		{
			try {
				Bo::deeplTargetLanguages(array_filter($data, fn($key) => str_starts_with($key, 'deepl_'), ARRAY_FILTER_USE_KEY));
			}
			catch (\Exception $e) {
				return $e->getMessage();
			}
		}

		return null;
	}

	public static function preferences($data)
	{
		return [
			'languages' => [
				'name'       => 'languages',
				'type'       => 'et2-select-lang',
				'label'      => "Translation languages",
				'attributes' => ['search' => true, 'multiple' => true],
				'default'    => 'en,de,fr,it,es-es',
			]
		];
	}
}
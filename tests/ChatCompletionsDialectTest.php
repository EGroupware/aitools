<?php
/**
 * EGroupware AiTools: /chat/completions request body per API dialect
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

use PHPUnit\Framework\TestCase;

/**
 * BEHAVIOUR UNDER TEST
 * Bo::chatCompletionsData() builds the body for all providers, which do not agree on the fields:
 * - generic (Ollama, llama.cpp, vLLM, proxies): flat "reasoning_effort", "max_tokens", temperature
 * - OpenAI: "max_tokens" is deprecated and rejected by reasoning models (o-series, gpt-5), which
 *   want "max_completion_tokens" and reject a non-default temperature; the other models reject
 *   "reasoning_effort"
 * - Anthropic's OpenAI compatible endpoint ignores "reasoning_effort", thinking is switched on with
 *   "thinking": {"type": "adaptive"} (Claude 4.6+) or {"type": "enabled", "budget_tokens": N}
 *   (older models, 1024 <= N < max_tokens); with thinking no sampling parameters, and newer models
 *   (Opus 4.7+, Sonnet 5, Fable) take none at all
 * Bo::apiDialect() picks the dialect: configured value, then provider prefix, then URL host.
 *
 * ENVIRONMENT
 * Pure functions, no session, no database, no endpoint.
 */
class ChatCompletionsDialectTest extends TestCase
{
	/**
	 * Call the protected Bo::chatCompletionsData()
	 */
	protected static function data(array $config, bool $is_translation=false) : array
	{
		$method = new \ReflectionMethod(Bo::class, 'chatCompletionsData');
		return $method->invoke(null, $config+['temperature' => '0.5', 'max_tokens' => '10000'],
			[['role' => 'user', 'content' => 'x']], $is_translation);
	}

	public function testDialectDetection()
	{
		$this->assertSame('generic', Bo::apiDialect(['provider' => 'custom', 'api_url' => 'http://10.0.0.1:11434/v1']));
		$this->assertSame('openai', Bo::apiDialect(['provider' => 'openai', 'api_url' => 'http://proxy/v1']));
		$this->assertSame('anthropic', Bo::apiDialect(['provider' => 'custom', 'api_url' => 'https://api.anthropic.com/v1']));
		$this->assertSame('openai', Bo::apiDialect(['provider' => 'custom', 'api_url' => 'https://api.openai.com/v1']));
		$this->assertSame('generic', Bo::apiDialect(['provider' => 'custom', 'api_url' => 'https://notopenai.com/v1']),
			'only openai.com and its subdomains, not a lookalike');
		$this->assertSame('anthropic', Bo::apiDialect(['api_dialect' => 'anthropic', 'provider' => 'egroupware']),
			'a configured dialect wins over the provider');
		$this->assertSame('openai', Bo::apiDialect(['api_dialect' => 'auto', 'provider' => 'openai']),
			'"auto" is not a dialect');
	}

	public function testGenericBodyIsUnchanged()
	{
		$data = self::data(['model' => 'qwen3.8:27b', 'provider' => 'custom', 'reasoning' => 'low']);

		$this->assertSame('low', $data['reasoning_effort']);
		$this->assertSame(10000, $data['max_tokens']);
		$this->assertSame(0.5, $data['temperature']);
		$this->assertArrayNotHasKey('reasoning', $data);
		$this->assertArrayNotHasKey('max_completion_tokens', $data);
		$this->assertArrayNotHasKey('thinking', $data);
	}

	public function testGenericWithoutEffortSendsNoEffort()
	{
		$this->assertArrayNotHasKey('reasoning_effort', self::data(['model' => 'qwen3.8:27b', 'provider' => 'custom']));
	}

	public function testOpenAiReasoningModel()
	{
		$data = self::data(['model' => 'gpt-5-mini', 'provider' => 'openai', 'reasoning' => 'low', 'top_p' => '0.9']);

		$this->assertSame(10000, $data['max_completion_tokens']);
		$this->assertArrayNotHasKey('max_tokens', $data, 'deprecated, rejected by reasoning models');
		$this->assertSame('low', $data['reasoning_effort']);
		$this->assertArrayNotHasKey('temperature', $data, 'reasoning models reject a non-default temperature');
		$this->assertArrayNotHasKey('top_p', $data);
	}

	public function testOpenAiOtherModel()
	{
		$data = self::data(['model' => 'gpt-4o', 'provider' => 'openai', 'reasoning' => 'low']);

		$this->assertSame(10000, $data['max_completion_tokens']);
		$this->assertArrayNotHasKey('max_tokens', $data);
		$this->assertArrayNotHasKey('reasoning_effort', $data, 'non-reasoning models reject it');
		$this->assertSame(0.5, $data['temperature']);
	}

	public function testAnthropicCurrentModelThinksAdaptive()
	{
		$data = self::data(['model' => 'claude-opus-5', 'provider' => 'anthropic', 'reasoning' => 'high']);

		$this->assertSame(['type' => 'adaptive'], $data['thinking']);
		$this->assertArrayNotHasKey('reasoning_effort', $data, 'ignored by the compatible endpoint');
		$this->assertArrayNotHasKey('temperature', $data);
		$this->assertSame(10000, $data['max_tokens']);
	}

	public function testAnthropicOlderModelGetsABudget()
	{
		foreach (['claude-haiku-4-5', 'claude-sonnet-4-5-20250929', 'claude-opus-4-1', 'claude-sonnet-4-20250514', 'claude-3-7-sonnet-latest'] as $model)
		{
			$data = self::data(['model' => $model, 'provider' => 'anthropic', 'reasoning' => 'medium']);
			$this->assertSame(['type' => 'enabled', 'budget_tokens' => 8192], $data['thinking'], $model);
			$this->assertArrayNotHasKey('temperature', $data, "$model: no sampling parameters with thinking");
		}
		$this->assertSame(['type' => 'adaptive'],
			self::data(['model' => 'claude-sonnet-4-6', 'provider' => 'anthropic', 'reasoning' => 'medium'])['thinking'],
			'4.6 has adaptive thinking');
	}

	public function testAnthropicBudgetStaysBelowMaxTokens()
	{
		$data = self::data(['model' => 'claude-haiku-4-5', 'provider' => 'anthropic', 'reasoning' => 'xhigh', 'max_tokens' => '4000']);
		$this->assertSame(2976, $data['thinking']['budget_tokens']);
		$this->assertSame(4000, $data['max_tokens'], 'the configured limit is kept');

		$data = self::data(['model' => 'claude-haiku-4-5', 'provider' => 'anthropic', 'reasoning' => 'low', 'max_tokens' => '500']);
		$this->assertSame(1024, $data['thinking']['budget_tokens'], 'the API minimum');
		$this->assertSame(2048, $data['max_tokens'], 'raised, as it has to be above the budget');
	}

	public function testAnthropicWithoutEffort()
	{
		$data = self::data(['model' => 'claude-haiku-4-5', 'provider' => 'anthropic', 'reasoning' => 'none']);
		$this->assertArrayNotHasKey('thinking', $data, '"none" sends nothing - some models reject {"type": "disabled"}');
		$this->assertSame(0.5, $data['temperature'], 'without thinking an older model takes a temperature');

		$data = self::data(['model' => 'claude-opus-5', 'provider' => 'anthropic']);
		$this->assertArrayNotHasKey('thinking', $data);
		$this->assertArrayNotHasKey('temperature', $data, 'Claude 5 models reject sampling parameters');
	}
}

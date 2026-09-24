<?php
/**
 * EGroupware AiTools: outgoing /chat/completions request must send numbers as JSON numbers
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

/**
 * Bo::call_ai_api()/chatCompletions() build the outgoing JSON body straight from Api\Config values -
 * which are always plain strings, since egw_config is a varchar key/value store: whatever an
 * et2-number widget (see aitools/templates/default/config.xet's "temperature"/"max_tokens" fields)
 * submits comes back out of Api\Config::read() as a string, never a float/int.
 *
 * `max_tokens` is cast with (int) on the FIRST /chat/completions call, but NOT on the follow-up call
 * built inside the tool-call loop, and `temperature`/`top_p` are never cast at all - so a configured
 * (non-default) temperature/top_p, or a follow-up round's max_tokens, goes out as a JSON STRING, not
 * a number. Several OpenAI-compatible servers (eg. llama.cpp) validate the request against a JSON
 * schema and reject that with "Field 'temperature': type must be number, but is string.".
 *
 * Reported live against a local llama.cpp endpoint, see
 * https://help.egroupware.org/t/aitools-mehrere-reproduzierbare-probleme-bei-tool-calls-und-openai-kompatiblen-apis/80042
 * (2026-09-24).
 *
 * A tiny local `php -S` server records the raw JSON body of every /chat/completions request it
 * receives, so these tests can inspect the actual wire types sent - not just what
 * Bo::call_ai_api() happens to return (which decodes the AI's response, not the outgoing request).
 */
class ChatCompletionsRequestTypesTest extends \EGroupware\Api\AppTest
{
	private static $server_process;
	private static string $server_url;
	private static string $fixture_dir;

	protected $orig_config;

	public static function setUpBeforeClass() : void
	{
		parent::setUpBeforeClass();

		self::$fixture_dir = sys_get_temp_dir().'/AiToolsChatCompletionsTest-'.bin2hex(random_bytes(4));
		mkdir(self::$fixture_dir);
		file_put_contents(self::$fixture_dir.'/router.php', self::routerSource());

		// find a free loopback port, then immediately hand it to `php -S`
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		[, $port] = explode(':', stream_socket_get_name($socket, false));
		fclose($socket);

		self::$server_url = "http://127.0.0.1:$port";
		$cmd = escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$port.' '.escapeshellarg(self::$fixture_dir.'/router.php');
		self::$server_process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$fixture_dir);
		self::assertIsResource(self::$server_process, 'failed to start php -S test server');

		// wait for the server to accept connections (max ~2s)
		for ($i = 0; $i < 40; $i++)
		{
			if (($conn = @fsockopen('127.0.0.1', (int)$port, $errno, $errstr, 0.1)))
			{
				fclose($conn);
				return;
			}
			usleep(50000);
		}
		self::fail('test php -S server did not start listening in time');
	}

	public static function tearDownAfterClass() : void
	{
		if (self::$server_process)
		{
			proc_terminate(self::$server_process);
			proc_close(self::$server_process);
		}
		array_map('unlink', glob(self::$fixture_dir.'/*'));
		@rmdir(self::$fixture_dir);
		parent::tearDownAfterClass();
	}

	/**
	 * Minimal fake /chat/completions endpoint: logs every raw request body verbatim (so the test can
	 * inspect the exact wire types sent) and returns a canned response - a tool-call on the first
	 * request that carries "tools", a plain final answer once it sees a 'tool' role message (ie. a
	 * follow-up round after a tool result was sent back).
	 */
	private static function routerSource() : string
	{
		return <<<'EOT'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/chat/completions')
{
	http_response_code(404);
	echo json_encode(['error' => 'unexpected path: '.$path]);
	exit;
}
$raw = file_get_contents('php://input');
file_put_contents(__DIR__.'/requests.log', $raw."\n", FILE_APPEND);
$data = json_decode($raw, true);

header('Content-Type: application/json');

$saw_tool_result = false;
foreach ($data['messages'] ?? [] as $m)
{
	if (($m['role'] ?? '') === 'tool') { $saw_tool_result = true; break; }
}

if (!empty($data['tools']) && !$saw_tool_result)
{
	echo json_encode([
		'choices' => [[
			'message' => [
				'role' => 'assistant',
				'content' => null,
				'tool_calls' => [[
					'id' => 'call_1',
					'type' => 'function',
					'function' => ['name' => $data['tools'][0]['function']['name'], 'arguments' => '{}'],
				]],
			],
			'finish_reason' => 'tool_calls',
		]],
		'usage' => ['total_tokens' => 10],
	]);
}
else
{
	echo json_encode([
		'choices' => [[
			'message' => ['role' => 'assistant', 'content' => 'Done.'],
			'finish_reason' => 'stop',
		]],
		'usage' => ['total_tokens' => 5],
	]);
}
EOT;
	}

	protected function setUp() : void
	{
		parent::setUp();
		$this->orig_config = Api\Config::read(Bo::APP);
		// mimic what a real saved config looks like: everything comes back as a string
		Api\Config::save_value('ai_model', 'openai:test-model', Bo::APP);
		Api\Config::save_value('ai_api_url', self::$server_url, Bo::APP);
		Api\Config::save_value('ai_api_key', 'test-key', Bo::APP);
		Api\Config::save_value('temperature', '0.42', Bo::APP);
		Api\Config::save_value('max_tokens', '321', Bo::APP);
		@unlink(self::$fixture_dir.'/requests.log');
	}

	protected function tearDown() : void
	{
		foreach (['ai_model', 'ai_api_url', 'ai_api_key', 'temperature', 'max_tokens'] as $key)
		{
			Api\Config::save_value($key, $this->orig_config[$key] ?? null, Bo::APP);
		}
		parent::tearDown();
	}

	private function loggedRequests() : array
	{
		$lines = array_filter(explode("\n", @file_get_contents(self::$fixture_dir.'/requests.log') ?: ''));
		return array_values(array_map(static fn($line) => json_decode($line, true), $lines));
	}

	public function testTemperatureIsSentAsJsonNumberNotString()
	{
		(new Bo())->process_predefined_prompt(['name' => 'test_prompt', 'text' => 'Say hi.'], 'irrelevant content');

		$requests = $this->loggedRequests();
		$this->assertNotEmpty($requests, 'AI endpoint was never called');
		$this->assertIsFloat($requests[0]['temperature'] ?? null,
			"configured temperature '0.42' must be sent as a JSON number, not the string EGroupware ".
			'stores it as - llama.cpp and other OpenAI-compatible servers reject a string here');
	}

	public function testMaxTokensIsSentAsJsonNumberOnFollowUpToolCallRound()
	{
		(new Bo())->process_predefined_prompt(
			['name' => 'test_prompt', 'text' => 'Search something.', 'tools' => ['searchContacts']],
			'irrelevant content'
		);

		$requests = $this->loggedRequests();
		$this->assertGreaterThanOrEqual(2, count($requests),
			'expected an initial call plus a follow-up call after the tool result');
		$this->assertIsInt($requests[1]['max_tokens'] ?? null,
			"configured max_tokens '321' must be sent as a JSON number on the follow-up call too, ".
			'not just the first one');
	}
}

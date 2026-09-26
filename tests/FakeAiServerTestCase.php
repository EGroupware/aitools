<?php
/**
 * EGroupware AiTools: shared test harness - a real local "OpenAI-compatible" /chat/completions endpoint
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\AiTools;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');

use EGroupware\Api;

/**
 * A tiny local `php -S` server standing in for an OpenAI-compatible /chat/completions endpoint - a
 * genuine HTTP round-trip (the same pattern as api/tests/CalDAV/RestClientTraitTest.php), not a
 * mock, so tests exercise the actual curl/JSON-encoding code in Bo::chatCompletions(), not a
 * stand-in for it. Every request's raw JSON body is logged verbatim to a file, so a test can
 * inspect exactly what was sent over the wire (types included), not just what Bo::call_ai_api()
 * happens to return.
 *
 * The default router (see routerSource()) is generic enough for most tests: a canned tool-call on
 * the first request if it carries "tools" and hasn't seen a tool result yet, a canned final answer
 * otherwise. Override routerSource() in a subclass for different canned behaviour.
 */
abstract class FakeAiServerTestCase extends \EGroupware\Api\AppTest
{
	private static $server_process;
	protected static string $server_url;
	private static string $fixture_dir;

	protected $orig_config;

	public static function setUpBeforeClass() : void
	{
		parent::setUpBeforeClass();

		self::$fixture_dir = sys_get_temp_dir().'/AiToolsFakeServer-'.bin2hex(random_bytes(4));
		mkdir(self::$fixture_dir);
		file_put_contents(self::$fixture_dir.'/router.php', static::routerSource());

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
	 * The router source used by the `php -S` server - override in a subclass for different canned
	 * AI behaviour. Must log every request body to "requests.log" in its own directory (__DIR__), one
	 * JSON blob per line, for loggedRequests() to work.
	 */
	protected static function routerSource() : string
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
		// the "openai:" prefix would pick the OpenAI dialect, these tests are about the generic body
		// (the dialects are covered by ChatCompletionsDialectTest)
		Api\Config::save_value('api_dialect', 'generic', Bo::APP);
		@unlink(self::$fixture_dir.'/requests.log');
	}

	protected function tearDown() : void
	{
		foreach (['ai_model', 'ai_api_url', 'ai_api_key', 'temperature', 'max_tokens', 'reasoning', 'api_dialect'] as $key)
		{
			Api\Config::save_value($key, $this->orig_config[$key] ?? null, Bo::APP);
		}
		parent::tearDown();
	}

	/**
	 * @return array[] every request body received so far, decoded, in the order they arrived
	 */
	protected function loggedRequests() : array
	{
		$lines = array_filter(explode("\n", @file_get_contents(self::$fixture_dir.'/requests.log') ?: ''));
		return array_values(array_map(static fn($line) => json_decode($line, true), $lines));
	}
}

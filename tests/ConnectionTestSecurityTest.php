<?php
/**
 * EGroupware AI Tools - the connection test reads no local files and talks only http(s)
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Aitools;

use PHPUnit\Framework\TestCase;

/**
 * The Test connection button sends to whatever API URL the config form holds and shows the
 * answer: "file:///var/www/egroupware/header.inc.php#" (the "#" swallowing the appended
 * "/models") returned the header with the DB credentials.
 */
class ConnectionTestSecurityTest extends TestCase
{
	public static function urlProvider() : array
	{
		return [
			['https://api.openai.com/v1', true],
			['http://ollama.local:11434/v1', true],
			['HTTPS://example.org/v1', true],
			['file:///var/www/egroupware/header.inc.php#', false],
			['file:///etc/hostname', false],
			['gopher://127.0.0.1:6379/_INFO', false],
			['dict://127.0.0.1:11211/stats', false],
			['//example.org/v1', false],
			['example.org/v1', false],
			['https://example.org/v1#', false],
			['https://example.org/v1?x=', false],
			['https://user:pass@example.org/v1', false],
			['https://example.org@evil.example/v1', false],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('urlProvider')]
	public function testApiUrlError(string $url, bool $valid)
	{
		$this->assertSame($valid, Bo::apiUrlError($url) === null, $url);
	}

	/**
	 * Even a URL that got past the check: curl itself must refuse anything but http(s)
	 */
	public function testCurlRefusesFileUrls()
	{
		$request = new \ReflectionMethod(Bo::class, 'debugRequest');
		$res = $request->invoke(null, 'file:///etc/hostname#/models', [], null, 5);

		$this->assertTrue(empty($res['body']), 'file:// must not be read: '.json_encode($res['body']));
		$this->assertNotSame('', $res['error']);
	}

	public function testTimeoutIsClamped()
	{
		$this->assertNull(Bo::clampTimeout(null));
		$this->assertNull(Bo::clampTimeout(''));
		$this->assertNull(Bo::clampTimeout('abc'));
		$this->assertSame(Bo::TIMEOUT_MIN, Bo::clampTimeout(0));
		$this->assertSame(Bo::TIMEOUT_MIN, Bo::clampTimeout('-5'));
		$this->assertSame(90, Bo::clampTimeout('90'));
		$this->assertSame(Bo::TIMEOUT_MAX, Bo::clampTimeout(99999));
	}
}

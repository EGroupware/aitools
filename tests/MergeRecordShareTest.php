<?php
/**
 * EGroupware AI Tools - a prompt never merges share placeholders
 *
 * @link https://www.egroupware.org
 * @package aitools
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Aitools;

use PHPUnit\Framework\TestCase;

/**
 * Merging $$share/writable$$ creates a writable share link of the record, and the prompt text
 * comes from whoever wrote the prompt, not from the user running it.
 */
class MergeRecordShareTest extends TestCase
{
	public function testSharePlaceholdersAreRemoved()
	{
		$this->assertSame('a  b  c  d  e ',
			Bo::stripSharePlaceholders('a $$share$$ b $$share/writable$$ c {{share/readonly}} d $$SHARE/x$$ e '));
	}

	public function testOtherPlaceholdersStay()
	{
		$text = 'Summarize {{info_des}} for $$info_subject$$ and $$shared_field$$ / {{links/addressbook/email}}';
		$this->assertSame($text, Bo::stripSharePlaceholders($text));
	}
}

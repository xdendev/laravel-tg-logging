<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Xden\TelegramLogger\Formatting\Markup;
use Xden\TelegramLogger\Formatting\ParseMode;
use Xden\TelegramLogger\Support\FailureReporter;

final class MarkupTest extends TestCase
{
    #[Test]
    public function html_escaping(): void
    {
        $fmt = new Markup(ParseMode::Html);

        $this->assertSame('a &lt;b&gt; &amp; "c"', $fmt->text('a <b> & "c"'));
        $this->assertSame('<b>&lt;x&gt;</b>', $fmt->bold('<x>'));
        $this->assertSame('<pre>1 &lt; 2</pre>', $fmt->pre('1 < 2'));
        $this->assertSame('<a href="https://x.test/?a=1&amp;b=&quot;">l</a>', $fmt->link('l', 'https://x.test/?a=1&b="'));
    }

    #[Test]
    public function markdown_v2_escaping(): void
    {
        $fmt = new Markup(ParseMode::MarkdownV2);

        $this->assertSame('1\.5 \- \(ok\)\! \#tag \\\\', $fmt->text('1.5 - (ok)! #tag \\'));
        $this->assertSame('*a\_b*', $fmt->bold('a_b'));
        $this->assertSame('`a\`b_c`', $fmt->code('a`b_c'));
        $this->assertSame("```json\n{\"a\": \"\\\\\"}\n```", $fmt->pre('{"a": "\\"}', 'json'));
    }

    #[Test]
    public function invalid_utf8_is_scrubbed(): void
    {
        $this->assertTrue(mb_check_encoding((new Markup)->text("bad \xC3\x28 byte"), 'UTF-8'));
    }

    #[Test]
    public function tokens_are_masked(): void
    {
        $this->assertSame(
            'GET https://api.telegram.org/bot***/sendMessage failed',
            FailureReporter::mask('GET https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage failed'),
        );
    }
}

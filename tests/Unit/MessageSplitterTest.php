<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Xden\TelegramLogger\Formatting\Length;
use Xden\TelegramLogger\Formatting\Markup;
use Xden\TelegramLogger\Formatting\MessageSplitter;
use Xden\TelegramLogger\Formatting\ParseMode;

final class MessageSplitterTest extends TestCase
{
    private MessageSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new MessageSplitter;
    }

    #[Test]
    public function short_text_is_returned_as_is(): void
    {
        $this->assertSame(['<b>hi</b>'], $this->splitter->split('<b>hi</b>', ParseMode::Html));
    }

    #[Test]
    public function html_tags_are_closed_and_reopened_across_chunks(): void
    {
        $text = '<b>Title</b>'."\n".'<pre><code class="language-json">'.str_repeat("line &amp; more\n", 30).'</code></pre>';

        $chunks = $this->splitter->split($text, ParseMode::Html, 100, 50);

        $this->assertGreaterThan(2, count($chunks));

        foreach ($chunks as $i => $chunk) {
            $this->assertLessThanOrEqual(100, Length::of($chunk), "chunk {$i} too long");
            $this->assertSame(substr_count($chunk, '<pre>'), substr_count($chunk, '</pre>'));
            $this->assertSame(substr_count($chunk, '<code'), substr_count($chunk, '</code>'));
            $this->assertDoesNotMatchRegularExpression('/&[a-z]*$|^[a-z]*;/', strip_tags($chunk));
        }

        $this->assertStringStartsWith('<pre><code class="language-json">', $chunks[1]);
    }

    #[Test]
    public function markdown_code_blocks_and_escapes_are_preserved(): void
    {
        $md = new Markup(ParseMode::MarkdownV2);
        $text = $md->bold('Error.').' '.$md->text(str_repeat('a.b ', 30))."\n".$md->pre(str_repeat("{\"k\": \"v`\"}\n", 20), 'json');

        $chunks = $this->splitter->split($text, ParseMode::MarkdownV2, 120, 50);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(120, Length::of($chunk));
            $this->assertSame(0, substr_count($chunk, '```') % 2, $chunk);
            $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)\\\\$/', $chunk, 'chunk ends with a dangling escape');
        }

        $this->assertStringStartsWith('```json', $chunks[count($chunks) - 1]);
    }

    #[Test]
    public function length_is_measured_in_utf16_units(): void
    {
        $this->assertSame(2, Length::of('😀'));
        $this->assertSame(3, Length::of('ab😀') - 1);

        $chunks = $this->splitter->split(str_repeat('😀', 100), ParseMode::Html, 64, 10);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(64, Length::of($chunk));
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }

        $this->assertSame(str_repeat('😀', 100), implode('', $chunks));
    }

    #[Test]
    public function the_last_allowed_chunk_gets_a_truncation_marker(): void
    {
        $chunks = $this->splitter->split('<i>'.str_repeat('word ', 200).'</i>', ParseMode::Html, 100, 2);

        $this->assertCount(2, $chunks);
        $this->assertStringEndsWith("</i>\n<i>… truncated</i>", $chunks[1]);
        $this->assertLessThanOrEqual(100, Length::of($chunks[1]));
    }
}

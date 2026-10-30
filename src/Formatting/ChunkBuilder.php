<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Formatting;

/**
 * Packs markup tokens into messages of limited length. Entities that are
 * open at a boundary are closed at the end of a chunk and reopened at the
 * start of the next one, so every chunk is valid markup on its own.
 *
 * @internal
 */
final class ChunkBuilder
{
    /** @var list<string> */
    private array $chunks = [];

    /** @var list<array{name: string, open: string, close: string}> */
    private array $stack = [];

    private string $buffer = '';

    private int $length = 0;

    private int $closingLength = 0;

    private bool $hasContent = false;

    public function __construct(private readonly int $limit) {}

    public function open(string $name, string $open, string $close): void
    {
        if (Length::of($open) + Length::of($close) > $this->room() && $this->hasContent) {
            $this->flush();
        }

        $this->append($open);
        $this->stack[] = ['name' => $name, 'open' => $open, 'close' => $close];
        $this->closingLength += Length::of($close);
    }

    public function close(string $name): void
    {
        $index = null;

        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]['name'] === $name) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return; // stray closing tag, dropping it keeps the markup valid
        }

        while (count($this->stack) > $index) {
            $entity = array_pop($this->stack);
            $this->closingLength -= Length::of($entity['close']);
            $this->append($entity['close']);
        }
    }

    /**
     * An indivisible piece: HTML entity, escape sequence, link.
     */
    public function atom(string $atom): void
    {
        if (Length::of($atom) > $this->room() && $this->hasContent) {
            $this->flush();
        }

        $this->append($atom, true);
    }

    public function text(string $text): void
    {
        while ($text !== '') {
            $room = $this->room();

            if ($room <= 0) {
                $this->hasContent ? $this->flush() : $this->reset();

                continue;
            }

            if (Length::of($text) <= $room) {
                $this->append($text, true);

                return;
            }

            $piece = $this->cut($text, $room);

            if ($piece === '') {
                if ($this->hasContent) {
                    $this->flush();

                    continue;
                }

                $piece = mb_substr($text, 0, 1, 'UTF-8');
            }

            $this->append($piece, true);
            $text = substr($text, strlen($piece));
            $this->flush();
        }
    }

    /**
     * @return list<string>
     */
    public function finish(): array
    {
        if ($this->hasContent) {
            $this->chunks[] = $this->buffer.$this->closingTags();
        }

        $chunks = $this->chunks;
        $this->chunks = [];
        $this->reset();

        return $chunks;
    }

    private function flush(): void
    {
        if ($this->hasContent) {
            $this->chunks[] = rtrim($this->buffer).$this->closingTags();
        }

        $this->buffer = implode('', array_column($this->stack, 'open'));
        $this->length = Length::of($this->buffer);
        $this->hasContent = false;

        if ($this->length + $this->closingLength >= $this->limit) {
            $this->reset();
        }
    }

    private function reset(): void
    {
        $this->stack = [];
        $this->buffer = '';
        $this->length = 0;
        $this->closingLength = 0;
        $this->hasContent = false;
    }

    private function room(): int
    {
        return $this->limit - $this->length - $this->closingLength;
    }

    private function append(string $text, bool $content = false): void
    {
        $this->buffer .= $text;
        $this->length += Length::of($text);

        if ($content && trim($text) !== '') {
            $this->hasContent = true;
        }
    }

    private function closingTags(): string
    {
        return implode('', array_reverse(array_column($this->stack, 'close')));
    }

    /**
     * Prefix that fits into $room, preferably ending at a line break or space.
     */
    private function cut(string $text, int $room): string
    {
        $piece = Length::prefix($text, $room);
        $half = intdiv(mb_strlen($piece, 'UTF-8'), 2);

        foreach (["\n", ' '] as $separator) {
            $position = mb_strrpos($piece, $separator, 0, 'UTF-8');

            if ($position !== false && $position >= $half) {
                return mb_substr($piece, 0, $position + 1, 'UTF-8');
            }
        }

        return $piece;
    }
}

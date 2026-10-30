<?php

declare(strict_types=1);

namespace Xden\TelegramLogger\Formatting;

use Monolog\Formatter\NormalizerFormatter;

/**
 * @internal
 */
final class ContextNormalizer extends NormalizerFormatter
{
    public function __construct()
    {
        parent::__construct('Y-m-d H:i:s');
        $this->setMaxNormalizeDepth(5);
        $this->setMaxNormalizeItemCount(100);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function encode(array $data, bool $pretty = true): ?string
    {
        if ($data === []) {
            return null;
        }

        $json = json_encode(
            $this->normalize($data),
            ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return $json === false ? null : $json;
    }
}

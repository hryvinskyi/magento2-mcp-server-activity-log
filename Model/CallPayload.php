<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Model;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;

/**
 * A call's arguments or result as the journal keeps them: masked first, written as JSON, cut to a size
 *
 * Secrets are masked by the activity log's sanitizer before anything is written, whatever the ability: a password in
 * the arguments of an account creation, a token in a result. A value larger than the size kept is cut on a character
 * boundary and said to be cut.
 */
class CallPayload
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * @param SanitizerInterface $sanitizer
     * @param int $maxBytes The most a value keeps
     */
    public function __construct(
        private readonly SanitizerInterface $sanitizer,
        private readonly int $maxBytes = 262144
    ) {
    }

    /**
     * The value as kept, and whether it was cut; null when there is no value
     *
     * @param mixed $value
     * @return array{text: string|null, truncated: bool}
     */
    public function of(mixed $value): array
    {
        if ($value === null) {
            return ['text' => null, 'truncated' => false];
        }

        $json = json_encode($this->sanitizer->sanitize($this->plain($value)), self::JSON_FLAGS);
        $text = $json === false ? '' : $json;
        if (strlen($text) <= $this->maxBytes) {
            return ['text' => $text, 'truncated' => false];
        }

        return ['text' => mb_strcut($text, 0, $this->maxBytes, 'UTF-8'), 'truncated' => true];
    }

    /**
     * The value with every object turned into the data it holds, so the sanitizer reaches its fields
     *
     * @param mixed $value
     * @return mixed
     */
    private function plain(mixed $value): mixed
    {
        $decoded = json_decode((string) json_encode($value, self::JSON_FLAGS), true);

        return $decoded ?? $value;
    }
}

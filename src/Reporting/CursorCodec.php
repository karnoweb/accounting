<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting;

use Karnoweb\Accounting\Exceptions\InvalidReportFilterException;

/**
 * URL-safe JSON cursor for keyset pagination. Keys must be a deterministic
 * composite that uniquely identifies a report row.
 */
final class CursorCodec
{
    /**
     * @param  array<string, int|string|null>  $keys
     */
    public static function encode(array $keys): string
    {
        $json = json_encode($keys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidReportFilterException('Unable to encode report cursor.');
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function decode(string $cursor): array
    {
        $padding = strlen($cursor) % 4;
        if ($padding > 0) {
            $cursor .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($json === false) {
            throw new InvalidReportFilterException('Invalid report cursor.');
        }

        $keys = json_decode($json, true);
        if (! is_array($keys)) {
            throw new InvalidReportFilterException('Invalid report cursor.');
        }

        return $keys;
    }
}

<?php

namespace App\Services\Machine;

/**
 * Business plans batch 3: the eight selectable regions for the
 * dedicated-VPS plan. The chosen region is snapshotted onto the order at
 * checkout and can never be edited afterwards (a region switch after an
 * SLA timeout is a fulfilment action, not an order edit).
 */
final class MachineRegionCatalog
{
    /** @var array<string, string> code => display name */
    public const REGIONS = [
        'KR' => '韩国',
        'JP' => '日本',
        'SG' => '新加坡',
        'US' => '美国',
        'DE' => '德国',
        'UK' => '英国',
        'TW' => '台湾',
        'HK' => '香港',
    ];

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::REGIONS[strtoupper($code)]);
    }

    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));
        return isset(self::REGIONS[$code]) ? $code : null;
    }

    public static function name(?string $code): ?string
    {
        $code = self::normalize($code);
        return $code ? self::REGIONS[$code] : null;
    }

    /** @return array<int, array{code: string, name: string}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::REGIONS as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }
        return $out;
    }
}

<?php

namespace App\Services\Enterprise;

use App\Models\EnterprisePurpose;

/**
 * SB-ENT-INV-01 model A (D06): the canonical enterprise SKU catalog.
 *
 * Region-first: the customer picks ONE region (JP/HK) and ONE usage
 * (TikTok / shopping / trade / general); the pair composes exactly one
 * SKU code "<REGION>_<USAGE>". Delivery for a SKU is that SKU's own kit
 * (primary + backup lines) plus the shared VIP lines — a customer never
 * holds assignments for multiple SKUs at once.
 *
 * Legacy compatibility: batch 1 (pre-model-A) seeded four purpose codes —
 * JP_TIKTOK, JP_SHOPPING, HK_TRADE, HK_GENERAL. All four survive verbatim
 * in the model-A catalog (identity mapping, see LEGACY_CODE_MAP), so
 * orders, intents and kits recorded against them keep resolving to the
 * same SKU and renewals keep finding the customer's original kit. No
 * historical row needs a data rewrite; resolution goes through
 * normalizeCode().
 */
class EnterpriseSkuCatalog
{
    public const REGION_JP = 'JP';
    public const REGION_HK = 'HK';

    public const USAGE_TIKTOK = 'TIKTOK';
    public const USAGE_SHOPPING = 'SHOPPING';
    public const USAGE_TRADE = 'TRADE';
    public const USAGE_GENERAL = 'GENERAL';

    /** Region code => display name. */
    public const REGIONS = [
        self::REGION_JP => '日本',
        self::REGION_HK => '香港',
    ];

    /** Usage code => display name (matches the plan-page wording). */
    public const USAGES = [
        self::USAGE_TIKTOK => 'TikTok用',
        self::USAGE_SHOPPING => '购物拍卖',
        self::USAGE_TRADE => '外贸',
        self::USAGE_GENERAL => '通用',
    ];

    /**
     * Batch-1 (pre-model-A) purpose codes and the model-A SKU each one
     * resolves to. All four mappings are identities: the legacy codes were
     * already "<REGION>_<USAGE>" shaped, so they carry over unchanged and
     * historical entitlements are never silently reduced or re-pointed.
     */
    public const LEGACY_CODE_MAP = [
        'JP_TIKTOK' => 'JP_TIKTOK',
        'JP_SHOPPING' => 'JP_SHOPPING',
        'HK_TRADE' => 'HK_TRADE',
        'HK_GENERAL' => 'HK_GENERAL',
    ];

    /**
     * The 8 SKUs in display order (region-major, then usage).
     *
     * @return array<int, array{code: string, region: string, usage: string, name: string, protocol: string, sort: int}>
     */
    public static function skus(): array
    {
        $skus = [];
        $sort = 0;
        foreach (self::REGIONS as $region => $regionName) {
            foreach (self::USAGES as $usage => $usageName) {
                $skus[] = [
                    'code' => self::compose($region, $usage),
                    'region' => $region,
                    'usage' => $usage,
                    'name' => $regionName . '-' . $usageName,
                    // Blueprint presumption: trade lines are SW1, the rest Reality.
                    'protocol' => $usage === self::USAGE_TRADE
                        ? EnterprisePurpose::PROTOCOL_SW1
                        : EnterprisePurpose::PROTOCOL_REALITY,
                    'sort' => ++$sort,
                ];
            }
        }
        return $skus;
    }

    /** @return array<int, string> the 8 canonical SKU codes */
    public static function codes(): array
    {
        return array_map(fn(array $sku) => $sku['code'], self::skus());
    }

    /** Compose a SKU code from a region + usage pair; null when either is unknown. */
    public static function compose(?string $region, ?string $usage): ?string
    {
        $region = strtoupper(trim((string) $region));
        $usage = strtoupper(trim((string) $usage));
        if (!isset(self::REGIONS[$region]) || !isset(self::USAGES[$usage])) {
            return null;
        }
        return $region . '_' . $usage;
    }

    /**
     * Split a SKU code into its region + usage parts.
     *
     * @return array{region: string, usage: string}|null
     */
    public static function decompose(?string $code): ?array
    {
        $sku = self::find($code);
        return $sku ? ['region' => $sku['region'], 'usage' => $sku['usage']] : null;
    }

    /**
     * Resolve any stored or submitted purpose code to its canonical
     * model-A SKU code: trims, upper-cases and applies LEGACY_CODE_MAP.
     * Returns null for codes that are not enterprise SKUs at all.
     */
    public static function normalizeCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        $code = self::LEGACY_CODE_MAP[$code] ?? $code;
        return in_array($code, self::codes(), true) ? $code : null;
    }

    /** The catalog entry for a (possibly legacy / lower-case) code. */
    public static function find(?string $code): ?array
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === null) {
            return null;
        }
        foreach (self::skus() as $sku) {
            if ($sku['code'] === $normalized) {
                return $sku;
            }
        }
        return null;
    }

    /**
     * Two-level structure for the purchase page: regions, each with its
     * usages and the single SKU code each combination submits.
     *
     * @return array<int, array{code: string, name: string, usages: array<int, array{code: string, usage: string, name: string, protocol: string}>}>
     */
    public static function twoLevel(): array
    {
        $levels = [];
        foreach (self::REGIONS as $region => $regionName) {
            $usages = [];
            foreach (self::skus() as $sku) {
                if ($sku['region'] !== $region) {
                    continue;
                }
                $usages[] = [
                    'code' => $sku['code'],
                    'usage' => $sku['usage'],
                    'name' => self::USAGES[$sku['usage']],
                    'protocol' => $sku['protocol'],
                ];
            }
            $levels[] = ['code' => $region, 'name' => $regionName, 'usages' => $usages];
        }
        return $levels;
    }
}

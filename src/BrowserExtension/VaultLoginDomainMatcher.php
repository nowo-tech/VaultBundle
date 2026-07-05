<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use function is_string;
use function parse_url;
use function preg_replace;
use function str_ends_with;
use function strtolower;
use function substr_count;
use function trim;

use const PHP_URL_HOST;

/**
 * Matches vault login websites against the current page host (subdomain-aware, most specific first).
 */
final class VaultLoginDomainMatcher
{
    /**
     * @param list<string> $websites
     */
    public function bestMatchScore(string $currentHost, array $websites): ?int
    {
        $current = self::normalizeHost($currentHost);
        if ($current === '') {
            return null;
        }

        $best = null;
        foreach ($websites as $website) {
            $stored = self::extractHost((string) $website);
            if ($stored === '') {
                continue;
            }

            $score = self::matchScore($current, $stored);
            if ($score !== null && ($best === null || $score > $best)) {
                $best = $score;
            }
        }

        return $best;
    }

    public static function extractHost(string $website): string
    {
        $website = trim($website);
        if ($website === '') {
            return '';
        }

        if (!str_contains($website, '://')) {
            $website = 'https://' . $website;
        }

        $host = parse_url($website, PHP_URL_HOST);

        return self::normalizeHost(is_string($host) ? $host : '');
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * Higher score = more specific match (exact beats parent-domain match).
     */
    public static function matchScore(string $currentHost, string $storedHost): ?int
    {
        $current = self::normalizeHost($currentHost);
        $stored  = self::normalizeHost($storedHost);

        if ($current === '' || $stored === '') {
            return null;
        }

        if ($current === $stored) {
            return 1000 + self::labelCount($stored);
        }

        if (str_ends_with($current, '.' . $stored)) {
            return self::labelCount($stored);
        }

        return null;
    }

    private static function labelCount(string $host): int
    {
        return substr_count($host, '.') + 1;
    }
}

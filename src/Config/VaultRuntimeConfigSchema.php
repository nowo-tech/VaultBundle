<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Config;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Defines which nowo_vault keys may be stored in the database when config_storage is enabled.
 *
 * Bootstrap keys (YAML/env only — required before the settings table can be read):
 * - user_class, encryption_key (env fallback), table_prefix, database, config_storage
 * - security.access_checker (service id)
 * - routes, templates, security roles, route_prefix, dashboard_route, firewall
 *
 * Database settings (stored in vault_settings; merged over YAML baseline at runtime):
 * - encryption_key — dedicated encrypted column (DoctrineEncryptBundle)
 * - max_attachment_bytes — config_values JSON
 * - password_field.level — config_values JSON (level only)
 */
final class VaultRuntimeConfigSchema
{
    /** @var list<string> */
    public const ALLOWED_ROOT_KEYS = [
        'encryption_key',
        'max_attachment_bytes',
        'password_field',
    ];

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function filter(array $values): array
    {
        $filtered = [];
        foreach (self::ALLOWED_ROOT_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $filtered[$key] = $values[$key];
            }
        }

        if (isset($filtered['password_field']) && is_array($filtered['password_field'])) {
            $level = $filtered['password_field']['level'] ?? null;
            if (is_string($level) && $level !== '') {
                $filtered['password_field'] = ['level' => $level];
            } else {
                unset($filtered['password_field']);
            }
        }

        return $filtered;
    }
}

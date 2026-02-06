<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RobbieConfigService
{
    public const GLOBAL_KEY = 'robbie.config.global';
    public const ROLE_KEY_PREFIX = 'robbie.config.role.';

    private const DEFAULT_CONFIG = [
        'model' => 'gpt-4o',
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'tools' => [
            'enabled' => true,
            'allow' => [],
            'deny' => [],
        ],
        'features' => [
            'voice' => [
                'enabled' => false,
            ],
            'media_links' => [
                'enabled' => false,
            ],
        ],
        'system_prompt' => null,
    ];

    public function getDefaultConfig(): array
    {
        return self::DEFAULT_CONFIG;
    }

    public function getGlobalConfig(): array
    {
        return $this->getConfigByKey(self::GLOBAL_KEY) ?? [];
    }

    public function getRoleConfig(string $role): array
    {
        return $this->getConfigByKey($this->roleKey($role)) ?? [];
    }

    public function getMergedConfigForRole(?string $role): array
    {
        $globalConfig = $this->getGlobalConfig();
        $resolvedRole = $role ? $this->resolveRole($role) : null;
        $roleConfig = $resolvedRole ? $this->getRoleConfig($resolvedRole) : [];

        return $this->mergeConfig(self::DEFAULT_CONFIG, $globalConfig, $roleConfig);
    }

    public function saveGlobalConfig(array $config, ?string $description = null): void
    {
        $this->saveConfig(self::GLOBAL_KEY, $config, $description ?? 'Robbie AI global configuration');
    }

    public function saveRoleConfig(string $role, array $config, ?string $description = null): void
    {
        $this->saveConfig(
            $this->roleKey($role),
            $config,
            $description ?? "Robbie AI role configuration for {$role}"
        );
    }

    public function getRoleConfigs(): array
    {
        $configs = [];
        foreach ($this->getKnownRoles() as $role) {
            $configs[$role] = $this->getRoleConfig($role);
        }

        return $configs;
    }

    public function getRoleKeys(): array
    {
        $keys = [];
        foreach ($this->getKnownRoles() as $role) {
            $keys[$role] = $this->roleKey($role);
        }

        return $keys;
    }

    public function getRoleKey(string $role): string
    {
        return $this->roleKey($role);
    }

    public function getKnownRoles(): array
    {
        return [
            'superadmin',
            'admin',
            'editing_manager',
            'editor',
            'salesRep',
            'photographer',
            'client',
        ];
    }

    public function resolveRole(string $role): ?string
    {
        $normalized = $this->normalizeRole($role);
        foreach ($this->getKnownRoles() as $knownRole) {
            if ($this->normalizeRole($knownRole) === $normalized) {
                return $knownRole;
            }
        }

        return null;
    }

    private function getConfigByKey(string $key): ?array
    {
        $setting = DB::table('settings')->where('key', $key)->first();

        if (!$setting) {
            return null;
        }

        return $this->parseValue($setting->value, $setting->type ?? 'json');
    }

    private function parseValue(?string $value, ?string $type): array
    {
        if ($type === 'json' || $type === null) {
            $decoded = json_decode($value ?? '[]', true);
            return is_array($decoded) ? $decoded : [];
        }

        if ($type === 'boolean') {
            return ['value' => filter_var($value, FILTER_VALIDATE_BOOLEAN)];
        }

        if ($type === 'integer') {
            return ['value' => (int) $value];
        }

        return ['value' => $value];
    }

    private function saveConfig(string $key, array $config, ?string $description = null): void
    {
        $data = [
            'value' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'type' => 'json',
            'description' => $description,
        ];

        // Check if timestamps columns exist before including them
        if (Schema::hasColumn('settings', 'updated_at')) {
            $data['updated_at'] = now();
        }

        $existing = DB::table('settings')->where('key', $key)->first();
        
        if ($existing) {
            DB::table('settings')->where('key', $key)->update($data);
        } else {
            $data['key'] = $key;
            if (Schema::hasColumn('settings', 'created_at')) {
                $data['created_at'] = now();
            }
            DB::table('settings')->insert($data);
        }
    }

    private function roleKey(string $role): string
    {
        return self::ROLE_KEY_PREFIX . $role;
    }

    private function mergeConfig(array ...$configs): array
    {
        $merged = [];
        foreach ($configs as $config) {
            $merged = array_replace_recursive($merged, $config);
        }

        return $merged;
    }

    private function normalizeRole(string $role): string
    {
        return strtolower(str_replace(['_', '-'], '', $role));
    }
}

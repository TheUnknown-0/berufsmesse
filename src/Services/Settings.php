<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Laufzeit-Einstellungen aus der settings-Tabelle.
 * school_id NULL = globale Einstellung (z. B. Seitenpasswort).
 *
 * Zeiträume/Messedatum liegen bewusst NICHT hier, sondern in messe_editions.
 */
final class Settings
{
    /** @var array<string, array<string, string|null>> Cache: schoolKey => key => value */
    private array $cache = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, ?int $schoolId = null, ?string $default = null): ?string
    {
        $all = $this->allFor($schoolId);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function getBool(string $key, ?int $schoolId = null, bool $default = false): bool
    {
        $value = $this->get($key, $schoolId);

        return $value === null ? $default : $value === '1';
    }

    public function getInt(string $key, ?int $schoolId = null, int $default = 0): int
    {
        $value = $this->get($key, $schoolId);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public function set(string $key, ?string $value, ?int $schoolId = null): void
    {
        $this->db->run(
            'INSERT INTO settings (school_id, setting_key, setting_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$schoolId, $key, $value],
        );
        unset($this->cache[$this->cacheKey($schoolId)]);
    }

    public function delete(string $key, ?int $schoolId = null): void
    {
        $this->db->run(
            'DELETE FROM settings WHERE setting_key = ? AND school_key = ?',
            [$key, $schoolId ?? 0],
        );
        unset($this->cache[$this->cacheKey($schoolId)]);
    }

    /** @return array<string, string|null> */
    public function allFor(?int $schoolId): array
    {
        $cacheKey = $this->cacheKey($schoolId);
        if (!isset($this->cache[$cacheKey])) {
            $rows = $this->db->fetchAll(
                'SELECT setting_key, setting_value FROM settings WHERE school_key = ?',
                [$schoolId ?? 0],
            );
            $this->cache[$cacheKey] = array_column($rows, 'setting_value', 'setting_key');
        }

        return $this->cache[$cacheKey];
    }

    private function cacheKey(?int $schoolId): string
    {
        return (string) ($schoolId ?? 0);
    }
}

<?php

final class SystemConfigService
{
    public static function getString(PDO $pdo, string $key, string $default = ''): string
    {
        try {
            $stmt = $pdo->prepare('SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return ($value === false || $value === null || $value === '') ? $default : (string)$value;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function getInt(PDO $pdo, string $key, int $default = 0): int
    {
        $value = self::getString($pdo, $key, (string)$default);
        return is_numeric($value) ? (int)$value : $default;
    }

    public static function getFloat(PDO $pdo, string $key, float $default = 0.0): float
    {
        $value = self::getString($pdo, $key, (string)$default);
        return is_numeric($value) ? (float)$value : $default;
    }

    public static function getBool(PDO $pdo, string $key, bool $default = false): bool
    {
        $value = self::getString($pdo, $key, $default ? '1' : '0');
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function upsert(PDO $pdo, string $key, string $value, string $description = ''): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                config_value = VALUES(config_value),
                description = CASE
                    WHEN VALUES(description) <> '' THEN VALUES(description)
                    ELSE description
                END
        ");
        $stmt->execute([$key, $value, $description]);
    }

    public static function seedDefaults(PDO $pdo, array $definitions): void
    {
        foreach ($definitions as $definition) {
            self::upsertIfMissing(
                $pdo,
                (string)$definition['key'],
                (string)$definition['default'],
                (string)($definition['description'] ?? '')
            );
        }
    }

    public static function upsertIfMissing(PDO $pdo, string $key, string $value, string $description = ''): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE config_value = config_value
        ");
        $stmt->execute([$key, $value, $description]);
    }
}

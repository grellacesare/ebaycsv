<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Requirements
{
    public static function audit(string $dataDir): array
    {
        $json = false;
        try {
            if (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
                $db = new \PDO('sqlite::memory:');
                $json = (int) $db->query("SELECT json_valid('[]')")->fetchColumn() === 1;
            }
        } catch (\Throwable) {}
        $checks = [
            ['label' => 'PHP 8.1 o successivo, 64 bit', 'ok' => PHP_VERSION_ID >= 80100 && PHP_INT_SIZE === 8,
                'help' => 'Seleziona PHP 8.3 o successivo nelle impostazioni del dominio.'],
            ['label' => 'Archivio SQLite con funzioni JSON', 'ok' => $json,
                'help' => 'Abilita PDO SQLite per la versione PHP del dominio.'],
            ['label' => 'Testo Unicode e codifiche CSV', 'ok' => extension_loaded('mbstring') && extension_loaded('iconv'),
                'help' => 'Abilita mbstring e iconv nelle estensioni PHP.'],
            ['label' => 'Archivio privato scrivibile', 'ok' => is_dir($dataDir) && is_writable($dataDir),
                'help' => 'Crea la cartella csvstudio-data accanto al webroot, fuori da public_html e private_html, con permessi di scrittura per PHP.'],
        ];
        return [
            'ready' => !in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
            'php_version' => PHP_VERSION,
            'upload_limit' => self::bytes((string) ini_get('upload_max_filesize')),
            'request_limit' => self::bytes((string) ini_get('post_max_size')),
        ];
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') { return PHP_INT_MAX; }
        $number = (int) $value;
        $multiplier = match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        return $number > intdiv(PHP_INT_MAX, $multiplier) ? PHP_INT_MAX : $number * $multiplier;
    }
}

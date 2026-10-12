<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Support
{
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function diagnostic(string $level, string $code, string $message, array $extra = []): array
    {
        return array_replace([
            'level' => $level, 'code' => $code, 'message' => $message, 'hint' => '',
            'product_id' => null, 'listing_id' => null, 'source' => null,
            'field' => null, 'value' => null,
        ], $extra);
    }

    public static function resources(): string
    {
        $packaged = dirname(__DIR__) . '/resources';
        if (is_dir($packaged)) { return $packaged; }
        return dirname(__DIR__, 3) . '/backend/src/motorworld_csv/resources';
    }
}

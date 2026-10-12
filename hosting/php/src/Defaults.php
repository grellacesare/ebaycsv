<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Defaults
{
    public static function profile(string $destination): array
    {
        if ($destination === 'prestashop') {
            return ['destination' => 'prestashop', 'tax_rule_id' => null, 'reference_prefix' => '', 'free_shipping_confirmed' => false];
        }
        if ($destination !== 'ebay') {
            throw new AppError('wrong_destination', 'Scegli eBay o PrestaShop.');
        }
        return ['destination' => 'ebay', 'condition_id' => '1000', 'location' => '92019',
            'shipping_profile' => 'CORRIERE PREDEFINITA MOTOR WORLD', 'return_profile' => 'RESTITUZIONE PREDEFINITA MW 60 GIORNI',
            'payment_profile' => 'eBay Managed Payments 1818820adfa4000', 'return_days' => 60, 'return_shipping_paid_by' => 'Buyer',
            'store_category' => '', 'logo_url' => '', 'manufacturer' => new \stdClass(),
            'shipping_text' => 'Spedizione gratuita. Evadiamo in 24/48h (lavorativi). Corrieri: GLS / BRT / Poste, secondo la destinazione.',
            'free_shipping_confirmed' => false];
    }

    public static function platform(string $destination): array { return self::profile($destination); }

    public static function product(array $values): array
    {
        return array_replace(['id' => Support::uuid(), 'supplier_id' => '', 'code' => '', 'name' => '', 'brand' => '', 'family' => '',
            'department' => '', 'cost' => null, 'ean' => null, 'image_urls' => [], 'available' => null, 'packaging' => [],
            'extra' => [], 'sources' => [], 'diagnostics' => []], $values);
    }

    public static function compatibility(array $values): array
    {
        return array_replace(['id' => Support::uuid(), 'product_id' => '', 'make' => '', 'model' => '', 'displacement' => '',
            'year_from' => null, 'year_to' => null, 'vehicle_type' => 'unspecified'], $values);
    }

    public static function priceSettings(array $values): array
    {
        return array_replace(['commission_fixed' => '0', 'vat_rate' => '.22'], $values);
    }

    public static function overrides(array $values): array
    {
        return array_replace(['title' => null, 'reference' => null, 'gross_price' => null, 'category_id' => null], $values);
    }

    public static function productId(string $supplier, string $code): string
    {
        $namespace = hex2bin('6ba7b8119dad11d180b400c04fd430c8');
        $digest = sha1($namespace . $supplier . "\0" . $code, true);
        $digest[6] = chr((ord($digest[6]) & 0x0f) | 0x50);
        $digest[8] = chr((ord($digest[8]) & 0x3f) | 0x80);
        $hex = bin2hex(substr($digest, 0, 16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

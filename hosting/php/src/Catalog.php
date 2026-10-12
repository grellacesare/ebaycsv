<?php
declare(strict_types=1);

namespace MotorWorld\Hosting;

final class Catalog
{
    public function __construct(private readonly string $resources)
    {
    }

    public function load(): array
    {
        $ebay = array_map(
            static fn(array $entry): array => array_replace(['id' => '', 'path' => '', 'context' => 'unknown'], $entry),
            $this->read('ebay_categories.json'),
        );
        $prestashop = array_map(
            static fn(array $entry): array => array_replace([
                'id' => '', 'name' => '', 'description' => '', 'position' => 0, 'shown' => true,
            ], $entry),
            $this->read('prestashop_categories.json'),
        );

        return [
            'ebay' => $ebay,
            'prestashop' => $prestashop,
            'seed_rules' => $this->read('category_rules.json'),
            'version' => $this->read('resource_manifest.json')['builder_sha256'],
        ];
    }

    public static function normalizeKey(string $value): string
    {
        $folded = mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
        // Symfony's table omits Unicode 14's modifier small q decomposition.
        $folded = str_replace("\u{107A5}", 'q', $folded);
        $normalized = \Normalizer::normalize($folded, \Normalizer::FORM_KD);
        if ($normalized === false) {
            throw new \InvalidArgumentException('Category keys must be valid UTF-8.');
        }
        return preg_replace('/[^a-z0-9]/', '', $normalized);
    }

    public static function resolve(
        array $product,
        ?array $compatibility,
        string $destination,
        array $catalog,
        array $rules,
        ?string $override = null,
    ): array {
        $context = $compatibility !== null
            ? ($compatibility['vehicle_type'] ?? 'unspecified')
            : ($product['extra']['vehicle_type'] ?? 'unspecified');
        $family = self::normalizeKey($product['family'] ?? '');
        $entries = [];
        foreach ($catalog[$destination === 'ebay' ? 'ebay' : 'prestashop'] ?? [] as $entry) {
            $entries[$entry['id']] = $entry;
        }

        $unresolved = static function (string $message, string $code = 'unresolved_category') use ($product): array {
            return [
                'category_ids' => [],
                'method' => 'unresolved',
                'reason' => '',
                'diagnostics' => [Support::diagnostic('error', $code, $message, [
                    'hint' => 'Seleziona una categoria e salva l’associazione per famiglia e contesto.',
                    'product_id' => $product['id'],
                    'field' => 'category',
                ])],
            ];
        };
        $selected = static function (array $ids, string $method) use ($entries, $destination, $context, $product, $unresolved): array {
            $ids = array_values(array_unique($ids, SORT_STRING));
            if (count($ids) !== 1) {
                return $unresolved('Più categorie possibili: occorre una scelta esplicita.', 'ambiguous_category');
            }
            $id = (string) $ids[0];
            if (!isset($entries[$id])) {
                return $unresolved('La categoria scelta non è presente nel repertorio.', 'unknown_category');
            }
            $entry = $entries[$id];
            $entryContext = $entry['context'] ?? 'unknown';
            if ($destination === 'ebay'
                && in_array($context, ['moto', 'scooter'], true)
                && in_array($entryContext, ['moto', 'scooter'], true)
                && $entryContext !== $context) {
                return $unresolved('Categoria incompatibile con il tipo di veicolo della variante.', 'category_context_mismatch');
            }
            $diagnostics = [];
            if ($destination === 'prestashop' && !($entry['shown'] ?? true)) {
                $diagnostics[] = Support::diagnostic('warning', 'hidden_store_category', 'Questa categoria del negozio è nascosta.', [
                    'hint' => 'Verifica che debba essere utilizzata.',
                    'product_id' => $product['id'],
                ]);
            }
            return [
                'category_ids' => [$id],
                'method' => $method,
                'reason' => 'Corrispondenza verificata nel repertorio fornito.',
                'diagnostics' => $diagnostics,
            ];
        };

        // Python treats every nonempty string as an explicit override, including "0".
        if ($override !== null && $override !== '') {
            return $selected([$override], 'explicit');
        }
        $relevant = array_values(array_filter($rules, static fn(array $rule): bool =>
            $rule['supplier_id'] === $product['supplier_id']
            && self::normalizeKey($rule['family']) === $family
            && $rule['destination'] === $destination
        ));
        $scoped = array_values(array_filter($relevant, static fn(array $rule): bool =>
            ($rule['context'] ?? 'unspecified') === $context
        ));
        $savedRules = $scoped ?: array_values(array_filter($relevant, static fn(array $rule): bool =>
            ($rule['context'] ?? 'unspecified') === 'unspecified'
        ));
        if ($savedRules !== []) {
            return $selected(array_column($savedRules, 'category_id'), 'saved_rule');
        }

        if ($destination === 'prestashop') {
            $values = array_filter([
                $family,
                self::normalizeKey($product['department'] ?? ''),
            ], static fn(string $value): bool => $value !== '');
            $ids = [];
            foreach ($catalog['prestashop'] ?? [] as $entry) {
                if (in_array(self::normalizeKey($entry['name']), $values, true)) {
                    $ids[] = $entry['id'];
                }
            }
            return $ids !== [] ? $selected($ids, 'recognized') : $unresolved('Categoria del negozio non associata.');
        }

        $allowed = $context === 'unspecified' ? ['moto', 'scooter', 'shared'] : [$context, 'shared'];
        $ids = [];
        foreach ($catalog['ebay'] ?? [] as $entry) {
            if (in_array($entry['context'] ?? 'unknown', $allowed, true)
                && self::normalizeKey($entry['path']) === $family) {
                $ids[] = $entry['id'];
            }
        }
        if ($ids === [] && in_array($context, ['moto', 'scooter'], true)) {
            foreach ($catalog['ebay'] ?? [] as $entry) {
                $path = explode('>', $entry['path']);
                if (in_array($entry['context'] ?? 'unknown', $allowed, true)
                    && self::normalizeKey($path[count($path) - 1]) === $family) {
                    $ids[] = $entry['id'];
                }
            }
        }
        if ($ids === [] && in_array($context, ['moto', 'scooter'], true)) {
            foreach ($catalog['seed_rules'] ?? [] as $rule) {
                if ($rule['destination'] === 'ebay'
                    && self::normalizeKey($rule['family']) === $family
                    && in_array($rule['context'], $allowed, true)
                    && isset($entries[$rule['category_id']])
                    && in_array($entries[$rule['category_id']]['context'] ?? 'unknown', $allowed, true)) {
                    $ids[] = $rule['category_id'];
                }
            }
        }
        return $ids !== []
            ? $selected($ids, 'recognized')
            : $unresolved('Nessun riconoscimento univoco Moto/Scooter per questa famiglia e contesto.');
    }

    private function read(string $name): array
    {
        $path = rtrim($this->resources, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Category resource unavailable: ' . $name);
        }
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}

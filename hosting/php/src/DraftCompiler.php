<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class DraftCompiler
{
    private Descriptions $descriptions;

    public function __construct(private readonly string $resources)
    {
        $this->descriptions = new Descriptions($resources);
    }

    public function compile(array $product, array $fits, array $priceSettings, array $profile, array $catalog, array $rules, array $overrides): array
    {
        $product = Defaults::product($product);
        $fits = array_map(Defaults::compatibility(...), $fits);
        $profile = array_replace(Defaults::profile($profile['destination']), $profile);
        $priceSettings = Defaults::priceSettings($priceSettings);
        $destination = $profile['destination'];
        $variants = $destination === 'ebay' ? ($fits ?: [null]) : [null];
        $common = array_merge($product['diagnostics'], self::validateProfile($profile), self::validateCompatibilities($fits));
        $drafts = [];
        foreach ($variants as $fit) {
            $id = self::listingIdentity($product, $fit, $destination);
            $override = Defaults::overrides($overrides[$id] ?? ['listing_id' => $id]);
            $title = self::normalizeTitle($override['title'] ?? self::proposeTitle($product, $fit));
            $reference = $override['reference'] ?? ($destination === 'ebay' ? $id : $profile['reference_prefix'] . $product['code']);
            $diagnostics = array_map(static fn(array $item): array => array_replace($item, ['product_id' => $product['id'], 'listing_id' => $id]), $common);
            if ($destination === 'ebay') {
                $diagnostics = array_merge($diagnostics, self::validateTitle($title));
            } else {
                $diagnostics = array_merge($diagnostics, self::validateReference($reference));
                if ($title === '' || mb_strlen($title, 'UTF-8') > 128 || preg_match('/[<>;=#{}]/u', $title)) {
                    $diagnostics[] = Support::diagnostic('error', 'invalid_product_name', 'Nome PrestaShop: massimo 128 caratteri, senza < > ; = # { }.', ['hint' => 'Modifica il nome della bozza.', 'field' => 'title']);
                }
                if (self::truthyString($product['ean']) && (!self::isDigit($product['ean']) || !in_array(mb_strlen($product['ean'], 'UTF-8'), [8, 13], true))) {
                    $diagnostics[] = Support::diagnostic('error', 'invalid_ean', 'EAN PrestaShop non valido: 8 o 13 cifre.', ['hint' => 'Correggi o rimuovi il codice non confermato.', 'field' => 'ean', 'value' => $product['ean']]);
                }
            }
            $commonCategory = $product['extra'][$destination . '_category_id'] ?? null;
            if ($destination === 'ebay' && $commonCategory && $fit !== null) {
                foreach ($catalog['ebay'] as $category) {
                    if ($category['id'] === $commonCategory && in_array($category['context'], ['moto', 'scooter'], true)
                        && in_array($fit['vehicle_type'], ['moto', 'scooter'], true) && $category['context'] !== $fit['vehicle_type']) {
                        $commonCategory = null; break;
                    }
                }
            }
            $decision = Catalog::resolve($product, $fit, $destination, $catalog, $rules, self::truthyString($override['category_id']) ? $override['category_id'] : $commonCategory);
            $diagnostics = array_merge($diagnostics, $decision['diagnostics']);
            $price = null;
            try {
                if ($product['cost'] === null) { throw new AppError('missing_cost', 'Imposta il costo di acquisto IVA esclusa.'); }
                $price = Pricing::calculate($product['cost'], $priceSettings, $override['gross_price']);
                $diagnostics = array_merge($diagnostics, $price['diagnostics']);
            } catch (AppError $error) {
                $diagnostics[] = Support::diagnostic('error', $error->errorCode, $error->getMessage(), ['field' => 'cost']);
            }
            $html = '';
            try {
                $html = $this->descriptions->render_description($product, $fit !== null ? [$fit] : ($destination === 'prestashop' ? $fits : []), $profile, $title, $reference, $priceSettings['quantity']);
            } catch (AppError $error) {
                $diagnostics[] = Support::diagnostic('error', $error->errorCode, $error->getMessage(), ['field' => 'image_urls']);
            }
            $fields = self::snapshotFields($product, $fit, $profile, $this->resources);
            if ($destination === 'ebay') { $fields['VAT%'] = Pricing::multiply($priceSettings['vat_rate'], '100'); }
            $drafts[] = ['id' => $id, 'product_id' => $product['id'], 'compatibility_id' => $fit['id'] ?? null,
                'destination' => $destination, 'reference' => $reference, 'title' => $title, 'category_ids' => $decision['category_ids'],
                'price' => $price, 'quantity' => $priceSettings['quantity'], 'description_html' => $html, 'fields' => $fields,
                'diagnostics' => $diagnostics, 'valid' => !array_filter($diagnostics, static fn(array $d): bool => $d['level'] === 'error'),
                'exported' => false, 'stale' => false];
        }
        return $drafts;
    }

    public static function normalizeTitle(string $title): string
    {
        $title = \Normalizer::normalize($title, \Normalizer::FORM_C);
        if ($title === false) { throw new AppError('invalid_title', 'Il titolo contiene testo UTF-8 non valido.'); }
        return trim(preg_replace('/[\s\p{Z}\x{0085}\x{001c}-\x{001f}]+/u', ' ', $title));
    }

    public static function fitmentLabel(array $row): string
    {
        $row = Defaults::compatibility($row);
        $years = $row['year_from'] !== null || $row['year_to'] !== null ? ($row['year_from'] ?: '…') . '–' . ($row['year_to'] ?: '…') : '';
        return self::normalizeTitle(implode(' ', [$row['make'], $row['model'], $row['displacement'], $years]));
    }

    public static function proposeTitle(array $product, ?array $compatibility): string
    {
        $parts = [$product['name'] ?? ''];
        $brand = $product['brand'] ?? '';
        if ($brand !== '' && !str_contains(self::casefold($product['name'] ?? ''), self::casefold($brand))) { $parts[] = $brand; }
        if ($compatibility !== null) { $parts[] = self::fitmentLabel($compatibility); }
        return self::normalizeTitle(implode(' ', $parts));
    }

    public static function validateTitle(string $title): array
    {
        $title = self::normalizeTitle($title);
        if ($title === '') { return [Support::diagnostic('error', 'missing_title', 'Il titolo è obbligatorio.', ['field' => 'title'])]; }
        $count = mb_strlen($title, 'UTF-8');
        if ($count > 80) { return [Support::diagnostic('error', 'title_too_long', "Titolo di $count caratteri: massimo 80.", ['hint' => 'Abbrevia il testo senza perdere modello o codice.', 'field' => 'title', 'value' => $title])]; }
        if (preg_match('/\p{Cc}/u', $title)) { return [Support::diagnostic('error', 'invalid_title', 'Il titolo contiene caratteri di controllo.', ['field' => 'title'])]; }
        return [];
    }

    public static function validateReference(string $reference): array
    {
        if ($reference === '' || mb_strlen($reference, 'UTF-8') > 64 || preg_match('/[<>;={}\x00-\x1f]/u', $reference)) {
            return [Support::diagnostic('error', 'invalid_reference', 'Riferimento PrestaShop non valido: massimo 64 caratteri, senza < > ; = { }.', ['hint' => 'Imposta un prefisso/riferimento valido; il codice fornitore resta separato.', 'field' => 'reference', 'value' => $reference])];
        }
        return [];
    }

    public static function validateCompatibilities(array $rows): array
    {
        $diagnostics = []; $seen = []; $ids = [];
        foreach ($rows as $row) {
            $row = Defaults::compatibility($row);
            if (self::normalizeTitle($row['model']) === '') { $diagnostics[] = Support::diagnostic('error', 'missing_model', 'Inserisci il modello compatibile.', ['field' => 'model']); }
            if ($row['year_from'] !== null && $row['year_to'] !== null && $row['year_from'] > $row['year_to']) {
                $diagnostics[] = Support::diagnostic('error', 'invalid_years', 'L’anno iniziale supera quello finale.', ['hint' => 'Inverti o correggi gli anni.', 'field' => 'year_from']);
            }
            $key = json_encode([self::casefold(self::normalizeTitle($row['make'])), self::casefold(self::normalizeTitle($row['model'])), self::casefold(self::normalizeTitle($row['displacement'])), $row['year_from'], $row['year_to'], $row['vehicle_type']], JSON_THROW_ON_ERROR);
            if (isset($seen[$key]) || isset($ids[$row['id']])) { $diagnostics[] = Support::diagnostic('error', 'duplicate_compatibility', 'Modello compatibile duplicato.', ['hint' => 'Mantieni una sola riga per la stessa compatibilità.', 'product_id' => $row['product_id']]); }
            $seen[$key] = true; $ids[$row['id']] = true;
        }
        return $diagnostics;
    }

    public static function listingIdentity(array $product, ?array $compatibility, string $destination): string
    {
        $variant = $destination === 'ebay' && $compatibility !== null ? $compatibility['id'] : 'base';
        return ($destination === 'ebay' ? 'MW-' : 'PS-') . substr(hash('sha256', $product['supplier_id'] . "\0" . $product['code'] . "\0" . $variant), 0, 24);
    }

    public static function validateProfile(array $profile): array
    {
        $profile = array_replace(Defaults::profile($profile['destination']), $profile);
        $diagnostics = [];
        if ($profile['destination'] === 'prestashop') {
            if (!self::truthyString($profile['tax_rule_id']) || !self::isDigit($profile['tax_rule_id']) || !self::positiveDecimalId($profile['tax_rule_id'])) {
                $diagnostics[] = Support::diagnostic('error', 'tax_rule_required', 'Imposta l’ID reale del gruppo regole IVA PrestaShop.', ['hint' => 'È un ID del negozio, non la percentuale 22%.', 'field' => 'tax_rule_id']);
            }
        } else {
            foreach (['location', 'shipping_profile', 'return_profile', 'payment_profile'] as $field) {
                if (self::normalizeTitle($profile[$field]) === '') { $diagnostics[] = Support::diagnostic('error', 'missing_platform_field', "Imposta $field nel profilo eBay.", ['field' => $field]); }
            }
            if (!self::isDigit($profile['condition_id'])) { $diagnostics[] = Support::diagnostic('error', 'invalid_condition', 'Usa un ID condizione numerico, ad esempio 1000.', ['field' => 'condition_id']); }
            if (self::truthyString($profile['store_category']) && !self::isDigit($profile['store_category'])) { $diagnostics[] = Support::diagnostic('error', 'invalid_store_category', 'La categoria del negozio eBay deve essere un ID.', ['field' => 'store_category']); }
            if (self::truthyString($profile['logo_url']) && !Descriptions::public_https_url($profile['logo_url'])) { $diagnostics[] = Support::diagnostic('error', 'invalid_logo_url', 'Il logo esportato deve avere un URL pubblico HTTPS.', ['field' => 'logo_url']); }
        }
        if (!$profile['free_shipping_confirmed']) { $diagnostics[] = Support::diagnostic('error', 'shipping_confirmation_required', 'Verifica che il negozio o la policy eBay prevedano spedizione gratuita al cliente.', ['hint' => 'Il prezzo include il costo, ma il CSV non modifica le tariffe del corriere.', 'field' => 'free_shipping_confirmed']); }
        return $diagnostics;
    }

    public static function columns(string $destination, ?string $resources = null): array
    {
        if ($destination === 'ebay') { return json_decode(file_get_contents(($resources ?? Support::resources()) . '/ebay_headers.json'), true, 512, JSON_THROW_ON_ERROR); }
        return ['Active (0/1)', 'Name', 'Categories (x,y,z...)', 'Price tax included', 'Tax rule ID', 'Cost price',
            'Reference #', 'Supplier reference #', 'Supplier', 'Brand', 'Quantity', 'EAN13', 'Summary', 'Description', 'Image URLs (x,y,z...)'];
    }

    public static function snapshotFields(array $product, ?array $compatibility, array $profile, ?string $resources = null): array
    {
        $product = Defaults::product($product);
        $profile = array_replace(Defaults::profile($profile['destination']), $profile);
        if ($profile['destination'] !== 'ebay') {
            return ['Active (0/1)' => '1', 'Supplier reference #' => $product['code'], 'Supplier' => $product['supplier_id'], 'Brand' => $product['brand'],
                'EAN13' => $product['ean'] ?? '', 'Cost price' => $product['cost'] === null ? '' : Pricing::monetary($product['cost']),
                'Image URLs (x,y,z...)' => implode(',', array_map(static fn(string $url): string => str_replace(',', '%2C', $url), $product['image_urls'])),
                'Tax rule ID' => $profile['tax_rule_id'] ?? '', 'Summary' => mb_substr($product['name'], 0, 800, 'UTF-8')];
        }
        $fields = [self::columns('ebay', $resources)[0] => 'Add', 'Condition ID' => $profile['condition_id'], 'Format' => 'FixedPrice', 'Duration' => 'GTC',
            'Location' => $profile['location'], 'Returns accepted option' => 'ReturnsAccepted', 'Returns within option' => 'Days_' . $profile['return_days'],
            'Return shipping cost paid by' => $profile['return_shipping_paid_by'], 'Shipping profile name' => $profile['shipping_profile'],
            'Return profile name' => $profile['return_profile'], 'Payment profile name' => $profile['payment_profile'], 'StoreCategory' => $profile['store_category'],
            'C:Marca' => $product['brand'], 'C:Codice EAN' => $product['ean'] ?? '', 'Item photo URL' => implode('|', $product['image_urls'])];
        $mapping = ['C:Garanzia produttore' => 'warranty', 'C:Unita  di misura' => 'unit', 'C:MPN' => 'mpn', 'C:Codice OEM / Ref' => 'oem',
            'C:Posizione / Lato' => 'posizione', 'C:Colore / Finitura' => 'colore', 'C:Misure / Dimensioni' => 'misure', 'C:Motore / Normativa' => 'motore-norm',
            'C:Omologazione / Certificazioni' => 'omologazione', 'C:Voltaggio' => 'voltaggio', 'C:Peso' => 'peso', 'C:Finitura (extra)' => 'finitura-extra',
            'C:Ricambio moto classica' => 'ricambio-classica', 'C:Inserzione bundle' => 'bundle', "C:Destinazione d'uso" => 'destinazione-uso',
            'C:Altro codice identificativo' => 'altro-codice', 'C:Informazioni Aggiuntive' => 'info-agg'];
        foreach ($mapping as $header => $key) { $fields[$header] = $product['extra'][$key] ?? ''; }
        $manufacturer = (array) $profile['manufacturer'];
        foreach (self::columns('ebay', $resources) as $header) { if (str_starts_with($header, 'Manufacturer ')) { $fields[$header] = $manufacturer[$header] ?? ''; } }
        if ($compatibility !== null) {
            $compatibility = Defaults::compatibility($compatibility);
            $fields = array_replace($fields, ['C:Tipo veicolo' => ['moto' => 'Moto', 'scooter' => 'Scooter', 'other' => 'Altro', 'unspecified' => ''][$compatibility['vehicle_type']],
                'C:Marca veicolo' => $compatibility['make'], 'C:Modello veicolo' => $compatibility['model'], 'C:Cilindrata' => $compatibility['displacement'],
                'C:Anno compatibilità' => $compatibility['year_from'] || $compatibility['year_to'] ? ($compatibility['year_from'] ?: '…') . '–' . ($compatibility['year_to'] ?: '…') : '']);
        }
        return $fields;
    }

    public static function draftToRow(array $draft, array $product, array $profile, ?string $resources = null): array
    {
        if ($draft['price'] === null) { throw new AppError('missing_price', 'Prezzo non calcolato'); }
        $fields = $draft['fields'];
        if ($draft['destination'] === 'ebay') {
            $fields = array_replace($fields, ['Custom label (SKU)' => $draft['reference'], 'Category Id' => $draft['category_ids'][0], 'Title' => $draft['title'],
                'Start Price' => Pricing::monetary($draft['price']['final_gross'], 2), 'Quantity' => (string) $draft['quantity'], 'Description' => $draft['description_html']]);
        } else {
            $fields = array_replace($fields, ['Name' => $draft['title'], 'Reference #' => $draft['reference'], 'Categories (x,y,z...)' => implode(',', $draft['category_ids']),
                'Price tax included' => Pricing::monetary($draft['price']['final_gross'], 2), 'Quantity' => (string) $draft['quantity'], 'Description' => $draft['description_html']]);
        }
        $row = [];
        foreach (self::columns($draft['destination'], $resources) as $header) { $row[$header] = $fields[$header] ?? ''; }
        return $row;
    }

    private static function casefold(string $value): string { return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8'); }
    private static function truthyString(?string $value): bool { return $value !== null && $value !== ''; }
    private static function isDigit(string $value): bool
    {
        // Python Unicode 15 isdigit also includes Numeric_Type=Digit outside Nd.
        return preg_match('/^[\p{Nd}\x{11F50}-\x{11F59}\x{1E4F0}-\x{1E4F9}\x{B2}-\x{B3}\x{B9}\x{1369}-\x{1371}\x{19DA}\x{2070}\x{2074}-\x{2079}\x{2080}-\x{2089}\x{2460}-\x{2468}\x{2474}-\x{247C}\x{2488}-\x{2490}\x{24EA}\x{24F5}-\x{24FD}\x{24FF}\x{2776}-\x{277E}\x{2780}-\x{2788}\x{278A}-\x{2792}\x{10A40}-\x{10A43}\x{10E60}-\x{10E68}\x{11052}-\x{1105A}\x{1F100}-\x{1F10A}]+$/uD', $value) === 1;
    }

    private static function positiveDecimalId(string $value): bool
    {
        // No integer cast: Arabic, full width, and other decimal scripts retain
        // Python's positive-ID behavior, and arbitrarily long IDs cannot overflow.
        return preg_match('/^[\p{Nd}\x{11F50}-\x{11F59}\x{1E4F0}-\x{1E4F9}]+$/uD', $value) === 1
            && preg_match('/[^\x{30}\x{660}\x{6F0}\x{7C0}\x{966}\x{9E6}\x{A66}\x{AE6}\x{B66}\x{BE6}\x{C66}\x{CE6}\x{D66}\x{DE6}\x{E50}\x{ED0}\x{F20}\x{1040}\x{1090}\x{17E0}\x{1810}\x{1946}\x{19D0}\x{1A80}\x{1A90}\x{1B50}\x{1BB0}\x{1C40}\x{1C50}\x{A620}\x{A8D0}\x{A900}\x{A9D0}\x{A9F0}\x{AA50}\x{ABF0}\x{FF10}\x{104A0}\x{10D30}\x{11066}\x{110F0}\x{11136}\x{111D0}\x{112F0}\x{11450}\x{114D0}\x{11650}\x{116C0}\x{11730}\x{118E0}\x{11950}\x{11C50}\x{11D50}\x{11DA0}\x{11F50}\x{16A60}\x{16AC0}\x{16B50}\x{1D7CE}\x{1D7D8}\x{1D7E2}\x{1D7EC}\x{1D7F6}\x{1E140}\x{1E2F0}\x{1E4F0}\x{1E950}\x{1FBF0}]/u', $value) === 1;
    }
}

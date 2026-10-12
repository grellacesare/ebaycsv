<?php
declare(strict_types=1);

namespace MotorWorld\Hosting;

/** Static platform descriptions, using the same trusted template as Python. */
final class Descriptions
{
    private ?array $template = null;
    private ?string $css = null;

    public function __construct(private readonly string $resources) {}

    public function render_description(array $product, array $fitments, array $profile, string $title, string $reference, int $quantity): string
    {
        $images = $product['image_urls'] ?? [];
        foreach ($images as $url) {
            if (!self::public_https_url($url)) {
                throw new AppError('invalid_image_url', 'Correggi le immagini: servono URL pubblici HTTPS.');
            }
        }
        $labels = array_map(self::fitmentLabel(...), $fitments);
        $extra = $product['extra'] ?? [];
        $description = $extra['description'] ?? ($product['name'] ?? '');
        if (($profile['destination'] ?? 'ebay') !== 'ebay') {
            $html = '<h2>'.self::escape($title).'</h2><p>'.self::nl2br($description).'</p>';
            if ($labels !== []) {
                $html .= '<h3>Modelli compatibili</h3><ul>';
                foreach ($labels as $label) $html .= '<li>'.self::escape($label).'</li>';
                $html .= '</ul>';
            }
            return $html;
        }
        if (count($fitments) > 1) {
            throw new AppError('invalid_fitment_scope', 'Ogni descrizione eBay deve corrispondere a una sola compatibilità.');
        }
        if (self::truthy($profile['logo_url'] ?? '') && !self::public_https_url($profile['logo_url'])) {
            throw new AppError('invalid_logo_url', 'Configura un logo pubblico HTTPS.');
        }
        $details = [];
        foreach (['modello','materiale','specifica','oem','mpn','posizione','colore','misure','motore-norm','omologazione','voltaggio','peso','finitura-extra','ricambio-classica','bundle','destinazione-uso','altro-codice','info-agg'] as $key) {
            $details[$key] = $extra[$key] ?? '';
        }
        $condition = $profile['condition_id'] ?? '1000';
        $details += [
            'marca' => $product['brand'] ?? '', 'ean' => $product['ean'] ?? '', 'qty' => (string)$quantity,
            'condizione' => ['1000'=>'Nuovo','3000'=>'Usato'][$condition] ?? $condition,
            'tipo-veicolo'=>'', 'marca-veicolo'=>'', 'modello-veicolo'=>'', 'anno-compat'=>'', 'cilindrata'=>'',
        ];
        if ($fitments !== []) {
            $row = $fitments[0];
            $details['tipo-veicolo'] = ['moto'=>'Moto','scooter'=>'Scooter','other'=>'Altro','unspecified'=>''][$row['vehicle_type'] ?? 'unspecified'];
            $details['marca-veicolo'] = $row['make'] ?? '';
            $details['modello-veicolo'] = $row['model'] ?? '';
            $details['cilindrata'] = $row['displacement'] ?? '';
            if (($row['year_from'] ?? 0) || ($row['year_to'] ?? 0)) {
                $details['anno-compat'] = self::years($row);
            }
        }
        $profile += [
            'logo_url'=>'', 'return_days'=>60,
            'shipping_text'=>'Spedizione gratuita. Evadiamo in 24/48h (lavorativi). Corrieri: GLS / BRT / Poste, secondo la destinazione.',
        ];
        $this->loadTemplate();
        return $this->renderNodes($this->template, [
            'product'=>$product, 'profile'=>$profile, 'title'=>$title, 'reference'=>$reference,
            'details'=>$details, 'image_urls'=>$images, 'description'=>$description,
            'fitment_labels'=>$labels, 'features'=>self::splitlines($extra['features'] ?? ''),
            'box'=>self::splitlines($extra['box'] ?? ''), 'notes'=>$extra['notes'] ?? '', 'css'=>$this->css,
        ]);
    }

    public function render(array $product, array $fitments, array $profile, string $title, string $reference, int $quantity): string
    {
        return $this->render_description($product, $fitments, $profile, $title, $reference, $quantity);
    }

    public static function publicHttpsUrl(string $value): bool
    {
        return self::public_https_url($value);
    }

    /** Match urllib.urlsplit and ipaddress.is_global; this does not fetch URLs. */
    public static function public_https_url(string $value): bool
    {
        $value = str_replace(["\t", "\r", "\n"], '', preg_replace('/^[\x00-\x20]+/', '', $value));
        if (!preg_match('~^([a-zA-Z][a-zA-Z0-9+.-]*):\/\/([^/?#]*)~', $value, $match) || strtolower($match[1]) !== 'https') return false;
        $authority = $match[2];
        if (preg_match('/[^\x00-\x7f]/', $authority)) {
            $normalized = \Normalizer::normalize(str_replace(['@',':','#','?'], '', $authority), \Normalizer::FORM_KC);
            if (preg_match('~[/\?#@:]~', $normalized)) return false;
        }
        $at = strrpos($authority, '@');
        $hostinfo = $authority;
        if ($at !== false) {
            $credentials = explode(':', substr($authority, 0, $at), 2);
            if ($credentials[0] !== '' || ($credentials[1] ?? '') !== '') return false;
            $hostinfo = substr($authority, $at + 1);
        }
        $bracketed = str_contains($hostinfo, '[') || str_contains($hostinfo, ']');
        if ($bracketed) {
            if (!preg_match('/^\[([^\[\]]+)\](?::(.*))?$/D', $hostinfo, $parts)) return false;
            $host = $parts[1]; $port = $parts[2] ?? '';
            if (str_starts_with($host, 'v')) {
                if (!preg_match('/^v[0-9a-fA-F]+\..+$/D', $host)) return false;
            } elseif (self::ipBytes($host) === false || strlen(self::ipBytes($host)) !== 16) {
                return false;
            }
        } else {
            $parts = explode(':', $hostinfo, 2);
            $host = $parts[0]; $port = $parts[1] ?? '';
        }
        if ($port !== '' && (!ctype_digit($port) || ltrim($port, '0') !== '443')) return false;
        $host = strtolower($host);
        if ($host === '' || $host === 'localhost') return false;
        foreach (['.localhost','.local','.internal','.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) return false;
        }
        $packed = self::ipBytes($host);
        return $packed === false ? str_contains($host, '.') : self::globalIp($packed);
    }

    private static function ipBytes(string $host): string|false
    {
        // Python treats these as non-IP hostnames; PHP otherwise throws ValueError.
        if (str_contains($host, "\0")) return false;
        if (str_contains($host, '%')) {
            $parts = explode('%', $host);
            if (count($parts) !== 2 || $parts[1] === '' || !str_contains($parts[0], ':')) return false;
            $host = $parts[0];
        }
        return @inet_pton($host);
    }

    private static function globalIp(string $packed): bool
    {
        if (strlen($packed) === 4) {
            if ($packed === inet_pton('192.0.0.9') || $packed === inet_pton('192.0.0.10')) return true;
            $ranges = ['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','240.0.0.0/4'];
        } else {
            if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") return self::globalIp(substr($packed, 12));
            foreach (['2001:1::1/128','2001:1::2/128','2001:3::/32','2001:4:112::/48','2001:20::/28','2001:30::/28'] as $exception) {
                if (self::inRange($packed, $exception)) return true;
            }
            $ranges = ['::/128','::1/128','64:ff9b:1::/48','100::/64','2001::/23','2001:db8::/32','2002::/16','3fff::/20','fc00::/7','fe80::/10'];
        }
        foreach ($ranges as $range) if (self::inRange($packed, $range)) return false;
        return true;
    }

    private static function inRange(string $packed, string $range): bool
    {
        [$address, $prefix] = explode('/', $range);
        $network = inet_pton($address);
        $bytes = intdiv((int)$prefix, 8); $bits = (int)$prefix % 8;
        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) return false;
        return $bits === 0 || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (0xff << (8 - $bits))) === 0;
    }

    private static function fitmentLabel(array $row): string
    {
        $years = isset($row['year_from']) || isset($row['year_to']) ? self::years($row) : '';
        $label = implode(' ', [$row['make'] ?? '', $row['model'] ?? '', $row['displacement'] ?? '', $years]);
        $label = \Normalizer::normalize($label, \Normalizer::FORM_C);
        return implode(' ', preg_split('/[\x09-\x0d\x1c-\x20\x{0085}\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+/u', $label, -1, PREG_SPLIT_NO_EMPTY));
    }

    private static function years(array $row): string
    {
        return (($row['year_from'] ?? 0) ?: '…').'–'.(($row['year_to'] ?? 0) ?: '…');
    }

    private static function splitlines(string $value): array
    {
        if ($value === '') return [];
        $lines = preg_split('/\r\n|[\n\r\x0b\x0c\x1c-\x1e\x{0085}\x{2028}\x{2029}]/u', $value);
        if (preg_match('/[\n\r\x0b\x0c\x1c-\x1e\x{0085}\x{2028}\x{2029}]$/uD', $value)) array_pop($lines);
        return $lines;
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value) ? $value !== '' : (bool)$value;
    }

    private static function escape(mixed $value): string
    {
        // MarkupSafe uses these exact numeric entities for both quote types.
        return strtr((string)$value, ['&'=>'&amp;', '<'=>'&lt;', '>'=>'&gt;', "'"=>'&#39;', '"'=>'&#34;']);
    }

    private static function nl2br(string $value): string
    {
        return str_replace("\n", '<br/>', self::escape($value));
    }

    private function loadTemplate(): void
    {
        if ($this->template !== null) return;
        $source = file_get_contents($this->resources.'/ebay_description.html.j2');
        $css = file_get_contents($this->resources.'/ebay_template.css');
        if ($source === false || $css === false) throw new \RuntimeException('Description resources are unavailable.');
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $this->css = str_replace(["\r\n", "\r"], "\n", $css);
        // Jinja's default keep_trailing_newline=False removes one final newline.
        if (str_ends_with($source, "\n")) $source = substr($source, 0, -1);
        $tokens = preg_split('/({{.*?}}|{%.*?%})/s', $source, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $offset = 0;
        $this->template = $this->parseNodes($tokens, $offset);
    }

    /** Parse only the variable, if and for syntax present in the bundled file. */
    private function parseNodes(array $tokens, int &$offset, ?string $closing = null): array
    {
        $nodes = [];
        while ($offset < count($tokens)) {
            $token = $tokens[$offset++];
            if (str_starts_with($token, '{{')) {
                $nodes[] = ['value', trim(substr($token, 2, -2))];
            } elseif (str_starts_with($token, '{%')) {
                $statement = trim(substr($token, 2, -2));
                if ($statement === $closing) return $nodes;
                if (preg_match('/^if (.+)$/D', $statement, $match)) {
                    $nodes[] = ['if', $match[1], $this->parseNodes($tokens, $offset, 'endif')];
                } elseif (preg_match('/^for (\w+) in (.+)$/D', $statement, $match)) {
                    $nodes[] = ['for', $match[1], $match[2], $this->parseNodes($tokens, $offset, 'endfor')];
                } else {
                    throw new \RuntimeException('Unsupported description template statement.');
                }
            } else {
                $nodes[] = ['text', $token];
            }
        }
        if ($closing !== null) throw new \RuntimeException('Unclosed description template statement.');
        return $nodes;
    }

    private function renderNodes(array $nodes, array $context): string
    {
        $html = '';
        foreach ($nodes as $node) {
            switch ($node[0]) {
                case 'text': $html .= $node[1]; break;
                case 'value':
                    $expression = array_map('trim', explode('|', $node[1]));
                    $value = self::resolve($expression[0], $context);
                    $filter = $expression[1] ?? '';
                    if ($filter === 'safe' && $expression[0] === 'css') $html .= $value;
                    elseif ($filter === 'nl2br') $html .= self::nl2br($value);
                    elseif ($filter === '') $html .= self::escape($value);
                    else throw new \RuntimeException('Unsupported description template filter.');
                    break;
                case 'if':
                    if (self::truthy(self::resolve($node[1], $context))) $html .= $this->renderNodes($node[2], $context);
                    break;
                case 'for':
                    foreach (self::resolve($node[2], $context) as $value) $html .= $this->renderNodes($node[3], [$node[1]=>$value] + $context);
                    break;
            }
        }
        return $html;
    }

    private static function resolve(string $expression, array $context): mixed
    {
        if (!preg_match('/^(\w+)(?:\.(\w+)|\[\x27([^\x27]+)\x27\])?$/D', $expression, $match)) {
            throw new \RuntimeException('Unsupported description template expression.');
        }
        $value = $context[$match[1]] ?? '';
        $key = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
        return $key === '' ? $value : ($value[$key] ?? '');
    }
}

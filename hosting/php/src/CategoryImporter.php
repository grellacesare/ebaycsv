<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class CategoryImporter
{
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    private const EBAY_FORMAT = 'Usa un array JSON con id, path e contesto opzionale.';
    private const EBAY_IDS = 'ID categoria non numerici, vuoti o duplicati.';
    private const PRESTASHOP_FORMAT = 'Correggi il file categorie: UTF-8, separatore ; e posizione numerica.';

    private static function invalid(string $message): never
    {
        throw new AppError('invalid_category_file', $message);
    }

    private static function limit(string $path): void
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            self::invalid('Il repertorio categorie supera il limite di 10 MB.');
        }
    }

    private static function positiveId(string $id): bool
    {
        // Unicode 15 decimal digits match Python's isdigit + positive int check.
        return preg_match('/\A[\p{Nd}\x{11F50}-\x{11F59}\x{1E4F0}-\x{1E4F9}]+\z/uD',$id)===1
            && preg_match('/[^\x{30}\x{660}\x{6F0}\x{7C0}\x{966}\x{9E6}\x{A66}\x{AE6}\x{B66}\x{BE6}\x{C66}\x{CE6}\x{D66}\x{DE6}\x{E50}\x{ED0}\x{F20}\x{1040}\x{1090}\x{17E0}\x{1810}\x{1946}\x{19D0}\x{1A80}\x{1A90}\x{1B50}\x{1BB0}\x{1C40}\x{1C50}\x{A620}\x{A8D0}\x{A900}\x{A9D0}\x{A9F0}\x{AA50}\x{ABF0}\x{FF10}\x{104A0}\x{10D30}\x{11066}\x{110F0}\x{11136}\x{111D0}\x{112F0}\x{11450}\x{114D0}\x{11650}\x{116C0}\x{11730}\x{118E0}\x{11950}\x{11C50}\x{11D50}\x{11DA0}\x{11F50}\x{16A60}\x{16AC0}\x{16B50}\x{1D7CE}\x{1D7D8}\x{1D7E2}\x{1D7EC}\x{1D7F6}\x{1E140}\x{1E2F0}\x{1E4F0}\x{1E950}\x{1FBF0}]/u',$id)===1;
    }

    private static function strip(string $value): string
    {
        return preg_replace('/\A[\s\x{001c}-\x{001f}]+|[\s\x{001c}-\x{001f}]+\z/u', '', $value);
    }

    /** @return list<array{id:string,path:string,context:string}> */
    public static function ebay(string $path): array
    {
        self::limit($path);
        $bytes = @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);
        if ($bytes === false) self::invalid(self::EBAY_FORMAT);
        if (strlen($bytes) > self::MAX_FILE_BYTES) self::invalid('Il repertorio categorie supera il limite di 10 MB.');
        if (str_starts_with($bytes, "\xef\xbb\xbf")) $bytes = substr($bytes, 3);
        try {
            $rows = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            self::invalid(self::EBAY_FORMAT);
        }
        if ($rows instanceof \stdClass && (array)$rows === []) self::invalid(self::EBAY_IDS);
        if (!is_array($rows)) self::invalid(self::EBAY_FORMAT);
        $result = [];
        try {
            foreach ($rows as $raw) {
                $row = Input::object($raw, 'category');
                Input::fields($row, ['id', 'path', 'context'], ['id', 'path']);
                $categoryPath = Input::text($row['path'], 'path', self::MAX_FILE_BYTES);
                $folded = mb_convert_case($categoryPath, MB_CASE_FOLD, 'UTF-8');
                $inferred = str_contains($folded, 'scooter') ? 'scooter' : (str_starts_with($folded, 'moto:') ? 'moto' : 'unknown');
                $context = Input::choice($row['context'] ?? $inferred, 'context', ['moto', 'scooter', 'shared', 'other', 'unknown']);
                if (array_key_exists('context', $row) && $row['context'] === null) self::invalid(self::EBAY_FORMAT);
                $id = is_int($row['id']) || is_string($row['id']) ? (string)$row['id'] : '';
                $result[] = ['id' => $id, 'path' => $categoryPath, 'context' => $context];
            }
        } catch (AppError $error) {
            if ($error->errorCode === 'invalid_category_file') throw $error;
            self::invalid(self::EBAY_FORMAT);
        }
        $seen = [];
        foreach ($result as $category) {
            $key = 'id:' . $category['id'];
            if (!self::positiveId($category['id']) || isset($seen[$key])) self::invalid(self::EBAY_IDS);
            $seen[$key] = true;
        }
        if ($result === []) self::invalid(self::EBAY_IDS);
        return $result;
    }

    /** @return list<array{id:string,name:string,description:string,position:int,shown:bool}> */
    public static function prestashop(string $path): array
    {
        self::limit($path);
        $result = [];
        $seen = [];
        try {
            $headers = ImportService::readHeaders($path, 'utf-8-sig', ';');
            if (array_diff(['Id', 'Nome', 'Mostrata'], $headers)) {
                self::invalid('Il repertorio deve avere Id, Nome e Mostrata.');
            }
            foreach (ImportService::readRows($path, 'utf-8-sig', ';') as $record) {
                $row = $record['row'];
                $id = self::strip($row['Id'] ?? '');
                $name = $row['Nome'] ?? '';
                $shown = $row['Mostrata'] ?? '';
                if (($record['extra_columns']??false) || !self::positiveId($id) || self::strip($name) === '' || !in_array($shown, ['0', '1'], true)) {
                    self::invalid('Categoria non valida alla riga ' . ($record['line_end'] ?? $record['line']) . '.');
                }
                $position = $row['Posizione'] ?? '';
                $position = $position === '' ? 0 : Input::integer(self::strip($position), 'Posizione', PHP_INT_MIN);
                $key = 'id:' . $id;
                $seen[$key] = true;
                $result[] = ['id' => $id, 'name' => Input::text($name, 'Nome', self::MAX_FILE_BYTES),
                    'description' => Input::text($row['Descrizione'] ?? '', 'Descrizione', self::MAX_FILE_BYTES),
                    'position' => $position, 'shown' => $shown === '1'];
            }
        } catch (AppError $error) {
            if ($error->errorCode === 'invalid_category_file') throw $error;
            if ($error->errorCode === 'duplicate_headers') {
                $prefix = @file_get_contents($path, false, null, 0, 5);
                if (is_string($prefix)) {
                    if (str_starts_with($prefix, "\xef\xbb\xbf")) $prefix = substr($prefix, 3);
                    if ($prefix === '' || str_starts_with($prefix, "\r") || str_starts_with($prefix, "\n")) {
                        self::invalid('Il repertorio deve avere Id, Nome e Mostrata.');
                    }
                }
            }
            self::invalid(self::PRESTASHOP_FORMAT);
        }
        if ($result === [] || count($seen) !== count($result)) self::invalid('Categorie vuote o ID duplicati.');
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public static function replacementDiagnostics(array $catalog, array $rules): array
    {
        $valid = ['ebay' => [], 'prestashop' => []];
        foreach ($valid as $destination => $_) {
            foreach ($catalog[$destination] ?? [] as $category) $valid[$destination]['id:' . $category['id']] = true;
        }
        $diagnostics = [];
        foreach ($rules as $rule) {
            if (!isset($valid[$rule['destination']]['id:' . $rule['category_id']])) {
                $diagnostics[] = Support::diagnostic('error', 'stale_category_rule',
                    'La categoria ' . $rule['category_id'] . ' dell’associazione ' . $rule['family'] . ' non è più presente.',
                    ['hint' => 'Scegli una categoria del nuovo repertorio.']);
            }
        }
        return $diagnostics;
    }
}

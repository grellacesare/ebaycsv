<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Installer
{
    private string $dataDir;

    public function __construct(string $publicDir, string $documentRoot)
    {
        $declaredRoot = rtrim($documentRoot, DIRECTORY_SEPARATOR);
        $documentRoot = realpath($documentRoot) ?: '';
        $publicDir = realpath($publicDir) ?: '';
        if ($documentRoot === '' || $documentRoot === DIRECTORY_SEPARATOR || $publicDir === ''
            || ($publicDir !== $documentRoot && !str_starts_with($publicDir, $documentRoot . DIRECTORY_SEPARATOR))) {
            throw new AppError('unsafe_document_root', 'Il percorso pubblico del sito non è riconoscibile.');
        }
        // A subdomain root can be nested inside the main site's public tree.
        // Its sibling would still be downloadable through that main site.
        foreach (array_unique([$documentRoot, $declaredRoot]) as $root) {
            for ($ancestor = dirname($root); $ancestor !== DIRECTORY_SEPARATOR && $ancestor !== '.'; $ancestor = dirname($ancestor)) {
                if ($ancestor !== '/var/www' && in_array(strtolower(basename($ancestor)),
                    ['public_html', 'private_html', 'htdocs', 'wwwroot', 'html', 'www'], true)) {
                    throw new AppError('unsafe_document_root', 'Installa CSV Studio nella cartella pubblica del dominio principale: questo sottodominio è dentro un’altra cartella pubblica.');
                }
            }
        }
        $this->dataDir = dirname($documentRoot) . '/csvstudio-data';
        if (is_link($this->dataDir)) {
            throw new AppError('unsafe_data_directory', 'Usa una cartella dati privata, senza collegamenti simbolici.');
        }
        $resolved = realpath($this->dataDir);
        if ($resolved !== false && ($resolved === $documentRoot || str_starts_with($resolved, $documentRoot . DIRECTORY_SEPARATOR))) {
            throw new AppError('unsafe_data_directory', 'L’archivio deve trovarsi fuori dalle cartelle pubbliche.');
        }
    }

    public function dataDir(): string { return $this->dataDir; }
    public function installed(): bool { return is_file($this->dataDir . '/config.json'); }

    public function prepare(): array
    {
        if (!is_dir($this->dataDir) && is_writable(dirname($this->dataDir))) {
            @mkdir($this->dataDir, 0750);
        }
        $view = Requirements::audit($this->dataDir);
        $view['installed'] = $this->installed();
        if ($view['ready'] && !$view['installed']) {
            $lock = $this->lock();
            try {
                if (!$this->installed() && !is_file($this->dataDir . '/installation-key.txt')) {
                    $this->atomicWrite($this->dataDir . '/installation-key.txt', bin2hex(random_bytes(32)) . "\n");
                }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        return $view;
    }

    public function install(string $key, string $username, string $password): void
    {
        if ($this->installed()) { throw new AppError('already_installed', 'CSV Studio è già configurato.', 409); }
        $view = $this->prepare();
        if (!$view['ready']) { throw new AppError('hosting_requirements', 'Correggi i requisiti indicati prima di continuare.'); }
        $lock = $this->lock();
        try {
            if ($this->installed()) { throw new AppError('already_installed', 'CSV Studio è già configurato.', 409); }
            $stored = trim((string) file_get_contents($this->dataDir . '/installation-key.txt'));
            if (strlen($stored) !== 64 || strlen($key) !== 64 || !hash_equals($stored, $key)) {
                throw new AppError('invalid_installation_key', 'Il codice non corrisponde al file installation-key.txt della cartella privata.');
            }
            if (!preg_match('/\A[a-zA-Z0-9_.@-]{3,60}\z/D', $username)) {
                throw new AppError('invalid_username', 'Nome utente: da 3 a 60 caratteri, lettere, numeri, punto, trattino o @.');
            }
            if (strlen($password) < 12 || strlen($password) > 72) {
                throw new AppError('weak_password', 'Scegli una password da 12 a 72 caratteri.');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->atomicWrite($this->dataDir . '/config.json', Support::json([
                'version' => 1, 'username' => $username, 'password_hash' => $hash, 'created_at' => Support::now(),
            ]));
            unlink($this->dataDir . '/installation-key.txt');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function lock()
    {
        $handle = fopen($this->dataDir . '/setup.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new AppError('private_storage_unavailable', 'La cartella privata non è scrivibile.');
        }
        @chmod($this->dataDir . '/setup.lock', 0600);
        return $handle;
    }

    private function atomicWrite(string $destination, string $contents): void
    {
        $temp = $destination . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temp, $contents, LOCK_EX) !== strlen($contents)) {
                throw new AppError('private_storage_unavailable', 'Impossibile salvare la configurazione privata.');
            }
            chmod($temp, 0600);
            if (!rename($temp, $destination)) {
                throw new AppError('private_storage_unavailable', 'Impossibile completare la configurazione privata.');
            }
        } finally { if (is_file($temp)) { unlink($temp); } }
    }
}

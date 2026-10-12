<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Auth
{
    private ?array $config;

    public function __construct(private string $dataDir, private string $path)
    {
        $this->path = '/' . trim($path, '/') . '/';
        if ($this->path === '//') { $this->path = '/'; }
        $file = $this->dataDir . '/config.json';
        $this->config = is_file($file) ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function cookiePath(): string { return $this->path; }

    public function checkSetupAttempt(string $ip): void { $this->rate($ip, 'check', 'setup'); }
    public function failedSetupAttempt(string $ip): void { $this->rate($ip, 'failed', 'setup'); }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        $sessions = $this->dataDir . '/sessions';
        if (!is_dir($sessions) && !mkdir($sessions, 0700)) {
            throw new AppError('session_storage_unavailable', 'Impossibile aprire una sessione riservata.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '28800');
        session_save_path($sessions);
        session_name('MWCSVSESSID');
        session_set_cookie_params(['lifetime' => 0, 'path' => $this->path, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) { throw new AppError('session_unavailable', 'Impossibile aprire una sessione riservata.'); }
        if (!isset($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    }

    public function isAuthenticated(): bool
    {
        if ($this->config === null || ($_SESSION['owner'] ?? null) !== $this->config['username']) { return false; }
        if ((int) ($_SESSION['last_seen'] ?? 0) < time() - 28800) {
            unset($_SESSION['owner']);
            return false;
        }
        $_SESSION['last_seen'] = time();
        return true;
    }

    public function requireAuthenticated(): void
    {
        if (!$this->isAuthenticated()) { throw new AppError('not_authenticated', 'Accedi a CSV Studio per continuare.', 401); }
    }

    public function csrf(): string { $this->start(); return (string) $_SESSION['csrf']; }

    public function requireCsrf(string $token): void
    {
        if (strlen($token) !== 64 || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
            throw new AppError('invalid_csrf', 'La sessione è scaduta o la richiesta non è valida. Ricarica la pagina.', 403);
        }
    }

    public function login(string $username, string $password, string $ip): void
    {
        $this->start();
        if ($this->config === null) { throw new AppError('setup_required', 'Completa la configurazione iniziale.', 409); }
        $this->rate($ip, 'check');
        $verified = strlen($password) <= 72 && password_verify($password, (string) $this->config['password_hash']);
        if (!$verified || !hash_equals((string) $this->config['username'], $username)) {
            $this->rate($ip, 'failed');
            throw new AppError('invalid_credentials', 'Nome utente o password non corretti.', 401);
        }
        $this->rate($ip, 'clear');
        session_regenerate_id(true);
        $_SESSION['owner'] = $this->config['username'];
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public function release(): void { if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); } }

    public function logout(string $token): void
    {
        $this->start();
        $this->requireCsrf($token);
        $_SESSION = [];
        session_destroy();
        session_id('');
        setcookie('MWCSVSESSID', '', ['expires' => time() - 3600, 'path' => $this->path,
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }

    private function rate(string $ip, string $action, string $scope = 'login'): void
    {
        $file = $this->dataDir . '/' . $scope . '-limits.json';
        $handle = fopen($file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new AppError('login_unavailable', 'Accesso temporaneamente non disponibile.', 503);
        }
        chmod($file, 0600);
        try {
            $contents = stream_get_contents($handle);
            $rows = $contents ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : [];
            $now = time();
            $rows = array_filter($rows, static fn(array $row): bool => $row['until'] > $now);
            $id = hash('sha256', $ip);
            if ($action === 'check' && ($rows[$id]['count'] ?? 0) >= 5) {
                throw new AppError($scope . '_rate_limited', 'Troppi tentativi. Riprova tra 15 minuti.', 429);
            }
            if ($action === 'clear') { unset($rows[$id]); }
            if ($action === 'failed') {
                $rows[$id] = ['count' => ($rows[$id]['count'] ?? 0) + 1, 'until' => $rows[$id]['until'] ?? $now + 900];
                if (count($rows) > 1000) { $rows = array_slice($rows, -1000, null, true); }
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, Support::json($rows));
            fflush($handle);
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use MotorWorld\Hosting\Installer;
use MotorWorld\Hosting\Auth;
use MotorWorld\Hosting\AppError;

if (!class_exists(Installer::class) || !class_exists(Auth::class)) {
    fwrite(STDERR, "FAIL: private installer and authentication are missing.\n");
    exit(1);
}

function check(bool $condition, string $reason): void {
    if (!$condition) { throw new RuntimeException($reason); }
}
function rejects(callable $call, string $code): void {
    try { $call(); } catch (AppError $error) {
        check($error->errorCode === $code, 'Unexpected rejection: ' . $error->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}

$base = sys_get_temp_dir() . '/mw-security-' . bin2hex(random_bytes(6));
mkdir($base . '/public_html/csvstudio', 0700, true);
mkdir($base . '/public_html/subdomains/studio/csvstudio', 0700, true);
rejects(fn() => new Installer($base . '/public_html/subdomains/studio/csvstudio',
    $base . '/public_html/subdomains/studio'), 'unsafe_document_root');
mkdir($base . '/site-root/studio/csvstudio', 0700, true);
mkdir($base . '/alias', 0700);
symlink($base . '/site-root', $base . '/alias/public_html');
rejects(fn() => new Installer($base . '/alias/public_html/studio/csvstudio',
    $base . '/alias/public_html/studio'), 'unsafe_document_root');
$installer = new Installer($base . '/public_html/csvstudio', $base . '/public_html');
$view = $installer->prepare();
check($view['ready'] && !$view['installed'], 'Ready host can prepare a private setup.');
$data = $installer->dataDir();
check(realpath(dirname($data)) === realpath($base), 'Data stays outside public_html.');
$key = trim(file_get_contents($data . '/installation-key.txt'));
check(strlen($key) === 64, 'Installation requires a random private FTP code.');
$installer->prepare();
check(trim(file_get_contents($data . '/installation-key.txt')) === $key, 'Visitors cannot invalidate the owner’s code.');
rejects(fn() => $installer->install('wrong-key', 'cesare', 'UnaPasswordPrivata123!'), 'invalid_installation_key');
rejects(fn() => $installer->install($key, 'cesare', 'short'), 'weak_password');
$installer->install($key, 'cesare', 'UnaPasswordPrivata123!');
check(!is_file($data . '/installation-key.txt'), 'One-time key disappears after installation.');
$config = json_decode(file_get_contents($data . '/config.json'), true, flags: JSON_THROW_ON_ERROR);
check(!str_contains(json_encode($config), 'UnaPasswordPrivata123!'), 'Configuration never stores plaintext password.');
check(password_verify('UnaPasswordPrivata123!', $config['password_hash']), 'Owner hash verifies.');
rejects(fn() => $installer->install($key, 'intruso', 'UnaPasswordPrivata123!'), 'already_installed');

$auth = new Auth($data, '/csvstudio/');
for ($i = 0; $i < 5; $i++) {
    $auth->checkSetupAttempt('127.0.0.4');
    $auth->failedSetupAttempt('127.0.0.4');
}
rejects(fn() => $auth->checkSetupAttempt('127.0.0.4'), 'setup_rate_limited');
$auth->checkSetupAttempt('127.0.0.5');
$auth->start();
rejects(fn() => $auth->requireAuthenticated(), 'not_authenticated');
rejects(fn() => $auth->login('intruso', 'UnaPasswordPrivata123!', '127.0.0.2'), 'invalid_credentials');
$auth->login('cesare', 'UnaPasswordPrivata123!', '127.0.0.1');
check($auth->isAuthenticated(), 'Owner can log in.');
$token = $auth->csrf();
check(strlen($token) === 64, 'CSRF token is cryptographically random.');
rejects(fn() => $auth->requireCsrf(''), 'invalid_csrf');
rejects(fn() => $auth->requireCsrf('different'), 'invalid_csrf');
$auth->requireCsrf($token);
check($auth->cookiePath() === '/csvstudio/', 'Nested installation scopes its session cookie.');
$params = session_get_cookie_params();
check($params['secure'] && $params['httponly'] && $params['samesite'] === 'Lax', 'Cookie protects the private session.');
$auth->logout($token);
check(!$auth->isAuthenticated(), 'Logout revokes authenticated state.');
for ($i = 0; $i < 5; $i++) {
    rejects(fn() => $auth->login('cesare', 'incorrect-password', '127.0.0.3'), 'invalid_credentials');
}
rejects(fn() => $auth->login('cesare', 'incorrect-password', '127.0.0.3'), 'login_rate_limited');
echo "PASS: private FTP proof, one-time setup, password/session/CSRF and login throttling.\n";

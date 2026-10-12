<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'MotorWorld\\Hosting\\' => __DIR__ . '/src/',
        'Brick\\Math\\' => __DIR__ . '/vendor/brick/math/src/',
        'Symfony\\Polyfill\\Intl\\Normalizer\\' => __DIR__ . '/vendor/symfony/normalizer/',
    ];
    if ($class === 'Normalizer') {
        require __DIR__ . '/vendor/symfony/normalizer/Resources/stubs/Normalizer.php';
        return;
    }
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $path = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) { require $path; }
            return;
        }
    }
});
require __DIR__ . '/vendor/symfony/normalizer/bootstrap.php';

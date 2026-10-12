<?php
declare(strict_types=1);

$bootstrap = dirname(__DIR__) . '/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "FAIL: PHP hosting bootstrap is missing.\n");
    exit(1);
}
require $bootstrap;

use MotorWorld\Hosting\Support;
use MotorWorld\Hosting\AppError;
use MotorWorld\Hosting\NotFound;
use Brick\Math\BigDecimal;

function expect(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}

expect((string) BigDecimal::of('0.1')->plus('0.2') === '0.3', 'Decimal arithmetic must stay exact.');
expect(Normalizer::normalize("e\u{0301}", Normalizer::FORM_C) === "é", 'NFC must work without ext-intl.');
expect(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Support::uuid()) === 1, 'UUID v4 format.');
expect(Support::diagnostic('error', 'missing_cost', 'Costo mancante')['hint'] === '', 'Diagnostic defaults match JSON model.');
expect(json_decode(Support::json(['name' => 'è']), true)['name'] === 'è', 'JSON preserves Unicode.');
$error = new AppError('invalid_cost', 'Costo non valido.');
expect($error->errorCode === 'invalid_cost' && $error->status === 422, 'Public error contract.');
expect((new NotFound('secret-file'))->status === 404, 'Missing data contract.');
expect(!str_contains((new NotFound('secret-file'))->getMessage(), 'secret-file'), 'Errors must not expose storage details.');
echo "PASS: PHP bootstrap, bundled decimal/Unicode and shared error/model helpers.\n";

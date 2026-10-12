<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use MotorWorld\Hosting\{Application, Router, Defaults, Support};

$app = new Application(sys_get_temp_dir() . '/mw-validation-budget-' . bin2hex(random_bytes(6)), Support::resources());
$api = new Router($app);
$blob = str_repeat('x', 300000);
$products = [];
for ($i = 0; $i < 100; $i++) {
    $products[] = Defaults::product(['id' => 'p'.$i, 'supplier_id' => 's', 'code' => sprintf('%04d', $i),
        'name' => 'Pastiglie freno', 'family' => 'Pastiglie Freno', 'cost' => '10',
        'extra' => ['supplier_notes' => $blob],
        'sources' => [['file' => 'source.csv', 'row' => $i + 2,
            'columns' => ['code' => sprintf('%04d', $i), 'supplier_notes' => $blob]]]]);
}
$app->store->save_import('original', ['products' => $products, 'diagnostics' => [], 'source_counts' => []]);
unset($products, $blob);
$profile = Defaults::platform('prestashop');
$profile['tax_rule_id'] = '1';
$app->store->save_platform_profile($profile);
$app->store->put_record('price_settings', 'prestashop', Defaults::priceSettings([
    'shipping_cost' => '5', 'product_markup' => '.2', 'shipping_markup' => '0',
    'commission_rate' => '.1', 'quantity' => 1]));
$job = $api->dispatch('POST', '/imports/original/validate', [],
    ['destination' => 'prestashop', 'request_key' => 'bounded-validation']);
ini_set('memory_limit', '64M');
$steps = 0;
while ($job['state'] !== 'completed') {
    if (++$steps > 101) throw new RuntimeException('Validation did not finish its checkpoints.');
    $job = $api->dispatch('POST', '/jobs/'.$job['id'].'/step');
    if ($job['state'] === 'failed') throw new RuntimeException($job['error']);
    if ($steps === 1 && $job['progress']['completed'] > 2) {
        throw new RuntimeException('Source payloads must count toward the per-step byte budget.');
    }
}
if ($job['progress']['completed'] !== 100) throw new RuntimeException('Some products were skipped.');
if ($app->store->list_drafts('original', 'prestashop', null, 0, 1)['total'] !== 100) {
    throw new RuntimeException('The final atomic catalog is incomplete.');
}
echo "PASS: 100 large supplier rows validate under 64 MB through $steps bounded steps.\n";

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MotorWorld\Hosting\{AppError, Catalog, Defaults, Descriptions, DraftCompiler, Pricing};

// One process consumes all fixtures so the checked code is the shipped service.
$fixtures = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$resources = dirname(__DIR__, 3) . '/backend/src/motorworld_csv/resources';
$compiler = new DraftCompiler($resources);
$descriptions = new Descriptions($resources);
$results = [];
foreach ($fixtures as $fixture) {
    $a = $fixture['args'];
    try {
        $result = match ($fixture['op']) {
            'pricing' => Pricing::calculate($a[0], $a[1], $a[2] ?? null),
            'end90' => Pricing::endIn90($a[0]),
            'normalizeTitle' => DraftCompiler::normalizeTitle($a[0]),
            'title' => DraftCompiler::proposeTitle($a[0], $a[1]),
            'validateTitle' => DraftCompiler::validateTitle($a[0]),
            'validateReference' => DraftCompiler::validateReference($a[0]),
            'compatibilities' => DraftCompiler::validateCompatibilities($a[0]),
            'identity' => DraftCompiler::listingIdentity($a[0], $a[1], $a[2]),
            'productId' => Defaults::productId($a[0], $a[1]),
            'profile' => Defaults::profile($a[0]),
            'validateProfile' => DraftCompiler::validateProfile($a[0]),
            'normalizeKey' => Catalog::normalizeKey($a[0]),
            'catalog' => (new Catalog($resources))->load(),
            'category' => Catalog::resolve(...$a),
            'publicUrl' => Descriptions::public_https_url($a[0]),
            'description' => $descriptions->render_description(...$a),
            'snapshot' => DraftCompiler::snapshotFields(...$a),
            'columns' => DraftCompiler::columns($a[0], $resources),
            'draftRow' => DraftCompiler::draftToRow(...$a),
            'compile' => $compiler->compile(...$a),
            default => throw new RuntimeException('Unknown domain fixture.'),
        };
        $results[] = ['name' => $fixture['name'], 'result' => $result];
    } catch (AppError $error) {
        $results[] = ['name' => $fixture['name'], 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]];
    }
}
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

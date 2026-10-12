<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__ . '/app/bootstrap.php';
\MotorWorld\Hosting\Portal::run(__DIR__, 'setup');

<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class Download
{
    public function __construct(public readonly string $path,public readonly string $name,public readonly string $type) {}
}

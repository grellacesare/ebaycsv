<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

class AppError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct($message);
    }
}

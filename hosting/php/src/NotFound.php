<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

final class NotFound extends AppError
{
    public function __construct(string $id = '')
    {
        parent::__construct('not_found', 'Elemento non trovato nell’archivio.', 404);
    }
}

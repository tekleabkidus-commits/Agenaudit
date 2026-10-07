<?php

namespace App\Exceptions;

use RuntimeException;

class HardRejectException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message)
    {
        parent::__construct($message);
    }
}

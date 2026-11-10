<?php

namespace App\Services\Studio;

class FreeMeshException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

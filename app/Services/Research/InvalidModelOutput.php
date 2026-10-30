<?php

namespace App\Services\Research;

/** Model output that was not usable JSON for the contract; carries safe correction feedback. */
class InvalidModelOutput extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly array $feedback, ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
    }
}

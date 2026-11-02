<?php

namespace App\Jobs\Research;

use App\Services\Research\Deliveries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        app(Deliveries::class)->generate($this->runId);
    }
}

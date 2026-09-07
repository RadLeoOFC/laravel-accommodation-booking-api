<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\OfferImport;

class ProcessOfferImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $importId)
    {
        
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // UA: Обробку пропозицій буде реалізовано в наступному коміті.
        // EN: Offer processing will be implemented in the next commit.
        throw new \LogicException('Offer import processing is not implemented yet.');
    }
}

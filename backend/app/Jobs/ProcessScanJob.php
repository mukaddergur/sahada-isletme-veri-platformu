<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Services\CrawlerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessScanJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 300;

    public function __construct(public int $scanId) {}

    public function handle(CrawlerService $crawlerService): void
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(300);

        $scan = Scan::query()->findOrFail($this->scanId);
        $crawlerService->run($scan);
    }
}

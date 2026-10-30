<?php

namespace App\Jobs;

use App\Services\Social\SocialSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A tiny job the scheduler puts on the `social` queue every five minutes. When it runs, the dedicated social worker is
 * alive; Social Settings refuses to switch publishing on until that has been seen (plan §10).
 */
class SocialQueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection('social');
        $this->onQueue('social');
    }

    public function handle(SocialSettingsService $settings): void
    {
        $settings->recordWorkerHeartbeat();
    }
}

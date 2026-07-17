<?php

namespace App\Jobs;

use App\Services\SlaTimerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateSlaBreachesJob implements ShouldQueue
{
    use Queueable;

    public function handle(SlaTimerService $slaTimerService): void
    {
        $slaTimerService->evaluateBreaches();
    }
}

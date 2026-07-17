<?php

namespace App\Console\Commands;

use App\Services\SlaTimerService;
use Illuminate\Console\Command;

class EvaluateSlaBreachesCommand extends Command
{
    protected $signature = 'helixdesk:evaluate-sla-breaches';

    protected $description = 'Mark work item SLA timers that have breached';

    public function handle(SlaTimerService $slaTimerService): int
    {
        $count = $slaTimerService->evaluateBreaches();
        $this->info("Marked {$count} SLA breach(es).");

        return self::SUCCESS;
    }
}

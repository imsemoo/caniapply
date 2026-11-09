<?php

namespace App\Jobs;

use App\Models\Opportunity;
use App\Services\Evaluation\OpportunityEvaluator;
use App\Services\Notifications\OpportunityAlerts;
use App\Services\Notifications\OperatorAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EvaluateOpportunity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public array $backoff = [30, 120];

    public function __construct(public int $opportunityId) {}

    public function handle(OpportunityEvaluator $evaluator, OpportunityAlerts $alerts): void
    {
        $opportunity = Opportunity::query()->find($this->opportunityId);

        if ($opportunity === null || ! $opportunity->needs_evaluation) {
            return;
        }

        $alerts->afterEvaluation($evaluator->evaluate($opportunity));
    }

    public function failed(?Throwable $e): void
    {
        app(OperatorAlerts::class)->jobFailed('Evaluation', (string) $e?->getMessage());
    }
}

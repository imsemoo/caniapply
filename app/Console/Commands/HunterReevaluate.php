<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateOpportunity;
use App\Models\Opportunity;
use Illuminate\Console\Command;

class HunterReevaluate extends Command
{
    protected $signature = 'hunter:reevaluate {--all : Every record, not just those awaiting evaluation} {--now : Run in this process}';

    protected $description = 'Score opportunities again, for example after editing the profile';

    public function handle(): int
    {
        if ($this->option('all')) {
            Opportunity::query()->active()->update(['needs_evaluation' => true]);
        }

        $query = Opportunity::query()->active()->where('needs_evaluation', true);
        $count = 0;

        $query->select('id')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $this->option('now') ? EvaluateOpportunity::dispatchSync($row->id) : EvaluateOpportunity::dispatch($row->id);
                $count++;
            }
        });

        $this->info("{$count} opportunities sent for evaluation.");

        return self::SUCCESS;
    }
}

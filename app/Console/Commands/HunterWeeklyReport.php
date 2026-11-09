<?php

namespace App\Console\Commands;

use App\Models\MarketReport;
use App\Services\Notifications\MessageComposer;
use App\Services\Notifications\TelegramBot;
use App\Services\Reports\MarketAnalytics;
use Illuminate\Console\Command;

class HunterWeeklyReport extends Command
{
    protected $signature = 'hunter:weekly-report';

    protected $description = 'Build the weekly market report and send it to Telegram';

    public function handle(MarketAnalytics $analytics, MessageComposer $composer, TelegramBot $bot): int
    {
        $to = now();
        $from = $to->copy()->subDays(7);
        $figures = $analytics->weeklyFigures($from, $to);

        $report = MarketReport::query()->updateOrCreate(
            ['kind' => 'weekly', 'period_end' => $to->toDateString()],
            ['period_start' => $from->toDateString(), 'figures' => $figures],
        );

        if ($bot->send($composer->weekly($figures))) {
            $report->update(['sent_at' => now()]);
            $this->info('Report sent.');
        } else {
            $this->warn('Report saved, but not sent to Telegram.');
        }

        return self::SUCCESS;
    }
}

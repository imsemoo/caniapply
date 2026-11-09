<?php

namespace App\Console\Commands;

use App\Services\Notifications\OpportunityAlerts;
use Illuminate\Console\Command;

class HunterDigest extends Command
{
    protected $signature = 'hunter:digest';

    protected $description = 'Send the daily opportunity digest to Telegram';

    public function handle(OpportunityAlerts $alerts): int
    {
        if ($alerts->sendDailyDigest()) {
            $this->info('Digest sent.');

            return self::SUCCESS;
        }

        $this->error('Digest not sent. Check TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID and the log.');

        return self::FAILURE;
    }
}

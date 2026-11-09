<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramActions;
use Illuminate\Console\Command;

class HunterTelegramPoll extends Command
{
    protected $signature = 'hunter:telegram-poll';

    protected $description = 'Apply buttons pressed on Telegram alerts';

    public function handle(TelegramActions $actions): int
    {
        $actions->poll();

        return self::SUCCESS;
    }
}

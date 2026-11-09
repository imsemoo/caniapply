<?php

namespace App\Console\Commands;

use App\Services\Discovery\ListingRechecker;
use Illuminate\Console\Command;

class HunterRecheck extends Command
{
    protected $signature = 'hunter:recheck';

    protected $description = 'Flag expired listings and recheck the important ones';

    public function handle(ListingRechecker $rechecker): int
    {
        $result = $rechecker->run();
        $this->info("Expired {$result['expired']}, closed {$result['closed']}, checked {$result['checked']}.");

        return self::SUCCESS;
    }
}

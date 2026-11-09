<?php

namespace App\Console\Commands;

use App\Services\Normalization\CurrencyConverter;
use Illuminate\Console\Command;

class HunterFxRefresh extends Command
{
    protected $signature = 'hunter:fx-refresh';

    protected $description = 'Refresh currency rates used for salary conversion';

    public function handle(CurrencyConverter $converter): int
    {
        if ($converter->refresh()) {
            $this->info('Rates updated.');

            return self::SUCCESS;
        }

        $this->warn('Rates not updated; the previous ones stay in use.');

        return self::FAILURE;
    }
}

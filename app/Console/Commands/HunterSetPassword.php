<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class HunterSetPassword extends Command
{
    protected $signature = 'hunter:set-password {email}';

    protected $description = 'Create the single dashboard account, or change its password';

    public function handle(): int
    {
        $password = $this->secret('New password (12 characters or more)');

        if (mb_strlen((string) $password) < 12) {
            $this->error('Too short.');

            return self::FAILURE;
        }

        User::query()->updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => 'Owner', 'password' => Hash::make($password)],
        );

        $this->info('Account saved.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CandidateProfile;
use App\Models\User;
use App\Services\Discovery\EligibilityRefresher;
use App\Sources\SourceRegistry;
use App\Support\HomeCountry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class HunterInstall extends Command
{
    protected $signature = 'hunter:install {--email=} {--name=} {--headline=} {--country=} {--demo : Load fictional listings so that the dashboard has something to show}';

    protected $description = 'First run: your country, your profile and the dashboard account';

    public function handle(SourceRegistry $registry, EligibilityRefresher $refresher): int
    {
        $country = $this->option('country') ?: $this->choice('Which country do you work from?', HomeCountry::names(), config('hunter.country'));

        if (! in_array($country, HomeCountry::names(), true)) {
            $this->error("\"{$country}\" is not in the list. Add a row to app/Support/HomeCountry.php, or pick a listed name.");

            return self::FAILURE;
        }

        $profile = CandidateProfile::current();
        $profile->update([
            'country' => $country,
            'full_name' => $this->option('name') ?: $this->ask('Your name, as it should sign application letters', $profile->full_name),
            'headline' => $this->option('headline') ?: $this->ask('Your job title', $profile->headline),
        ]);

        $registry->sync();
        $refresher->run();

        if (User::query()->exists()) {
            $this->info('The dashboard account already exists. Use hunter:set-password to change it.');
        } else {
            $email = $this->option('email') ?: $this->ask('Email for the dashboard login', 'you@example.com');

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('That is not an email address.');

                return self::FAILURE;
            }

            $password = Str::password(20, symbols: false);
            User::query()->create(['name' => 'Owner', 'email' => $email, 'password' => Hash::make($password)]);

            $this->newLine();
            $this->line("  Login:    {$email}");
            $this->line("  Password: {$password}");
            $this->warn('  This password is shown once. Change it with: php artisan hunter:set-password '.$email);
            $this->newLine();
        }

        if ($this->option('demo')) {
            $this->call('hunter:demo');
        }

        return self::SUCCESS;
    }
}

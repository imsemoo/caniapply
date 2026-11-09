<?php

namespace App\Providers;

use App\Models\CandidateProfile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Queue workers and hunter commands write messages and stored reasons, so they follow the chosen language too.
        Queue::before(fn () => app()->setLocale(CandidateProfile::current()->locale));
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (str_starts_with((string) $event->command, 'hunter:')) {
                app()->setLocale(CandidateProfile::current()->locale);
            }
        });

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('email:'.mb_strtolower(is_string($request->input('email')) ? $request->input('email') : '')),
        ]);
    }
}

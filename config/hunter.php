<?php

use App\Sources\Connectors\ArbeitnowSource;
use App\Sources\Connectors\AshbySource;
use App\Sources\Connectors\GreenhouseSource;
use App\Sources\Connectors\HimalayasSource;
use App\Sources\Connectors\JobicySource;
use App\Sources\Connectors\LaraJobsSource;
use App\Sources\Connectors\LeverSource;
use App\Sources\Connectors\RecruiteeSource;
use App\Sources\Connectors\RemoteOkSource;
use App\Sources\Connectors\RemotiveSource;
use App\Sources\Connectors\VueJobsSource;
use App\Sources\Connectors\WeWorkRemotelySource;
use App\Sources\Connectors\WorkableSource;

return [

    /*
    | Where you work from. Must be a name listed in App\Support\HomeCountry.
    | Used for the first run only; change it later under Settings.
    */
    'country' => env('HUNTER_COUNTRY', 'Egypt'),

    'user_agent' => env('HUNTER_USER_AGENT', 'CanIApply/1.0 (self-hosted job search; low volume)'),

    'http_pause_ms' => (int) env('HUNTER_HTTP_PAUSE_MS', 1500),

    /*
    | Cron expressions, evaluated in the timezone below. Every job can be moved
    | from .env without touching code.
    */
    'timezone' => env('HUNTER_TIMEZONE', 'UTC'),
    'schedule' => [
        'discover' => env('HUNTER_CRON_DISCOVER', '0 */3 * * *'),
        'digest' => env('HUNTER_CRON_DIGEST', '0 9 * * *'),
        'recheck' => env('HUNTER_CRON_RECHECK', '30 7 * * *'),
        'weekly_report' => env('HUNTER_CRON_WEEKLY', '0 10 * * 6'),
        'backup' => env('HUNTER_CRON_BACKUP', '0 4 * * *'),
        'fx' => env('HUNTER_CRON_FX', '15 6 * * *'),
    ],

    'discovery' => [
        'max_age_days' => (int) env('HUNTER_MAX_AGE_DAYS', 30),
        'expire_after_days' => (int) env('HUNTER_EXPIRE_AFTER_DAYS', 45),
        'recheck_min_score' => (int) env('HUNTER_RECHECK_MIN_SCORE', 70),
        'recheck_limit' => (int) env('HUNTER_RECHECK_LIMIT', 60),
        'failures_before_alert' => (int) env('HUNTER_FAILURES_BEFORE_ALERT', 3),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'base_url' => env('TELEGRAM_BASE_URL', 'https://api.telegram.org'),
        'max_immediate_per_run' => (int) env('TELEGRAM_MAX_IMMEDIATE_PER_RUN', 8),
        'alert_max_age_days' => (int) env('TELEGRAM_ALERT_MAX_AGE_DAYS', 7),
    ],

    /*
    | provider: none | anthropic | openai
    | "openai" covers any service exposing the OpenAI chat-completions format.
    | With "none" the system still runs on the rule engine alone, and every
    | evaluation is labelled so you can tell which engine produced it.
    */
    'llm' => [
        'provider' => env('LLM_PROVIDER', 'none'),
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'claude-haiku-5-5'),
        'base_url' => env('LLM_BASE_URL'),
        'json_mode' => env('LLM_JSON_MODE', 'schema'), // schema | object
        'token_param' => env('LLM_TOKEN_PARAM', 'max_tokens'),
        'max_output_tokens' => (int) env('LLM_MAX_OUTPUT_TOKENS', 3000),
        'timeout' => (int) env('LLM_TIMEOUT', 90),
        'daily_limit' => (int) env('LLM_DAILY_LIMIT', 150),
        'min_prescore' => (int) env('LLM_MIN_PRESCORE', 50),
        'max_description_chars' => (int) env('LLM_MAX_DESCRIPTION_CHARS', 9000),
    ],

    /*
    | Used only until the first successful refresh from open.er-api.com.
    | Units of currency per 1 USD.
    */
    'fx_fallback' => [
        'USD' => 1.0, 'EUR' => 0.89, 'GBP' => 0.76, 'CAD' => 1.40, 'AUD' => 1.53,
        'CHF' => 0.81, 'SEK' => 9.6, 'NOK' => 10.2, 'DKK' => 6.6, 'PLN' => 3.7,
        'SAR' => 3.75, 'AED' => 3.67, 'QAR' => 3.64, 'KWD' => 0.31, 'EGP' => 48.0,
        'INR' => 86.0, 'TRY' => 41.0, 'JPY' => 150.0, 'SGD' => 1.3, 'NZD' => 1.68,
    ],

    'letters' => [
        // Your projects, in the format of portfolio.example.json. Letters may only cite what is in this file.
        'portfolio_json' => env('HUNTER_PORTFOLIO_JSON', storage_path('app/private/portfolio.json')),
        'portfolio_site' => env('HUNTER_PORTFOLIO_SITE'),
        // Comma-separated names that must never appear in a letter, such as past clients under NDA.
        'never_mention' => array_values(array_filter(array_map('trim', explode(',', (string) env('HUNTER_NEVER_MENTION', ''))))),
    ],

    'backup' => [
        'path' => env('HUNTER_BACKUP_PATH', storage_path('backups')),
        'keep' => (int) env('HUNTER_BACKUP_KEEP', 14),
    ],

    /*
    | One entry per connector. min_interval_hours protects sources that ask for
    | a low polling rate, independently of the global discovery cron.
    */
    'sources' => [
        'himalayas' => ['label' => 'Himalayas', 'class' => HimalayasSource::class, 'type' => 'api', 'kind' => 'jobs', 'min_interval_hours' => 3, 'options' => []],
        'remotive' => ['label' => 'Remotive', 'class' => RemotiveSource::class, 'type' => 'api', 'kind' => 'jobs', 'min_interval_hours' => 6, 'options' => []],
        'jobicy' => ['label' => 'Jobicy', 'class' => JobicySource::class, 'type' => 'api', 'kind' => 'jobs', 'min_interval_hours' => 3, 'options' => []],
        'remoteok' => ['label' => 'Remote OK', 'class' => RemoteOkSource::class, 'type' => 'api', 'kind' => 'jobs', 'min_interval_hours' => 3, 'options' => []],
        'weworkremotely' => ['label' => 'We Work Remotely', 'class' => WeWorkRemotelySource::class, 'type' => 'rss', 'kind' => 'jobs', 'min_interval_hours' => 3, 'options' => []],
        'arbeitnow' => ['label' => 'Arbeitnow', 'class' => ArbeitnowSource::class, 'type' => 'api', 'kind' => 'jobs', 'min_interval_hours' => 6, 'options' => []],
        'larajobs' => ['label' => 'LaraJobs', 'class' => LaraJobsSource::class, 'type' => 'rss', 'kind' => 'jobs', 'min_interval_hours' => 3, 'options' => []],
        'vuejobs' => ['label' => 'VueJobs', 'class' => VueJobsSource::class, 'type' => 'rss', 'kind' => 'jobs', 'min_interval_hours' => 3, 'options' => []],
        'greenhouse' => ['label' => 'Company pages (Greenhouse)', 'class' => GreenhouseSource::class, 'type' => 'ats', 'kind' => 'jobs', 'min_interval_hours' => 6, 'options' => ['boards' => ['gitlab', 'remotecom', 'tamara']]],
        'ashby' => ['label' => 'Company pages (Ashby)', 'class' => AshbySource::class, 'type' => 'ats', 'kind' => 'jobs', 'min_interval_hours' => 6, 'options' => ['boards' => ['zapier', 'posthog', 'deel', 'supabase', 'linear', 'n8n']]],
        'lever' => ['label' => 'Company pages (Lever)', 'class' => LeverSource::class, 'type' => 'ats', 'kind' => 'jobs', 'min_interval_hours' => 6, 'options' => ['boards' => ['toptal']]],
        'recruitee' => ['label' => 'Company pages (Recruitee)', 'class' => RecruiteeSource::class, 'type' => 'ats', 'kind' => 'jobs', 'min_interval_hours' => 6, 'options' => ['boards' => ['scholarshipowl', 'reiztech']]],
        'workable' => ['label' => 'Company pages (Workable)', 'class' => WorkableSource::class, 'type' => 'ats', 'kind' => 'jobs', 'min_interval_hours' => 6, 'options' => ['boards' => ['gathern', 'hospitable', 'foodics', 'lucidya', 'salla', 'syarah']]],
    ],

    /*
    | Popular sources that have no permitted automated route.
    | Listed in the dashboard so the gap stays visible.
    */
    'unavailable_sources' => [
        'LinkedIn Jobs' => ['reason' => 'robots.txt states automated access is prohibited without express permission; no public jobs API.', 'alternative' => 'Paste a listing into "Add a job" and it runs through the same scoring.'],
        'Indeed' => ['reason' => 'Publisher API closed to new applicants; job pages sit behind bot protection.', 'alternative' => 'Add a job manually.'],
        'Glassdoor' => ['reason' => 'No public jobs API; job pages sit behind bot protection and login.', 'alternative' => 'Add a job manually.'],
        'Wellfound' => ['reason' => 'No public API; robots.txt blocks the job-query URLs and listings need login.', 'alternative' => 'Add a job manually.'],
        'Wuzzuf' => ['reason' => 'No public API; pages are behind Cloudflare for scripts.', 'alternative' => 'Add a job manually.'],
        'Bayt' => ['reason' => 'No public API or feed for job search.', 'alternative' => 'Add a job manually.'],
        'Dynamite Jobs' => ['reason' => 'No feed or API found; the /feed paths return the home page.', 'alternative' => 'Add a job manually.'],
        'Y Combinator (Work at a Startup)' => ['reason' => 'No public API; listings need an account.', 'alternative' => 'Many YC companies use Ashby, Greenhouse or Lever: add their board slug in config/hunter.php.'],
        'Upwork' => ['reason' => 'The public RSS feed was shut down (HTTP 410). The official API needs an approved OAuth app.', 'alternative' => 'Apply for an Upwork API key, then add a connector.'],
    ],

];

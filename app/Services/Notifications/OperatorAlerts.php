<?php

namespace App\Services\Notifications;

use App\Models\JobSource;
use Illuminate\Support\Facades\Cache;

/** Tells the owner when the system itself needs attention. Rate-limited on purpose. */
class OperatorAlerts
{
    public function __construct(private TelegramBot $bot) {}

    public function sourceFailing(JobSource $source): void
    {
        $limit = config('hunter.discovery.failures_before_alert');

        if ($source->consecutive_failures < $limit || $source->failure_alerted_at !== null) {
            return;
        }

        $sent = $this->bot->send(sprintf(
            "<b>SOURCE FAILING</b>\n%s has failed %d runs in a row.\n%s",
            htmlspecialchars($source->label, ENT_NOQUOTES),
            $source->consecutive_failures,
            htmlspecialchars((string) $source->last_error, ENT_NOQUOTES),
        ));

        if ($sent) {
            $source->update(['failure_alerted_at' => now()]);
        }
    }

    public function llmFailing(string $error): void
    {
        if (Cache::add('alert:llm-failing', true, now()->addDay())) {
            $this->bot->send("<b>AI SCORING FAILING</b>\nJobs are being scored by the rule engine until this is fixed.\n".htmlspecialchars($error, ENT_NOQUOTES));
        }
    }

    public function jobFailed(string $name, string $error): void
    {
        if (Cache::add('alert:job-failed:'.$name, true, now()->addHours(6))) {
            $this->bot->send('<b>BACKGROUND JOB FAILED</b>'."\n".htmlspecialchars($name, ENT_NOQUOTES)."\n".htmlspecialchars(mb_substr($error, 0, 400), ENT_NOQUOTES));
        }
    }
}

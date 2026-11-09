<?php

namespace App\Services\Applications;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Opportunity;

/** Records what the owner did himself. Nothing here contacts an employer. */
class ApplicationTracker
{
    public function setStatus(Opportunity $opportunity, ApplicationStatus $status): Application
    {
        $application = Application::query()->firstOrNew(['opportunity_id' => $opportunity->id]);
        $application->status = $status;

        if ($status === ApplicationStatus::Applied && $application->applied_on === null) {
            $application->applied_on = today();
        }

        $application->save();

        return $application;
    }

    /** Dismissing a job with no history removes it from the tracker's "active" work. */
    public function isDismissed(?Application $application): bool
    {
        return $application?->status === ApplicationStatus::Rejected;
    }
}

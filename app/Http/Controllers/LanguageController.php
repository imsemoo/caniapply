<?php

namespace App\Http\Controllers;

use App\Models\CandidateProfile;
use App\Models\Opportunity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class LanguageController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', 'in:ar,en']]);
        $profile = CandidateProfile::current();

        if ($profile->locale !== $validated['locale']) {
            $profile->update(['locale' => $validated['locale']]);

            // Reasons and recommendations are stored as text, so they are rewritten in the new language.
            // A few hundred jobs on the rules finish at once. More than that, or AI scoring, waits in the queue.
            if (config('hunter.llm.provider') === 'none' && Opportunity::fitsInOneRequest()) {
                Artisan::call('hunter:reevaluate', ['--all' => true, '--now' => true]);
            } else {
                Artisan::queue('hunter:reevaluate', ['--all' => true]);
                // Only a flag: this request still runs in the old language.
                session()->flash('language_rewriting', true);
            }
        }

        return back();
    }
}

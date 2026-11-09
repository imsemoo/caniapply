<?php

namespace App\Http\Controllers;

use App\Models\CandidateProfile;
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
            // Rules cost nothing and finish at once. AI scoring spends quota, so it waits in the queue.
            config('hunter.llm.provider') === 'none'
                ? Artisan::call('hunter:reevaluate', ['--all' => true, '--now' => true])
                : Artisan::queue('hunter:reevaluate', ['--all' => true]);
        }

        return back();
    }
}

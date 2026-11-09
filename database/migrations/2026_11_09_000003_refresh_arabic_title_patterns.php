<?php

use App\Models\CandidateProfile;
use App\Sources\Connectors\BaaeedSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('candidate_profiles')->get() as $profile) {
            $tracks = json_decode($profile->tracks, true);
            $latinOnly = array_filter($tracks['web']['title_patterns'], fn (string $pattern) => ! preg_match('/\p{Arabic}/u', $pattern));
            $tracks['web']['title_patterns'] = array_values([...$latinOnly, ...CandidateProfile::ARABIC_TITLE_PATTERNS]);

            DB::table('candidate_profiles')->where('id', $profile->id)->update(['tracks' => json_encode($tracks, JSON_UNESCAPED_UNICODE)]);
        }

        // Baaeed ids became hashes because Arabic URLs overflow the column.
        $source = DB::table('job_sources')->where('key', 'baaeed')->value('id');

        if ($source !== null) {
            foreach (DB::table('opportunity_listings')->where('job_source_id', $source)->get(['id', 'url']) as $listing) {
                DB::table('opportunity_listings')->where('id', $listing->id)->update(['external_id' => BaaeedSource::idFor($listing->url)]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};

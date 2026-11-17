<?php

use App\Models\CandidateProfile;
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
    }

    public function down(): void
    {
        //
    }
};

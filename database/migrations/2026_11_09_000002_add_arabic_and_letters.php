<?php

use App\Models\CandidateProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->string('locale', 5)->default('ar');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->longText('cover_letter')->nullable();
            $table->string('cover_letter_engine')->nullable();
        });

        $defaults = CandidateProfile::defaults();

        foreach (DB::table('candidate_profiles')->get() as $profile) {
            $tracks = json_decode($profile->tracks, true);
            $tracks['web']['title_patterns'] = array_values(array_unique([...$tracks['web']['title_patterns'], ...CandidateProfile::ARABIC_TITLE_PATTERNS]));
            $tracks['web']['responsibilities'] = $defaults['tracks']['web']['responsibilities'];

            $thresholds = json_decode($profile->thresholds, true) + ['learning_max_points' => 8];

            DB::table('candidate_profiles')->where('id', $profile->id)->update([
                'tracks' => json_encode($tracks, JSON_UNESCAPED_UNICODE),
                'thresholds' => json_encode($thresholds),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['cover_letter', 'cover_letter_engine']);
        });

        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BY_JOB = ['opportunity_listings', 'alerts', 'opportunity_changes'];

    public function up(): void
    {
        // Starts with the same two columns, so the index it replaces has nothing left to do.
        Schema::table('opportunities', fn (Blueprint $table) => $table->dropIndex(['title_norm', 'company_norm']));
        DB::statement('CREATE INDEX opportunities_same_role_index ON opportunities (title_norm, company_norm, bucket, score DESC, id)');

        foreach (self::BY_JOB as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->index('opportunity_id'));
        }
    }

    public function down(): void
    {
        foreach (self::BY_JOB as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex(['opportunity_id']));
        }

        DB::statement('DROP INDEX opportunities_same_role_index');
        Schema::table('opportunities', fn (Blueprint $table) => $table->index(['title_norm', 'company_norm']));
    }
};

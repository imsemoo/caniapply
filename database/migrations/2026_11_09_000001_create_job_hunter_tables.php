<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('country');
            $table->string('headline')->default('Developer');
            $table->unsignedSmallInteger('years_experience');
            $table->json('tracks');
            $table->json('compensation');
            $table->json('scoring_weights');
            $table->json('thresholds');
            $table->json('exclusions');
            $table->timestamps();
        });

        Schema::create('job_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('type');
            $table->boolean('enabled')->default(true);
            $table->string('health')->default('unknown');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('last_fetched')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('failure_alerted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_source_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('running');
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('irrelevant')->default(0);
            $table->unsignedInteger('stale')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('merged')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['job_source_id', 'started_at']);
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('track')->default('web');
            $table->string('employment_type')->default('unknown');
            $table->string('title');
            $table->string('title_norm');
            $table->string('company')->nullable();
            $table->string('company_norm')->nullable();
            $table->index(['title_norm', 'company_norm']);
            $table->longText('description');
            $table->string('location_text')->nullable();

            $table->string('remote_scope')->default('unknown');
            $table->string('country_eligibility')->default('unknown');
            $table->text('eligibility_evidence')->nullable();
            $table->string('timezone_note')->nullable();
            $table->boolean('requires_relocation')->default(false);

            $table->string('salary_status')->default('undisclosed');
            $table->string('salary_origin')->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('salary_currency', 8)->nullable();
            $table->string('salary_period', 8)->nullable();
            $table->decimal('monthly_usd_min', 12, 2)->nullable();
            $table->decimal('monthly_usd_max', 12, 2)->nullable();

            $table->string('budget_type', 8)->nullable();
            $table->decimal('budget_usd_min', 12, 2)->nullable();
            $table->decimal('budget_usd_max', 12, 2)->nullable();
            $table->decimal('estimated_hours', 8, 1)->nullable();
            $table->decimal('effective_hourly_usd', 8, 2)->nullable();
            $table->json('client_signals')->nullable();

            $table->string('seniority')->nullable();
            $table->unsignedSmallInteger('required_years')->nullable();

            $table->unsignedTinyInteger('score')->nullable();
            $table->string('tier')->nullable();
            $table->string('bucket')->default('pending');
            $table->string('reject_reason')->nullable();
            $table->json('factors')->nullable();
            $table->json('analysis')->nullable();
            $table->string('evaluated_by')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->boolean('needs_evaluation')->default(true);

            $table->string('lifecycle')->default('new');
            $table->timestamp('lifecycle_changed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('last_rechecked_at')->nullable();
            $table->foreignId('primary_source_id')->nullable()->constrained('job_sources')->nullOnDelete();
            $table->string('primary_url', 2048)->nullable();
            $table->timestamps();

            $table->index(['company_norm', 'title_norm']);
            $table->index(['bucket', 'score']);
            $table->index('posted_at');
            $table->index('first_seen_at');
            $table->index('lifecycle');
            $table->index('needs_evaluation');
        });

        Schema::create('opportunity_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('url', 2048);
            $table->string('url_key', 64)->index();
            $table->string('content_hash', 64);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['job_source_id', 'external_id']);
        });

        Schema::create('opportunity_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('fingerprint', 64);
            $table->timestamp('sent_at');
            $table->unique(['kind', 'fingerprint']);
            $table->index('sent_at');
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('saved');
            $table->date('applied_on')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_details')->nullable();
            $table->text('interview_notes')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->string('salary_offered')->nullable();
            $table->text('notes')->nullable();
            $table->longText('strategy')->nullable();
            $table->string('strategy_engine')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('market_reports', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('figures');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'period_end']);
        });

        Schema::create('llm_usage', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['llm_usage', 'market_reports', 'applications', 'alerts', 'opportunity_changes', 'opportunity_listings', 'opportunities', 'source_runs', 'job_sources', 'candidate_profiles'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};

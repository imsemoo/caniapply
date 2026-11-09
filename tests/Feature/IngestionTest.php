<?php

namespace Tests\Feature;

use App\Enums\Lifecycle;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Models\OpportunityChange;
use App\Models\OpportunityListing;
use App\Services\Discovery\ListingIngestor;
use App\Sources\RawListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOpportunities;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use BuildsOpportunities, RefreshDatabase;

    private function raw(array $overrides = []): RawListing
    {
        return new RawListing(...$overrides + [
            'externalId' => 'a1',
            'url' => 'https://board.example.com/jobs/a1?utm_source=feed',
            'title' => 'Senior Laravel Developer',
            'company' => 'Acme Inc',
            'description' => str_repeat(self::GOOD_BODY.' ', 6),
            'location' => 'Worldwide',
            'postedAt' => now()->subHours(3),
            'remote' => true,
        ]);
    }

    private function ingest(RawListing $raw, string $source = 'himalayas'): string
    {
        return app(ListingIngestor::class)->ingest($this->source($source), $raw, CandidateProfile::current());
    }

    public function test_new_listing_is_stored_and_evaluated(): void
    {
        $this->assertSame(ListingIngestor::CREATED, $this->ingest($this->raw()));

        $job = Opportunity::query()->sole();
        $this->assertNotNull($job->score);
        $this->assertSame('Worldwide', $job->location_text);
        $this->assertFalse($job->needs_evaluation);
    }

    public function test_irrelevant_titles_are_dropped(): void
    {
        $this->assertSame(ListingIngestor::IRRELEVANT, $this->ingest($this->raw(['title' => 'Account Executive'])));
        $this->assertSame(0, Opportunity::query()->count());
    }

    public function test_old_postings_are_skipped(): void
    {
        $this->assertSame(ListingIngestor::STALE, $this->ingest($this->raw(['postedAt' => now()->subDays(60)])));
    }

    public function test_same_job_on_two_sources_merges_and_keeps_both_urls(): void
    {
        $this->ingest($this->raw());
        $outcome = $this->ingest($this->raw(['externalId' => 'b2', 'url' => 'https://other.example.org/x/9', 'company' => 'ACME, LLC']), 'remotive');

        $this->assertSame(ListingIngestor::MERGED, $outcome);
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(2, OpportunityListing::query()->count());
    }

    public function test_second_source_fills_in_a_missing_salary(): void
    {
        $this->ingest($this->raw());
        $this->ingest($this->raw(['externalId' => 'b2', 'url' => 'https://other.example.org/x/9', 'salaryMin' => 4000.0, 'salaryMax' => 4000.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'monthly']), 'remotive');

        $this->assertSame(4000.0, Opportunity::query()->sole()->monthly_usd_max);
    }

    public function test_tracking_parameters_do_not_split_one_url(): void
    {
        $this->assertSame(
            OpportunityListing::urlKey('https://www.example.com/jobs/1/?utm_source=a&ref=b'),
            OpportunityListing::urlKey('https://example.com/jobs/1'),
        );
    }

    public function test_urls_that_differ_only_by_id_query_stay_separate(): void
    {
        $this->assertNotSame(
            OpportunityListing::urlKey('https://news.ycombinator.com/item?id=1'),
            OpportunityListing::urlKey('https://news.ycombinator.com/item?id=2'),
        );
    }

    public function test_same_title_at_two_companies_stays_separate(): void
    {
        $this->ingest($this->raw());
        $this->ingest($this->raw(['externalId' => 'b2', 'url' => 'https://o.example.org/2', 'company' => 'Globex', 'description' => 'A completely different company, with a different product and different needs. '.str_repeat('Laravel work. ', 30)]), 'remotive');

        $this->assertSame(2, Opportunity::query()->count());
    }

    public function test_seeing_a_listing_again_marks_it_previously_discovered(): void
    {
        $this->ingest($this->raw());
        $this->assertSame(ListingIngestor::UNCHANGED, $this->ingest($this->raw()));

        $this->assertSame(Lifecycle::Seen, Opportunity::query()->sole()->lifecycle);
    }

    public function test_salary_change_is_recorded_and_the_job_is_updated(): void
    {
        $this->ingest($this->raw(['salaryMin' => 2000.0, 'salaryMax' => 2000.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'monthly']));
        $outcome = $this->ingest($this->raw(['salaryMin' => 3500.0, 'salaryMax' => 3500.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'monthly']));

        $this->assertSame(ListingIngestor::UPDATED, $outcome);
        $job = Opportunity::query()->sole();
        $this->assertSame(Lifecycle::Updated, $job->lifecycle);
        $this->assertSame(3500.0, $job->monthly_usd_max);
        $this->assertSame('salary', OpportunityChange::query()->sole()->field);
    }

    public function test_listing_returning_after_closure_is_reposted(): void
    {
        $this->ingest($this->raw());
        Opportunity::query()->update(['lifecycle' => Lifecycle::Closed->value, 'lifecycle_changed_at' => now()->subDays(10)]);

        $this->ingest($this->raw());

        $this->assertSame(Lifecycle::Reposted, Opportunity::query()->sole()->lifecycle);
    }

    public function test_recent_closure_does_not_flip_to_reposted(): void
    {
        $this->ingest($this->raw());
        Opportunity::query()->update(['lifecycle' => Lifecycle::Closed->value, 'lifecycle_changed_at' => now()->subDay()]);

        $this->ingest($this->raw());

        $this->assertSame(Lifecycle::Closed, Opportunity::query()->sole()->lifecycle);
    }

    public function test_partial_listing_keeps_everything_already_known(): void
    {
        $this->ingest($this->raw(['isFreelance' => true, 'budgetType' => 'fixed', 'budgetMin' => 300.0, 'budgetMax' => 500.0, 'budgetCurrency' => 'USD']));
        $outcome = $this->ingest($this->raw(['isFreelance' => true, 'description' => 'Short brief about Laravel.', 'isPartial' => true]));

        $this->assertSame(ListingIngestor::UNCHANGED, $outcome);
        $this->assertSame(500.0, Opportunity::query()->sole()->budget_usd_max);
    }

    public function test_regional_variants_stay_separate(): void
    {
        $this->ingest($this->raw(['location' => 'Remote, EMEA']));
        $this->ingest($this->raw(['externalId' => 'b2', 'url' => 'https://board.example.com/jobs/b2', 'location' => 'Remote, Americas']));

        $this->assertSame(2, Opportunity::query()->count());
    }

    public function test_refresh_does_not_undo_a_restriction_from_another_source(): void
    {
        $this->ingest($this->raw());
        Opportunity::query()->update(['country_eligibility' => 'excluded']);

        $this->ingest($this->raw(['description' => str_repeat('A rewritten description of this Laravel role for production. ', 8)]));

        $this->assertSame('excluded', Opportunity::query()->sole()->country_eligibility->value);
    }

    public function test_refresh_keeps_a_salary_it_does_not_mention(): void
    {
        $this->ingest($this->raw());
        Opportunity::query()->update(['salary_status' => 'disclosed', 'salary_min' => 3000, 'salary_max' => 3000, 'salary_currency' => 'USD', 'salary_period' => 'month', 'monthly_usd_min' => 3000, 'monthly_usd_max' => 3000]);

        $this->ingest($this->raw(['description' => str_repeat('A rewritten description of this Laravel role for production. ', 8)]));

        $this->assertSame(3000.0, Opportunity::query()->sole()->monthly_usd_max);
        $this->assertSame(0, OpportunityChange::query()->where('field', 'salary')->count());
    }

    public function test_appended_requirements_count_as_a_change(): void
    {
        $body = str_repeat('Build production Laravel applications with our friendly team every single day. ', 6);
        $this->ingest($this->raw(['description' => $body]));
        $this->ingest($this->raw(['description' => $body.str_repeat('You also need deep Kubernetes and Terraform operations knowledge across regions. ', 6)]));

        $this->assertSame('requirements', OpportunityChange::query()->sole()->field);
    }

    public function test_estimated_salaries_from_a_source_are_treated_as_undisclosed(): void
    {
        $this->ingest($this->raw(['salaryMin' => 50000.0, 'salaryMax' => 180000.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'year', 'salaryIsEstimate' => true]));

        $this->assertSame('undisclosed', Opportunity::query()->sole()->salary_status);
    }

    public function test_html_in_a_feed_is_stored_as_plain_text(): void
    {
        $this->ingest($this->raw(['description' => '<p>Laravel and PHP role.</p><script>steal()</script>'.str_repeat('<p>Building REST APIs for production.</p>', 5)]));

        $description = Opportunity::query()->sole()->description;
        $this->assertStringNotContainsString('<', $description);
        $this->assertStringNotContainsString('steal', $description);
    }
}

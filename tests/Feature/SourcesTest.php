<?php

namespace Tests\Feature;

use App\Enums\CountryEligibility;
use App\Enums\Lifecycle;
use App\Enums\RemoteScope;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Models\OpportunityListing;
use App\Services\Discovery\DiscoveryRunner;
use App\Services\Discovery\ListingRechecker;
use App\Sources\Connectors\HimalayasSource;
use App\Sources\Connectors\LaraJobsSource;
use App\Sources\Connectors\WeWorkRemotelySource;
use App\Sources\SourceFetchFailed;
use App\Sources\SourceHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsOpportunities;
use Tests\TestCase;

class SourcesTest extends TestCase
{
    use BuildsOpportunities, RefreshDatabase;

    private function collect(string $connector, array $options = []): array
    {
        return iterator_to_array((new $connector(app(SourceHttp::class), $options))->fetch(CandidateProfile::current()), false);
    }

    public function test_himalayas_searches_for_egypt_and_marks_eligibility(): void
    {
        Http::fake(['himalayas.app/*' => Http::response(['jobs' => [[
            'title' => 'Laravel Dev', 'companyName' => 'Acme', 'guid' => 'g1', 'applicationLink' => 'https://h.example/1',
            'description' => 'x', 'locationRestrictions' => [], 'minSalary' => 2000, 'maxSalary' => 2500, 'currency' => 'EUR', 'salaryPeriod' => 'monthly', 'pubDate' => 1793265368,
        ]]])]);

        $listings = $this->collect(HimalayasSource::class);

        $this->assertCount(1, $listings, 'the same job across queries appears once');
        $this->assertSame(CountryEligibility::Eligible, $listings[0]->sourceEligibility);
        $this->assertSame(RemoteScope::Worldwide, $listings[0]->sourceScope);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'country=EG'));
    }

    public function test_requests_identify_themselves_without_personal_data(): void
    {
        Http::fake(['*' => Http::response(['jobs' => []])]);
        $this->collect(HimalayasSource::class);

        Http::assertSent(fn (Request $r) => str_contains($r->header('User-Agent')[0], 'CanIApply') && ! str_contains($r->header('User-Agent')[0], '@'));
    }

    public function test_wwr_rss_splits_company_and_title(): void
    {
        Http::fake(['weworkremotely.com/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><item><title>Acme: Senior PHP Engineer</title><link>https://wwr.example/1</link><region>Anywhere in the World</region><pubDate>Mon, 09 Nov 2026 10:00:00 +0000</pubDate><description>&lt;p&gt;Body&lt;/p&gt;</description></item></channel></rss>')]);

        $listing = $this->collect(WeWorkRemotelySource::class)[0];

        $this->assertSame(['Acme', 'Senior PHP Engineer'], [$listing->company, $listing->title]);
        $this->assertSame(RemoteScope::Worldwide, $listing->sourceScope);
    }

    public function test_larajobs_namespaced_fields_are_read(): void
    {
        Http::fake(['larajobs.com/*' => Http::response('<?xml version="1.0"?><rss version="2.0" xmlns:job="https://larajobs.com" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><item><title>Laravel Developer</title><link>https://larajobs.com/job/1</link><pubDate>Sun, 08 Nov 2026 23:10:54 +0000</pubDate><job:location>Remote UK</job:location><job:job_type>FULL_TIME</job:job_type><job:salary>$90k</job:salary><job:company>Vaste</job:company><description>Body</description></item></channel></rss>')]);

        $listing = $this->collect(LaraJobsSource::class)[0];

        $this->assertSame('Vaste', $listing->company);
        $this->assertSame('Remote UK', $listing->location);
        $this->assertStringContainsString('Salary: $90k', $listing->description);
    }

    public function test_one_dead_board_does_not_stop_the_rest(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/dead/*' => Http::response([], 404),
            'boards-api.greenhouse.io/v1/boards/live/*' => Http::response(['jobs' => [['id' => 1, 'absolute_url' => 'https://g.example/1', 'title' => 'PHP Dev', 'content' => 'x']]]),
        ]);

        $this->assertCount(1, $this->collect(\App\Sources\Connectors\GreenhouseSource::class, ['boards' => ['dead', 'live']]));
    }

    public function test_a_dead_board_marks_the_source_degraded_and_blocks_closures(): void
    {
        config(['hunter.sources.greenhouse.options.boards' => ['dead', 'live']]);
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/dead/*' => Http::response([], 404),
            'boards-api.greenhouse.io/v1/boards/live/*' => Http::response(['jobs' => []]),
        ]);
        $source = $this->source('greenhouse', 'ats');
        $source->update(['last_success_at' => now()->subDays(5)]);
        $job = $this->opportunity(['primary_source_id' => $source->id, 'last_seen_at' => now()->subDays(4), 'primary_url' => null]);

        app(DiscoveryRunner::class)->run($source->fresh());
        app(ListingRechecker::class)->run();

        $this->assertSame('degraded', $source->fresh()->health);
        $this->assertSame(Lifecycle::New, $job->fresh()->lifecycle);
    }

    public function test_retries_on_rate_limit_then_succeeds(): void
    {
        config(['hunter.http_pause_ms' => 0]);
        Http::fake(['x.example/*' => Http::sequence()->push('', 429, ['Retry-After' => '0'])->push(['ok' => 1])]);

        $this->assertSame(['ok' => 1], app(SourceHttp::class)->json('https://x.example/a'));
        Http::assertSentCount(2);
    }

    public function test_failure_updates_health_and_alerts_once_after_the_limit(): void
    {
        config(['hunter.telegram.token' => 't', 'hunter.telegram.chat_id' => '1']);
        Http::fake(['remotive.com/*' => Http::response('', 503), 'api.telegram.org/*' => Http::response(['ok' => true])]);
        $source = $this->source('remotive');

        foreach (range(1, 4) as $ignored) {
            app(DiscoveryRunner::class)->run($source->fresh());
        }

        $this->assertSame('failing', $source->fresh()->health);
        $this->assertSame(4, $source->fresh()->consecutive_failures);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r['text'], 'SOURCE FAILING'));
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.telegram.org')));
    }

    public function test_recovery_clears_failure_state(): void
    {
        Http::fake(['remotive.com/*' => Http::response(['jobs' => []])]);
        $source = $this->source('remotive');
        $source->update(['health' => 'failing', 'consecutive_failures' => 5, 'failure_alerted_at' => now()]);

        app(DiscoveryRunner::class)->run($source->fresh());

        $this->assertSame('ok', $source->fresh()->health);
        $this->assertSame(0, $source->fresh()->consecutive_failures);
    }

    public function test_source_going_silent_is_reported_as_degraded(): void
    {
        Http::fake(['remotive.com/*' => Http::response(['jobs' => []])]);
        $source = $this->source('remotive');
        $source->update(['last_fetched' => 40]);

        app(DiscoveryRunner::class)->run($source->fresh());

        $this->assertSame('degraded', $source->fresh()->health);
    }

    public function test_source_interval_is_respected(): void
    {
        $source = $this->source('remotive');
        $source->update(['last_run_at' => now()->subHour()]);
        $this->assertFalse($source->isDue(6));

        $source->update(['last_run_at' => now()->subHours(6)]);
        $this->assertTrue($source->fresh()->isDue(6));
    }

    public function test_recheck_closes_on_404_but_not_on_a_bot_wall(): void
    {
        Http::fake(['93.184.216.34/gone' => Http::response('', 404), '93.184.216.34/wall' => Http::response('', 403)]);
        $gone = $this->opportunity(['score' => 90, 'primary_url' => 'https://93.184.216.34/gone']);
        $wall = $this->opportunity(['score' => 90, 'primary_url' => 'https://93.184.216.34/wall']);

        app(ListingRechecker::class)->run();

        $this->assertSame(Lifecycle::Closed, $gone->fresh()->lifecycle);
        $this->assertSame(Lifecycle::New, $wall->fresh()->lifecycle);
    }

    public function test_recheck_skips_listings_from_a_disabled_source(): void
    {
        Http::fake();
        $source = $this->source('freelancer');
        $source->update(['enabled' => false]);
        $this->opportunity(['score' => 90, 'primary_source_id' => $source->id, 'primary_url' => 'https://93.184.216.34/off']);

        app(ListingRechecker::class)->run();

        Http::assertNothingSent();
    }

    public function test_recheck_never_touches_private_addresses(): void
    {
        Http::fake();

        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data', 'http://10.0.0.5/', 'http://[::1]/'] as $url) {
            $this->assertNull(app(SourceHttp::class)->statusOf($url), $url);
        }

        Http::assertNothingSent();
    }

    public function test_expiry_date_and_age_flag_listings(): void
    {
        Http::fake();
        $byDate = $this->opportunity(['expires_at' => now()->subDay()]);
        $byAge = $this->opportunity(['first_seen_at' => now()->subDays(60), 'last_seen_at' => now()->subDays(20)]);
        $live = $this->opportunity(['first_seen_at' => now()->subDays(60), 'last_seen_at' => now()->subDay()]);

        app(ListingRechecker::class)->run();

        $this->assertSame(Lifecycle::Expired, $byDate->fresh()->lifecycle);
        $this->assertSame(Lifecycle::Expired, $byAge->fresh()->lifecycle);
        $this->assertSame(Lifecycle::New, $live->fresh()->lifecycle);
    }

    public function test_url_key_ignores_case_of_host(): void
    {
        $this->assertSame(OpportunityListing::urlKey('https://EXAMPLE.com/a'), OpportunityListing::urlKey('https://example.com/a/'));
    }
}

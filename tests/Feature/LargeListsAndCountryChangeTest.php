<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Bucket;
use App\Enums\CountryEligibility;
use App\Enums\RemoteScope;
use App\Jobs\EvaluateOpportunity;
use App\Jobs\RefreshEligibility;
use App\Livewire\Explorer;
use App\Livewire\Settings;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\Applications\ApplicationLetterWriter;
use App\Services\Discovery\EligibilityRefresher;
use App\Services\Discovery\ListingIngestor;
use App\Services\Llm\LlmFailed;
use App\Services\Normalization\TextCleaner;
use App\Services\Notifications\OpportunityAlerts;
use App\Sources\RawListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\BuildsOpportunities;
use Tests\TestCase;

class LargeListsAndCountryChangeTest extends TestCase
{
    use BuildsOpportunities, RefreshDatabase;

    private function qualified(string $title, int $score): Opportunity
    {
        return $this->opportunity(['title' => $title, 'bucket' => Bucket::Qualified, 'score' => $score, 'needs_evaluation' => false]);
    }

    public function test_a_tracked_posting_stands_for_its_repeats_even_with_a_lower_score(): void
    {
        $this->qualified('Best Scoring Copy', 90);
        $tracked = $this->qualified('The Copy I Applied To', 70);
        Application::query()->create(['opportunity_id' => $tracked->id, 'status' => ApplicationStatus::Applied]);

        Livewire::test(Explorer::class)->assertSee('The Copy I Applied To')->assertDontSee('Best Scoring Copy');
    }

    public function test_turning_down_the_best_posting_leaves_its_repeats_visible(): void
    {
        $best = $this->qualified('Best Scoring Copy', 90);
        $this->qualified('Second Copy', 70);
        Application::query()->create(['opportunity_id' => $best->id, 'status' => ApplicationStatus::Rejected]);

        Livewire::test(Explorer::class)->assertSee('Second Copy')->assertDontSee('Best Scoring Copy');
    }

    public function test_a_failed_recheck_keeps_the_old_country(): void
    {
        $before = CandidateProfile::current()->country;
        $this->mock(EligibilityRefresher::class, fn ($mock) => $mock->shouldReceive('run')->andThrow(new RuntimeException('disk full')));

        try {
            Livewire::test(Settings::class)->set('country', 'Brazil')->call('save');
            $this->fail('Expected the failure to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame($before, CandidateProfile::current()->country);
    }

    public function test_a_long_list_is_rechecked_in_the_background(): void
    {
        Bus::fake([RefreshEligibility::class]);
        foreach (range(1, 301) as $i) {
            $this->opportunity(['title_norm' => "job {$i}"]);
        }

        Livewire::test(Settings::class)->set('country', 'Brazil')->call('save')
            ->assertSet('countryQueued', true)->assertSet('countryRechecked', false)
            ->assertSee('in the background');

        Bus::assertDispatched(RefreshEligibility::class);
        $this->assertSame('Brazil', CandidateProfile::current()->country);
    }

    public function test_a_long_list_is_rewritten_in_the_background_when_the_language_changes(): void
    {
        Queue::fake();
        config(['hunter.llm.provider' => 'none']);
        $this->actingAs(User::factory()->create());
        foreach (range(1, 301) as $i) {
            $this->opportunity(['title_norm' => "job {$i}", 'needs_evaluation' => false]);
        }

        $this->from('/')->post('/language', ['locale' => 'ar'])->assertRedirect('/')->assertSessionHas('language_rewriting');
        $this->get('/')->assertSee('في الخلفية');

        $this->assertSame(0, Opportunity::query()->where('needs_evaluation', true)->count());
    }

    private function himalayasListing(): RawListing
    {
        return new RawListing(
            externalId: 'h-1',
            url: 'https://example.com/h-1',
            title: 'Senior Laravel Developer',
            company: 'Acme',
            description: self::GOOD_BODY,
            location: 'Germany, France, Spain, Italy, Poland, Greece and others',
            remote: true,
            sourceScope: RemoteScope::MultiCountry,
            sourceEligibility: CountryEligibility::Eligible,
            sourceEvidence: 'Himalayas lists this job when filtering by your country',
        );
    }

    public function test_a_cut_off_country_list_waits_for_the_source_and_then_confirms(): void
    {
        Bus::fake([EvaluateOpportunity::class]);
        $ingestor = app(ListingIngestor::class);
        $source = $this->source();

        CandidateProfile::current()->update(['country' => 'Germany']);
        $ingestor->ingest($source, $this->himalayasListing(), CandidateProfile::current());
        $job = Opportunity::query()->sole();
        $this->assertSame(CountryEligibility::Eligible, $job->country_eligibility);

        CandidateProfile::current()->update(['country' => 'Brazil']);
        app(EligibilityRefresher::class)->run();
        $this->assertSame(CountryEligibility::Unknown, $job->refresh()->country_eligibility);
        $this->assertStringContainsString('not confirmed yet', $job->eligibility_evidence);

        $this->assertSame(ListingIngestor::UPDATED, $ingestor->ingest($source, $this->himalayasListing(), CandidateProfile::current()));
        $this->assertSame(CountryEligibility::Eligible, $job->refresh()->country_eligibility);
    }

    public function test_demo_jobs_never_reach_telegram(): void
    {
        config(['hunter.telegram.token' => 't', 'hunter.telegram.chat_id' => '1']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $demo = $this->opportunity(['title' => 'Made Up Demo Job', 'bucket' => Bucket::Qualified, 'score' => 95, 'primary_source_id' => $this->source('demo', 'manual')->id]);
        $alerts = app(OpportunityAlerts::class);

        $alerts->afterEvaluation($demo);
        Http::assertNothingSent();

        $alerts->sendDailyDigest();
        Http::assertNotSent(fn (Request $request) => str_contains((string) $request['text'], 'Made Up Demo Job'));
    }

    public function test_the_weekly_report_waits_for_real_jobs(): void
    {
        config(['hunter.telegram.token' => 't', 'hunter.telegram.chat_id' => '1']);
        Http::fake();
        $this->source('demo', 'manual');

        $this->artisan('hunter:weekly-report')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_search_started_under_the_old_country_does_not_confirm_a_job(): void
    {
        Bus::fake([EvaluateOpportunity::class]);
        $ingestor = app(ListingIngestor::class);
        $source = $this->source();

        CandidateProfile::current()->update(['country' => 'Germany']);
        $ingestor->ingest($source, $this->himalayasListing(), CandidateProfile::current());
        $startedEarlier = CandidateProfile::current();

        CandidateProfile::current()->update(['country' => 'Brazil']);
        app(EligibilityRefresher::class)->run();
        $ingestor->ingest($source, $this->himalayasListing(), $startedEarlier);

        $this->assertSame(CountryEligibility::Unknown, Opportunity::query()->sole()->country_eligibility);
    }

    private function writeLetter(string $letter): Application
    {
        $path = tempnam(sys_get_temp_dir(), 'portfolio');
        file_put_contents($path, json_encode(['projects' => [
            ['slug' => 'atlas', 'name' => 'Atlas', 'tier' => 1, 'role' => 'Front-end and back-end', 'stack' => ['Laravel'], 'summary' => 'Statistics', 'url' => 'https://atlas.example.org/'],
        ]]));

        config(['hunter.letters.portfolio_json' => $path, 'hunter.letters.never_mention' => [], 'hunter.llm.provider' => 'anthropic', 'hunter.llm.api_key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $letter]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);

        return app(ApplicationLetterWriter::class)->write($this->opportunity(['analysis' => ['skills_matched' => ['Laravel']]]));
    }

    public static function lettersWithForeignLinks(): array
    {
        return [
            'backslash before the allowed host' => ['See https://evil.com\\@atlas.example.org/x Sam'],
            'credentials in front of the host' => ['See https://atlas.example.org@evil.com/ Sam'],
            'joined by a comma' => ['See https://atlas.example.org/,https://evil.com/x Sam'],
            'bare domain on a newer ending' => ['Visit evil.ai today. Sam'],
            'any country ending' => ['Apply at www.evil.ru/apply Sam'],
            'link shortener' => ['Fill in forms.gle/AbC123 please. Sam'],
            'allowed name as a prefix' => ['See atlas.example.org.evil.ru/login Sam'],
            'arabic label' => ['See موقع.com/form Sam'],
            'ideographic dot' => ['See evil。com/x Sam'],
            'full-width letters' => ['See ｅｖｉｌ．ｃｏｍ Sam'],
            'soft hyphen' => ["See evil\u{00AD}.com Sam"],
            'delimiter inside the authority' => ['See https://atlas.example.org)@evil%2Ecom/ Sam'],
            'numeric host' => ['See 1.2.3.4/x Sam'],
            'scheme without slashes' => ['See http:evil.ru Sam'],
            'mail link' => ['Write to mailto:hr@evil.ru Sam'],
        ];
    }

    #[DataProvider('lettersWithForeignLinks')]
    public function test_a_letter_with_a_foreign_link_is_refused(string $letter): void
    {
        $this->expectException(LlmFailed::class);

        $this->writeLetter($letter);
    }

    public function test_technology_names_are_not_mistaken_for_links(): void
    {
        $letter = $this->writeLetter('I have used ASP.NET, Node.js/Express, Socket.io and PHP 8.3, e.g. in [Atlas](https://atlas.example.org/), see https://atlas.example.org. Sam')->cover_letter;

        $this->assertStringContainsString('Socket.io', $letter);
    }

    public function test_bad_bytes_and_huge_posts_are_cleaned_without_losing_the_text(): void
    {
        $this->assertStringContainsString('Laravel', TextCleaner::plain("<p>Laravel \xFF role</p>"));
        $this->assertStringNotContainsString('<img', TextCleaner::plain('Laravel &amp;lt;img src=x onerror=alert(1)&amp;gt;'));

        $huge = TextCleaner::plain('<p>Laravel</p>'.str_repeat('<div>filler text</div>', 200000));
        $this->assertStringStartsWith('Laravel', $huge);
        $this->assertLessThanOrEqual(102400, strlen($huge));
    }

    public function test_job_lookups_have_their_indexes(): void
    {
        $this->assertContains('opportunities_same_role_index', Schema::getIndexListing('opportunities'));

        foreach (['opportunity_listings', 'alerts', 'opportunity_changes'] as $table) {
            $this->assertTrue(Schema::hasIndex($table, ['opportunity_id']), $table);
        }
    }
}

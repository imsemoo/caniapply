<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Bucket;
use App\Livewire\AddJob;
use App\Livewire\Applications;
use App\Livewire\Explorer;
use App\Livewire\OpportunityDetail;
use App\Livewire\Settings;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsOpportunities;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsOpportunities, RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }

    public function test_every_page_requires_sign_in(): void
    {
        foreach (['/', '/jobs', '/jobs/add', '/applications', '/analytics', '/sources', '/settings'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['email' => 'a@b.co', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'a@b.co', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_every_page_renders_with_data(): void
    {
        $this->signIn();
        $job = $this->opportunity(['score' => 88, 'tier' => 'Strong Match', 'bucket' => Bucket::Qualified, 'analysis' => ['recommendation' => 'Apply.'], 'factors' => ['technical' => ['score' => 90, 'weight' => 30]]]);
        $job->application()->create(['status' => 'applied']);

        foreach (['/', '/jobs', '/jobs/add', '/applications', '/analytics', '/sources', '/settings', '/jobs/'.$job->id] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_pages_render_on_an_empty_database(): void
    {
        $this->signIn();

        foreach (['/', '/jobs', '/applications', '/analytics', '/sources', '/settings'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_explorer_filters_narrow_the_list(): void
    {
        $this->signIn();
        $this->opportunity(['title' => 'High One', 'score' => 90, 'bucket' => Bucket::Qualified, 'monthly_usd_max' => 4000]);
        $this->opportunity(['title' => 'Low One', 'score' => 62, 'bucket' => Bucket::Qualified, 'monthly_usd_max' => 1600]);

        Livewire::test(Explorer::class)
            ->assertSee('High One')->assertSee('Low One')
            ->set('minScore', '80')->assertSee('High One')->assertDontSee('Low One')
            ->set('minScore', '')->set('maxSalary', '2000');
    }

    public function test_rejected_jobs_stay_out_of_the_default_list(): void
    {
        $this->signIn();
        $this->opportunity(['title' => 'Nope Job', 'score' => 30, 'bucket' => Bucket::Rejected]);

        Livewire::test(Explorer::class)->assertDontSee('Nope Job')->set('view', 'rejected')->assertSee('Nope Job');
    }

    public function test_marking_applied_records_it_and_contacts_nobody(): void
    {
        $this->signIn();
        Http::fake();
        $job = $this->opportunity(['score' => 90, 'bucket' => Bucket::Qualified]);

        Livewire::test(Explorer::class)->call('mark', $job->id, 'applied');

        $this->assertSame(ApplicationStatus::Applied, Application::query()->sole()->status);
        $this->assertTrue(Application::query()->sole()->applied_on->isToday());
        Http::assertNothingSent();
    }

    public function test_dismissed_job_leaves_the_list(): void
    {
        $this->signIn();
        $job = $this->opportunity(['title' => 'Dismiss Me', 'score' => 90, 'bucket' => Bucket::Qualified]);

        Livewire::test(Explorer::class)->assertSee('Dismiss Me')->call('mark', $job->id, 'rejected')->assertDontSee('Dismiss Me');
    }

    public function test_invalid_status_is_refused(): void
    {
        $this->signIn();
        $job = $this->opportunity();

        $this->expectException(\ValueError::class);
        Livewire::test(Explorer::class)->call('mark', $job->id, 'hacked');
    }

    public function test_application_details_save_and_validate(): void
    {
        $this->signIn();
        $job = $this->opportunity();

        Livewire::test(OpportunityDetail::class, ['opportunity' => $job])
            ->set('status', 'hr_interview')->set('contact_name', 'Sara')->set('follow_up_on', '2026-12-01')->set('notes', 'Call went well')
            ->call('saveApplication')->assertHasNoErrors()->assertSet('saved', true);

        $application = Application::query()->sole();
        $this->assertSame(ApplicationStatus::HrInterview, $application->status);
        $this->assertSame('Sara', $application->contact_name);

        Livewire::test(OpportunityDetail::class, ['opportunity' => $job])->set('status', 'nope')->call('saveApplication')->assertHasErrors('status');
    }

    public function test_opportunity_id_cannot_be_tampered_with(): void
    {
        $this->signIn();
        $job = $this->opportunity();
        $other = $this->opportunity();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(OpportunityDetail::class, ['opportunity' => $job])->set('opportunityId', $other->id);
    }

    public function test_strategy_without_an_llm_uses_only_the_analysis(): void
    {
        $this->signIn();
        $job = $this->opportunity(['analysis' => ['skills_matched' => ['Laravel'], 'skills_missing' => ['Redis'], 'concerns' => []]]);

        Livewire::test(OpportunityDetail::class, ['opportunity' => $job])->call('generateStrategy')->assertSee('Real project evidence for Laravel')->assertSee('Redis');
        $this->assertSame('rules', Application::query()->sole()->strategy_engine);
    }

    public function test_unsafe_urls_from_a_feed_are_not_linked_and_text_is_escaped(): void
    {
        $this->signIn();
        $job = $this->opportunity(['primary_url' => 'javascript:alert(1)', 'title' => '<img src=x onerror=alert(2)>', 'description' => '<script>alert(3)</script>', 'score' => 80, 'bucket' => Bucket::Qualified]);

        $this->get('/jobs/'.$job->id)->assertOk()
            ->assertDontSee('javascript:alert(1)', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('<script>alert(3)', false);
    }

    public function test_application_status_changes_from_the_tracker(): void
    {
        $this->signIn();
        $application = $this->opportunity()->application()->create(['status' => 'saved']);

        Livewire::test(Applications::class)->call('changeStatus', $application->id, 'assessment');

        $this->assertSame(ApplicationStatus::Assessment, $application->fresh()->status);
    }

    public function test_settings_save_and_weights_must_total_100(): void
    {
        $this->signIn();

        Livewire::test(Settings::class)->set('weights.technical', 90)->call('save')->assertHasErrors('weights');

        Livewire::test(Settings::class)->set('compensation.full_time_floor', 1800)->set('thresholds.immediate', 88)->call('save')->assertHasNoErrors();

        $profile = CandidateProfile::current();
        $this->assertEquals(1800, $profile->compensation['full_time_floor']);
        $this->assertSame(88, $profile->thresholds['immediate']);
    }

    public function test_changing_country_rechecks_open_jobs_in_place(): void
    {
        Queue::fake();
        $this->signIn();
        $job = $this->opportunity(['location_text' => 'Germany', 'remote_scope' => \App\Enums\RemoteScope::SpecificCountries, 'country_eligibility' => \App\Enums\CountryEligibility::Excluded]);

        Livewire::test(Settings::class)->set('country', 'Germany')->call('save')->assertHasNoErrors();

        $this->assertSame('Germany', CandidateProfile::current()->country);
        $this->assertSame(\App\Enums\CountryEligibility::Eligible, $job->fresh()->country_eligibility);
        $this->assertTrue($job->fresh()->needs_evaluation);
        Queue::assertPushed(\Illuminate\Foundation\Console\QueuedCommand::class);
    }

    public function test_an_unknown_country_is_refused(): void
    {
        $this->signIn();

        Livewire::test(Settings::class)->set("country", "Atlantis")->call("save")->assertHasErrors("country");
    }

    public function test_install_creates_one_account_and_the_demo_never_calls_out(): void
    {
        Http::fake();

        $this->artisan("hunter:install", ["--no-interaction" => true, "--country" => "Brazil", "--name" => "Sam Rivera", "--headline" => "Developer", "--email" => "sam@example.com", "--demo" => true])->assertSuccessful();

        $this->assertSame("Brazil", CandidateProfile::current()->country);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(8, Opportunity::query()->count());
        $this->assertSame(0, Opportunity::query()->where("needs_evaluation", true)->count());
        Http::assertNothingSent();

        $this->artisan("hunter:install", ["--no-interaction" => true, "--country" => "Brazil", "--name" => "Sam", "--headline" => "Developer"])->assertSuccessful();
        $this->assertSame(1, User::query()->count());
    }

    public function test_manual_job_is_scored_on_the_spot(): void
    {
        $this->signIn();

        Livewire::test(AddJob::class)
            ->set('title', 'Staff Basket Weaver')->set('url', 'https://linkedin.example/jobs/1')
            ->set('description', self::GOOD_BODY)->set('location', 'Worldwide')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $this->assertNotNull(Opportunity::query()->sole()->score, 'a pasted job is scored even when its title looks unrelated');
    }

    public function test_manual_job_rejects_a_non_http_link(): void
    {
        $this->signIn();

        Livewire::test(AddJob::class)->set('title', 'X')->set('url', 'javascript:alert(1)')->set('description', self::GOOD_BODY)->call('save')->assertHasErrors('url');
    }
}

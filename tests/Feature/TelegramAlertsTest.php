<?php

namespace Tests\Feature;

use App\Enums\Bucket;
use App\Models\Alert;
use App\Models\OpportunityChange;
use App\Services\Evaluation\OpportunityEvaluator;
use App\Services\Notifications\OpportunityAlerts;
use App\Services\Notifications\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\BuildsOpportunities;
use Tests\TestCase;

class TelegramAlertsTest extends TestCase
{
    use BuildsOpportunities, RefreshDatabase;

    private const TOKEN = '123:SECRET';

    protected function setUp(): void
    {
        parent::setUp();
        config(['hunter.telegram.token' => self::TOKEN, 'hunter.telegram.chat_id' => '42']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function scored(array $attributes = [])
    {
        return app(OpportunityEvaluator::class)->evaluate($this->opportunity($attributes));
    }

    private function alerts(): OpportunityAlerts
    {
        return app(OpportunityAlerts::class);
    }

    public function test_high_score_sends_one_immediate_alert_even_when_evaluated_twice(): void
    {
        $job = $this->scored();

        $this->alerts()->afterEvaluation($job);
        $this->alerts()->afterEvaluation($job);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'NEW HIGH-VALUE OPPORTUNITY')
            && str_contains($r['text'], 'Match: <b>'.$job->score.'/100</b>')
            && $r['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://example.com/job');
    }

    public function test_mid_score_waits_for_the_digest(): void
    {
        $job = $this->scored();
        $job->update(['score' => 80]);

        $this->alerts()->afterEvaluation($job);
        Http::assertNothingSent();
    }

    public function test_threshold_is_configurable(): void
    {
        $profile = \App\Models\CandidateProfile::current();
        $profile->update(['thresholds' => ['immediate' => 70] + $profile->thresholds]);

        $job = $this->scored();
        $job->update(['score' => 72]);

        $this->alerts()->afterEvaluation($job);
        Http::assertSentCount(1);
    }

    public function test_rejected_job_is_never_announced(): void
    {
        $this->alerts()->afterEvaluation($this->scored(['monthly_usd_min' => 900, 'monthly_usd_max' => 900]));
        Http::assertNothingSent();
    }

    public function test_feed_text_cannot_inject_markup(): void
    {
        $job = $this->scored(['title' => 'Laravel <b>Dev</b> & <a href="http://evil">click</a>']);

        $this->alerts()->afterEvaluation($job);

        Http::assertSent(fn (Request $r) => str_contains($r['text'], '&lt;b&gt;Dev') && ! str_contains($r['text'], '<a href="http://evil"') && ! str_contains($r['text'], 'http://evil'));
    }

    public function test_links_in_model_text_are_removed(): void
    {
        $job = $this->scored();
        $job->update(['analysis' => ['recommendation' => 'Apply at https://evil.example/x now', 'why_apply' => ['See www.evil.example'], 'concerns' => []]]);

        $this->alerts()->afterEvaluation($job);

        Http::assertSent(fn (Request $r) => ! str_contains($r['text'], 'evil.example'));
    }

    public function test_failed_send_releases_the_claim_so_it_retries(): void
    {
        config(['hunter.telegram.base_url' => 'https://flaky.example']);
        Http::fake(['flaky.example/*' => Http::sequence()->push(['ok' => false, 'description' => 'nope'], 500)->push(['ok' => true])]);
        $job = $this->scored();

        $this->alerts()->afterEvaluation($job);
        $this->assertSame(0, Alert::query()->count());

        $this->alerts()->afterEvaluation($job);
        $this->assertSame(1, Alert::query()->count());
    }

    public function test_burst_is_capped_and_overflow_goes_to_the_digest(): void
    {
        config(['hunter.telegram.max_immediate_per_run' => 2]);

        foreach (range(1, 4) as $n) {
            $this->alerts()->afterEvaluation($this->scored(['title' => "Laravel Developer {$n}", 'primary_url' => "https://example.com/{$n}"]));
        }

        Http::assertSentCount(2);
        $this->assertTrue($this->alerts()->sendDailyDigest());
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'DAILY DIGEST') && str_contains($r['text'], 'Laravel Developer 4'));
    }

    public function test_digest_never_repeats_a_job_and_splits_undisclosed(): void
    {
        $this->scored()->update(['score' => 80]);
        $undisclosed = $this->scored(['title' => 'Hidden Salary Laravel Role', 'salary_status' => 'undisclosed', 'monthly_usd_min' => null, 'monthly_usd_max' => null, 'primary_url' => 'https://example.com/u']);
        $undisclosed->update(['score' => 78, 'bucket' => Bucket::Undisclosed]);

        $this->alerts()->sendDailyDigest();
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Salary undisclosed, your call') && str_contains($r['text'], 'Hidden Salary'));

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->alerts()->sendDailyDigest();
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Nothing new reached'));
    }

    public function test_salary_change_on_an_announced_job_alerts_once(): void
    {
        $job = $this->scored();
        $this->alerts()->afterEvaluation($job);

        OpportunityChange::query()->create(['opportunity_id' => $job->id, 'field' => 'salary', 'old_value' => '$3,000', 'new_value' => '$4,000']);

        $this->alerts()->afterEvaluation($job);
        $this->alerts()->afterEvaluation($job);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'LISTING CHANGED') && str_contains($r['text'], '$4,000'));
    }

    public function test_job_already_in_a_digest_gets_no_new_alert_when_it_rises(): void
    {
        $job = $this->scored();
        $job->update(['score' => 80]);
        $this->alerts()->sendDailyDigest();

        $job->update(['score' => 90]);
        $this->alerts()->afterEvaluation($job);

        Http::assertSentCount(1);
    }

    public function test_failed_change_alert_is_retried_with_the_digest(): void
    {
        $job = $this->scored();
        $job->update(['score' => 60]);
        $job->application()->create(['status' => 'saved']);
        OpportunityChange::query()->create(['opportunity_id' => $job->id, 'field' => 'salary', 'old_value' => 'a', 'new_value' => 'b']);

        $this->alerts()->sendDailyDigest();

        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'LISTING CHANGED'));
        $this->assertNotNull(OpportunityChange::query()->sole()->alerted_at);
    }

    public function test_change_on_an_ignored_job_stays_silent(): void
    {
        $job = $this->scored();
        $job->update(['score' => 61]);
        OpportunityChange::query()->create(['opportunity_id' => $job->id, 'field' => 'salary', 'old_value' => 'a', 'new_value' => 'b']);

        $this->alerts()->afterEvaluation($job);

        Http::assertNothingSent();
    }

    public function test_bot_token_never_reaches_the_log_on_network_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'));
        Log::spy();

        $this->assertFalse(app(TelegramBot::class)->send('hello'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), 'SECRET'));
    }

    public function test_long_message_is_split_on_line_breaks(): void
    {
        app(TelegramBot::class)->send(implode("\n", array_fill(0, 400, str_repeat('x', 40))));

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $r) => mb_strlen($r['text']) <= 4000);
    }

    public function test_setup_validates_the_token_finds_the_chat_and_writes_env(): void
    {
        config(['hunter.telegram.token' => null, 'hunter.telegram.chat_id' => null, 'hunter.telegram.base_url' => 'https://setup.example']);
        Http::fake([
            'setup.example/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'my_hunter_bot']]),
            'setup.example/*/getUpdates*' => Http::response(['ok' => true, 'result' => [['message' => ['text' => 'STRANGER1', 'from' => ['first_name' => 'Eve'], 'chat' => ['id' => 666, 'type' => 'private']]], ['message' => ['text' => 'CODE1234', 'from' => ['first_name' => 'Owner'], 'chat' => ['id' => 777, 'type' => 'private']]], ['message' => ['text' => 'late', 'from' => ['first_name' => 'Eve'], 'chat' => ['id' => 666, 'type' => 'private']]]]]),
            'setup.example/*/sendMessage' => Http::response(['ok' => true]),
        ]);

        $directory = sys_get_temp_dir().'/hunter-env-'.uniqid();
        mkdir($directory);
        file_put_contents($directory.'/.env', "APP_NAME=x\nTELEGRAM_BOT_TOKEN=\nTELEGRAM_CHAT_ID=\n");
        $this->app->useEnvironmentPath($directory);
        \Illuminate\Support\Str::createRandomStringsUsing(fn () => 'CODE1234');

        $this->artisan('hunter:telegram --setup')
            ->expectsQuestion('Paste the token from BotFather (it stays hidden)', '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ')
            ->expectsConfirmation('Connect alerts to Owner?', 'yes')
            ->assertSuccessful();

        \Illuminate\Support\Str::createRandomStringsNormally();

        $env = file_get_contents($directory.'/.env');
        $this->assertStringContainsString('TELEGRAM_BOT_TOKEN=123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', $env);
        $this->assertStringContainsString('TELEGRAM_CHAT_ID=777', $env);
        $this->assertStringContainsString('APP_NAME=x', $env);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '777');
    }

    public function test_setup_refuses_a_malformed_token_without_calling_telegram(): void
    {
        $this->artisan('hunter:telegram --setup')
            ->expectsQuestion('Paste the token from BotFather (it stays hidden)', 'not a token')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_setup_leaves_env_alone_when_telegram_rejects_the_token(): void
    {
        config(['hunter.telegram.base_url' => 'https://reject.example']);
        Http::fake(['reject.example/*' => Http::response(['ok' => false], 401)]);

        $directory = sys_get_temp_dir().'/hunter-env-'.uniqid();
        mkdir($directory);
        file_put_contents($directory.'/.env', "TELEGRAM_BOT_TOKEN=\n");
        $this->app->useEnvironmentPath($directory);

        $this->artisan('hunter:telegram --setup')
            ->expectsQuestion('Paste the token from BotFather (it stays hidden)', '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ')
            ->assertFailed();

        $this->assertSame("TELEGRAM_BOT_TOKEN=\n", file_get_contents($directory.'/.env'));
    }

    public function test_nothing_is_attempted_without_credentials(): void
    {
        config(['hunter.telegram.token' => null]);
        $this->alerts()->afterEvaluation($this->scored());
        Http::assertNothingSent();
        $this->assertSame(0, Alert::query()->count());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\Bucket;
use App\Enums\CountryEligibility;
use App\Enums\EmploymentType;
use App\Enums\RemoteScope;
use App\Livewire\OpportunityDetail;
use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\User;
use App\Services\Applications\ApplicationLetterWriter;
use App\Services\Applications\PreferenceLearner;
use App\Services\Evaluation\OpportunityEvaluator;
use App\Services\Llm\LlmFailed;
use App\Services\Normalization\EligibilityDetector;
use App\Services\Normalization\RelevanceFilter;
use App\Services\Normalization\SalaryParser;
use App\Services\Normalization\SeniorityDetector;
use App\Services\Notifications\OpportunityAlerts;
use App\Services\Notifications\TelegramActions;
use App\Sources\RawListing;
use App\Support\HomeCountry;
use App\Sources\SourceHttp;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Concerns\BuildsOpportunities;
use Tests\TestCase;

class ArabicAndNewFeaturesTest extends TestCase
{
    use BuildsOpportunities, RefreshDatabase;

    private function arabic(): void
    {
        CandidateProfile::current()->update(['locale' => 'ar']);
        app()->setLocale('ar');
    }

    public function test_every_interface_sentence_has_an_arabic_translation(): void
    {
        $arabic = json_decode(file_get_contents(lang_path('ar.json')), true);
        $missing = [];

        foreach (['app', 'resources/views'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! str_ends_with((string) $file, '.php')) {
                    continue;
                }
                preg_match_all("/(?:__|trans_choice)\('((?:[^'\\\\]|\\\\.)+)'/", file_get_contents((string) $file), $matches);
                foreach ($matches[1] as $key) {
                    if (! isset($arabic[stripcslashes($key)])) {
                        $missing[] = stripcslashes($key);
                    }
                }
            }
        }

        foreach ([...array_keys(CandidateProfile::defaults()['exclusions']), ...array_keys(CandidateProfile::defaults()['tracks']['web']['responsibilities'])] as $key) {
            if (! isset($arabic[$key])) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function test_pages_render_right_to_left_in_arabic(): void
    {
        $this->arabic();
        $this->actingAs(User::factory()->create());
        $job = $this->opportunity(['score' => 88, 'tier' => 'Strong Match', 'bucket' => Bucket::Qualified, 'analysis' => ['recommendation' => 'x'], 'factors' => ['technical' => ['score' => 90, 'weight' => 30]]]);

        foreach (['/', '/jobs', '/jobs/add', '/applications', '/analytics', '/sources', '/settings', '/jobs/'.$job->id] as $path) {
            $this->get($path)->assertOk()->assertSee('dir="rtl"', false);
        }

        $this->get('/')->assertSee('نظرة عامة');
    }

    public function test_language_switch_saves_and_requires_sign_in(): void
    {
        $this->post('/language', ['locale' => 'ar'])->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        $this->from('/')->post('/language', ['locale' => 'ar'])->assertRedirect('/');
        $this->assertSame('ar', CandidateProfile::current()->fresh()->locale);

        $this->post('/language', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }

    public function test_reasons_and_alerts_follow_the_language(): void
    {
        $this->arabic();
        config(['hunter.telegram.token' => 't', 'hunter.telegram.chat_id' => '42']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $rejected = app(OpportunityEvaluator::class)->evaluate($this->opportunity(['monthly_usd_min' => 900, 'monthly_usd_max' => 900]));
        $this->assertStringContainsString('أقل من الحد', $rejected->reject_reason);

        app(OpportunityAlerts::class)->afterEvaluation(app(OpportunityEvaluator::class)->evaluate($this->opportunity()));
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'فرصة قوية جديدة') && $r['reply_markup']['inline_keyboard'][1][0]['text'] === 'حفظ');
    }

    public function test_arabic_salaries_are_read(): void
    {
        $parser = new SalaryParser;

        $this->assertSame(['USD', 'month', 2000.0], $this->salary($parser->parse('الراتب ٢٠٠٠ دولار شهريًا')));
        $this->assertSame(['EGP', 'month', 25000.0], $this->salary($parser->parse('المرتب: 20000 - 25000 جنيه')));
        $this->assertSame(['SAR', 'year', 96000.0], $this->salary($parser->parse('راتب 96,000 ريال سنويًا')));
        $this->assertNull($parser->parse('ميزانية المشروع 5000 دولار والراتب حسب الخبرة'));
        $this->assertNull($parser->parse('حققنا مبيعات 90000 دولار'));
    }

    private function salary(?array $parsed): array
    {
        return [$parsed['currency'], $parsed['period'], (float) $parsed['max']];
    }

    public function test_arabic_salary_edge_cases(): void
    {
        $parser = new SalaryParser;

        $this->assertSame(['USD', 'month', 5000.0], $this->salary($parser->parse('الراتب 5000 دولار شهريًا وإجازة سنوية 30 يومًا')));
        $this->assertSame(['GBP', 'month', 3000.0], $this->salary($parser->parse('الراتب 3000 جنيه إسترليني')));
        $this->assertSame(['EGP', 'month', 150000.0], $this->salary($parser->parse('الراتب 150.000 جنيه')));
        $this->assertSame(['USD', 'month', 800.0], $this->salary($parser->parse('الراتب 800$')));
        $this->assertNull($parser->parse('راتب تنافسي وتأمين صحي بقيمة 500 دولار'));
        $this->assertNull($parser->parse('الوظيفة مرتبطة بمبلغ 500 دولار'));

        $long = str_repeat('نص عربي طويل قبل الرقم في هذا الإعلان. ', 8);
        $this->assertSame(800.0, (float) $parser->parse($long.'الراتب 800 دولار شهريًا')['max']);
        $this->assertSame(800.0, (float) $parser->parse($long.'الراتب $800')['max']);
    }

    public function test_arabic_location_edge_cases(): void
    {
        $detector = new EligibilityDetector(HomeCountry::named('Egypt'));
        $detect = fn (string $body) => $detector->detect(new RawListing(externalId: '1', url: 'https://x.co', title: 'مطور ويب', location: 'MENA', description: $body, remote: true), $body)->country;

        $this->assertNotSame(CountryEligibility::Excluded, $detect('للمقيمين في السعودية أو مصر'));
        $this->assertNotSame(CountryEligibility::Excluded, $detect('يفضل المقيمين في الرياض'));
    }

    public function test_news_sites_do_not_read_as_years_of_experience(): void
    {
        $detector = new SeniorityDetector;

        $this->assertNull($detector->detect('مطور ويب', 'موقع أخبار يعمل منذ 12 عامًا')['years']);
        $this->assertNull($detector->detect('مطور ويب', '0 سنوات خبرة')['years']);
    }

    public function test_more_arabic_titles_are_relevant(): void
    {
        $filter = app(RelevanceFilter::class);
        $profile = CandidateProfile::current();

        foreach (['مطور الواجهات الأمامية', 'مطور الويب', 'مطور واجهة أمامية', 'مطور فول ستاك'] as $title) {
            $this->assertSame('web', $filter->trackFor($title, '', false, $profile), $title);
        }
    }

    public function test_button_in_a_group_chat_is_refused(): void
    {
        $job = $this->opportunity();
        $press = $this->press("job:{$job->id}:applied");
        $press['callback_query']['message']['chat']['type'] = 'group';
        $this->fakeUpdates($press);

        app(TelegramActions::class)->poll();

        $this->assertSame(0, Application::query()->count());
    }

    public function test_saving_everything_teaches_nothing(): void
    {
        $this->decide(10, 'saved', ['Vue.js']);

        $job = $this->opportunity(['remote_scope' => RemoteScope::Emea, 'employment_type' => EmploymentType::Contract, 'seniority' => null]);

        $this->assertSame(0, app(PreferenceLearner::class)->adjustmentFor($job, ['Vue.js'], CandidateProfile::current())['points']);
    }

    public function test_employer_rejections_after_applying_are_not_your_dislikes(): void
    {
        foreach (range(1, 8) as $n) {
            $this->opportunity(['analysis' => ['skills_matched' => ['Vue.js']]])->application()->create(['status' => 'rejected', 'applied_on' => today()]);
        }

        $this->assertSame(0, app(PreferenceLearner::class)->adjustmentFor($this->opportunity(), ['Vue.js'], CandidateProfile::current())['points']);
    }

    public function test_learning_cannot_lift_a_job_over_the_reject_line(): void
    {
        $this->decide(8, 'saved', ['Java']);
        $this->decide(8, 'rejected', ['WordPress']);

        $job = app(OpportunityEvaluator::class)->evaluate($this->opportunity(['title' => 'Senior Java Developer', 'description' => 'Java developer role.', 'remote_scope' => RemoteScope::Unknown, 'country_eligibility' => CountryEligibility::Unknown, 'salary_status' => 'undisclosed', 'monthly_usd_min' => null, 'monthly_usd_max' => null]));

        $this->assertLessThan(60, $job->score);
        $this->assertLessThanOrEqual(0, $job->analysis['preference']['points']);
    }

    public function test_arabic_seniority_and_years_are_read(): void
    {
        $detector = new SeniorityDetector;

        $this->assertSame(['level' => 'senior', 'years' => 5], $detector->detect('مطور ويب أول', 'خبرة لا تقل عن ٥ سنوات في Laravel'));
        $this->assertSame('junior', $detector->detect('مطور واجهات مبتدئ', '')['level']);
    }

    public function test_arabic_titles_are_relevant_and_others_are_not(): void
    {
        $filter = app(RelevanceFilter::class);
        $profile = CandidateProfile::current();

        $this->assertSame('web', $filter->trackFor('مطور Full Stack', 'Laravel', false, $profile));
        $this->assertSame('web', $filter->trackFor('مطور واجهات أمامية', '', false, $profile));
        $this->assertNull($filter->trackFor('أخصائي تسويق إلكتروني', '', false, $profile));
    }

    public function test_arabic_location_rules(): void
    {
        $detector = new EligibilityDetector(HomeCountry::named('Egypt'));
        $detect = fn (string $body, ?string $location) => $detector->detect(new RawListing(externalId: '1', url: 'https://x.co', title: 'مطور ويب', location: $location, description: $body, remote: true), $body);

        $this->assertSame(CountryEligibility::Excluded, $detect('الوظيفة للمقيمين في السعودية', 'عن بعد')->country);
        $this->assertSame(CountryEligibility::Likely, $detect('وظيفة عن بعد', 'Remote, Arab world (MENA)')->country);
        $this->assertSame(CountryEligibility::Eligible, $detect('وظيفة عن بعد', 'القاهرة، مصر')->country);
    }

    private function press(string $data, string $chat = '42'): array
    {
        return ['update_id' => 500, 'callback_query' => ['id' => 'p1', 'data' => $data, 'message' => ['message_id' => 9, 'chat' => ['id' => $chat, 'type' => 'private'], 'reply_markup' => ['inline_keyboard' => [[['text' => 'Open', 'url' => 'https://e.co']], [['text' => 'Save', 'callback_data' => 'x']]]]]]];
    }

    private function fakeUpdates(array $update): void
    {
        config(['hunter.telegram.token' => 't', 'hunter.telegram.chat_id' => '42']);
        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response(['result' => [$update]]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_telegram_button_records_the_decision_and_confirms(): void
    {
        $job = $this->opportunity();
        $this->fakeUpdates($this->press("job:{$job->id}:applied"));

        $this->assertSame(1, app(TelegramActions::class)->poll());

        $this->assertSame(ApplicationStatus::Applied, Application::query()->sole()->status);
        $this->assertSame(501, Cache::get('telegram:update-offset'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageReplyMarkup') && isset($r['reply_markup']['inline_keyboard'][0][0]['url']));
    }

    public function test_button_from_another_chat_is_refused(): void
    {
        $job = $this->opportunity();
        $this->fakeUpdates($this->press("job:{$job->id}:applied", '999'));

        app(TelegramActions::class)->poll();

        $this->assertSame(0, Application::query()->count());
    }

    public function test_forged_button_data_does_nothing(): void
    {
        $job = $this->opportunity();
        $this->fakeUpdates($this->press("job:{$job->id}:hacked"));

        app(TelegramActions::class)->poll();

        $this->assertSame(0, Application::query()->count());
    }

    private function decide(int $count, string $status, array $skills): void
    {
        foreach (range(1, $count) as $n) {
            $this->opportunity(['title' => "Past {$status} {$n}", 'analysis' => ['skills_matched' => $skills]])->application()->create(['status' => $status]);
        }
    }

    public function test_learning_needs_enough_decisions(): void
    {
        $this->decide(3, 'saved', ['Vue.js']);

        $result = app(PreferenceLearner::class)->adjustmentFor($this->opportunity(), ['Vue.js'], CandidateProfile::current());

        $this->assertSame(0, $result['points']);
    }

    public function test_learning_raises_liked_and_lowers_disliked_within_the_limit(): void
    {
        $this->decide(8, 'saved', ['Vue.js']);
        $this->decide(8, 'rejected', ['WordPress']);
        $profile = CandidateProfile::current();

        $liked = app(PreferenceLearner::class)->adjustmentFor($this->opportunity(['remote_scope' => RemoteScope::Emea, 'employment_type' => EmploymentType::Contract, 'seniority' => null]), ['Vue.js'], $profile);
        $disliked = app(PreferenceLearner::class)->adjustmentFor($this->opportunity(['remote_scope' => RemoteScope::Emea, 'employment_type' => EmploymentType::Contract, 'seniority' => null]), ['WordPress'], $profile);

        $this->assertGreaterThan(0, $liked['points']);
        $this->assertLessThanOrEqual(8, $liked['points']);
        $this->assertStringContainsString('Vue.js', $liked['reasons'][0]);
        $this->assertLessThan(0, $disliked['points']);
        $this->assertGreaterThanOrEqual(-8, $disliked['points']);
    }

    public function test_learning_never_rescues_a_hard_rejection(): void
    {
        $this->decide(10, 'saved', ['Laravel', 'PHP']);

        $job = app(OpportunityEvaluator::class)->evaluate($this->opportunity(['monthly_usd_min' => 900, 'monthly_usd_max' => 900]));

        $this->assertSame(Bucket::Rejected, $job->bucket);
        $this->assertSame(0, $job->analysis['preference']['points']);
    }

    public function test_limit_of_zero_turns_learning_off(): void
    {
        $this->decide(10, 'saved', ['Vue.js']);
        $profile = CandidateProfile::current();
        $profile->update(['thresholds' => ['learning_max_points' => 0] + $profile->thresholds]);

        $this->assertSame(0, app(PreferenceLearner::class)->adjustmentFor($this->opportunity(), ['Vue.js'], $profile->fresh())['points']);
    }

    private function useLetterFixtures(string $letter): void
    {
        $path = tempnam(sys_get_temp_dir(), 'portfolio');
        file_put_contents($path, json_encode(['projects' => [
            ['slug' => 'atlas', 'name' => 'Atlas', 'tier' => 1, 'lang' => 'ar', 'role' => 'Design, front-end and back-end', 'stack' => ['Laravel', 'Vue 3'], 'tags' => ['apps'], 'summary' => 'Statistics', 'url' => 'https://atlas.example.org/'],
            ['slug' => 'secret-client', 'name' => 'SecretClient', 'tier' => 1, 'role' => 'front-end', 'stack' => ['Laravel'], 'summary' => 's'],
            ['slug' => 'no-role', 'name' => 'NoRole', 'tier' => 1, 'role' => '', 'stack' => ['Laravel'], 'summary' => 's'],
        ]]));

        config(['hunter.letters.portfolio_json' => $path, 'hunter.letters.never_mention' => ['SecretClient'], 'hunter.llm.provider' => 'anthropic', 'hunter.llm.api_key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $letter]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
    }

    public function test_letter_uses_only_audited_build_projects(): void
    {
        $this->useLetterFixtures('Your dashboard needs fast data — here is how. https://atlas.example.org/ Sam');
        $job = $this->opportunity(['analysis' => ['skills_matched' => ['Laravel']]]);
        $writer = app(ApplicationLetterWriter::class);

        $this->assertSame(['Atlas'], array_column($writer->evidenceFor($job), 'name'));

        $application = $writer->write($job);

        $this->assertStringNotContainsString('—', $application->cover_letter);
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'Atlas') && ! str_contains($r['messages'][0]['content'], 'SecretClient') && ! str_contains($r['messages'][0]['content'], 'NoRole'));
    }

    public function test_letter_with_a_banned_name_or_foreign_link_is_refused(): void
    {
        $this->useLetterFixtures('I built SecretClient. Sam');
        $job = $this->opportunity(['analysis' => ['skills_matched' => ['Laravel']]]);

        try {
            app(ApplicationLetterWriter::class)->write($job);
            $this->fail('Expected the draft to be refused');
        } catch (LlmFailed) {
            $this->assertSame(0, Application::query()->count());
        }
    }

    public function test_letter_with_a_lookalike_or_bare_domain_is_refused(): void
    {
        foreach (['See https://atlas.example.org.evil.com/x Sam', 'Apply at evil.com/apply Sam'] as $letter) {
            $this->useLetterFixtures($letter);

            try {
                app(ApplicationLetterWriter::class)->write($this->opportunity(['analysis' => ['skills_matched' => ['Laravel']]]));
                $this->fail('Expected refusal: '.$letter);
            } catch (LlmFailed) {
                $this->assertSame(0, Application::query()->count());
            }

            Http::clearResolvedInstances();
            $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        }
    }

    public function test_letter_section_explains_itself_without_a_provider(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(OpportunityDetail::class, ['opportunity' => $this->opportunity()])
            ->assertSee('Needs an AI provider');
    }

    public function test_letter_edit_is_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $job = $this->opportunity();

        Livewire::test(OpportunityDetail::class, ['opportunity' => $job])->set('cover_letter', 'My own edit')->call('saveLetter')->assertSet('letterSaved', true);

        $this->assertSame('My own edit', $job->application()->first()->cover_letter);
    }
}

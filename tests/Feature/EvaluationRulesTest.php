<?php

namespace Tests\Feature;

use App\Enums\Bucket;
use App\Enums\CountryEligibility;
use App\Enums\EmploymentType;
use App\Enums\RemoteScope;
use App\Services\Evaluation\OpportunityEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsOpportunities;
use Tests\TestCase;

class EvaluationRulesTest extends TestCase
{
    use BuildsOpportunities, RefreshDatabase;

    private function evaluate(array $attributes = [])
    {
        return app(OpportunityEvaluator::class)->evaluate($this->opportunity($attributes));
    }

    public function test_good_full_time_job_qualifies_and_lists_its_reasons(): void
    {
        $job = $this->evaluate();

        $this->assertSame(Bucket::Qualified, $job->bucket);
        $this->assertGreaterThanOrEqual(85, $job->score);
        $this->assertContains('Laravel', $job->analysis['skills_matched']);
        $this->assertSame('rules', $job->evaluated_by);
        $this->assertSame(30, $job->factors['technical']['weight']);
    }

    public function test_full_time_below_the_floor_is_rejected(): void
    {
        $job = $this->evaluate(['monthly_usd_min' => 1400, 'monthly_usd_max' => 1499]);

        $this->assertSame(Bucket::Rejected, $job->bucket);
        $this->assertStringContainsString('floor', $job->reject_reason);
        $this->assertLessThan(60, $job->score);
    }

    public function test_exactly_the_floor_passes(): void
    {
        $this->assertNotSame(Bucket::Rejected, $this->evaluate(['monthly_usd_min' => 2500, 'monthly_usd_max' => 2500])->bucket);
    }

    public function test_a_very_wide_pay_range_gets_the_neutral_score(): void
    {
        $job = $this->evaluate(['monthly_usd_min' => 3000, 'monthly_usd_max' => 12000]);

        $this->assertSame(60, $job->factors['compensation']['score']);
    }

    public function test_salary_above_preferred_is_never_penalised(): void
    {
        $preferred = $this->evaluate(['monthly_usd_min' => 6000, 'monthly_usd_max' => 6000]);
        $higher = $this->evaluate(['monthly_usd_min' => 12000, 'monthly_usd_max' => 12000]);

        $this->assertSame($preferred->factors['compensation']['score'], $higher->factors['compensation']['score']);
    }

    public function test_undisclosed_salary_is_kept_in_its_own_bucket_with_no_figure(): void
    {
        $job = $this->evaluate(['salary_status' => 'undisclosed', 'salary_min' => null, 'salary_max' => null, 'monthly_usd_min' => null, 'monthly_usd_max' => null]);

        $this->assertSame(Bucket::Undisclosed, $job->bucket);
        $this->assertSame('Undisclosed', $job->compensationLabel());
        $this->assertNull($job->monthly_usd_max);
    }

    public function test_hourly_contract_with_no_hours_gets_no_monthly_figure(): void
    {
        $job = $this->evaluate(['employment_type' => EmploymentType::Contract, 'salary_min' => 30, 'salary_max' => 30, 'salary_period' => 'hour', 'monthly_usd_min' => null, 'monthly_usd_max' => null]);

        $this->assertNull($job->monthly_usd_max);
        $this->assertStringContainsString('not calculated', $job->factors['compensation']['note']);
    }

    public function test_remote_job_excluding_egypt_is_rejected(): void
    {
        $job = $this->evaluate(['remote_scope' => RemoteScope::UnitedStates, 'country_eligibility' => CountryEligibility::Excluded]);

        $this->assertSame('Remote role excludes your country', $job->reject_reason);
    }

    public function test_relocation_is_rejected(): void
    {
        $this->assertSame('Requires relocation', $this->evaluate(['requires_relocation' => true])->reject_reason);
    }

    public function test_excluded_employer_location_is_rejected(): void
    {
        $this->assertStringStartsWith('Excluded', $this->evaluate(['description' => self::GOOD_BODY."\nWe run an online casino."])->reject_reason);
    }

    public function test_hybrid_cairo_needs_the_floor_and_onsite_needs_exceptional_pay(): void
    {
        $base = ['country_eligibility' => CountryEligibility::Eligible, 'monthly_usd_min' => 2600, 'monthly_usd_max' => 2600];

        $this->assertNotSame('Hybrid in your country below the salary floor', $this->evaluate($base + ['remote_scope' => RemoteScope::Hybrid])->reject_reason);
        $this->assertSame('Full-time pay below the $2,500 floor', $this->evaluate(['remote_scope' => RemoteScope::Hybrid, 'monthly_usd_min' => 1200, 'monthly_usd_max' => 1200])->reject_reason);
        $this->assertSame('On-site in your country without exceptional pay', $this->evaluate($base + ['remote_scope' => RemoteScope::Onsite])->reject_reason);
        $this->assertNotSame('On-site in your country without exceptional pay', $this->evaluate(['remote_scope' => RemoteScope::Onsite, 'monthly_usd_min' => 6500, 'monthly_usd_max' => 6500])->reject_reason);
    }

    public function test_suspicious_employer_is_flagged_not_rejected(): void
    {
        $job = $this->evaluate(['description' => self::GOOD_BODY."\nA small registration fee applies before you start."]);

        $this->assertSame(Bucket::Flagged, $job->bucket);
        $this->assertContains('Asks candidates to pay a fee', $job->analysis['red_flags']);
    }

    public function test_security_track_is_not_inflated_by_web_experience(): void
    {
        $job = $this->evaluate(['track' => 'security', 'title' => 'Senior SOC Analyst', 'seniority' => 'senior', 'required_years' => 5, 'description' => 'Senior SOC analyst with 5+ years of experience in SIEM, Splunk and incident response.']);

        $this->assertLessThan(60, $job->score);
        $this->assertSame(Bucket::Rejected, $job->bucket);
    }

    public function test_foreign_stack_title_scores_low_on_skills(): void
    {
        $job = $this->evaluate(['title' => 'Senior Java Developer', 'description' => 'Java developer. PHP and JavaScript are used in some internal tools.']);

        $this->assertLessThanOrEqual(25, $job->factors['technical']['score']);
    }

    public function test_small_freelance_project_is_not_rejected_for_its_total(): void
    {
        $job = $this->evaluate($this->freelance(['budget_usd_min' => 500, 'budget_usd_max' => 500, 'estimated_hours' => 10]));

        $this->assertNotSame(Bucket::Rejected, $job->bucket);
        $this->assertEqualsWithDelta(50, $job->effective_hourly_usd, 0.01);
        $this->assertSame(100, $job->factors['compensation']['score']);
    }

    public function test_freelance_with_an_unrealistic_rate_is_rejected(): void
    {
        $job = $this->evaluate($this->freelance(['budget_usd_min' => 200, 'budget_usd_max' => 200, 'estimated_hours' => 100]));

        $this->assertSame('Unrealistic budget for the scope', $job->reject_reason);
    }

    public function test_freelance_with_unknown_hours_never_reaches_immediate_alert_level(): void
    {
        $job = $this->evaluate($this->freelance(['budget_usd_min' => 2000, 'budget_usd_max' => 5000]));

        $this->assertLessThan(85, $job->score);
    }

    public function test_no_network_call_is_made_without_a_provider(): void
    {
        Http::fake();
        $this->evaluate();
        Http::assertNothingSent();
    }

    private function freelance(array $extra): array
    {
        return $extra + [
            'employment_type' => EmploymentType::Freelance, 'company' => null, 'company_norm' => null,
            'budget_type' => 'fixed', 'salary_status' => 'undisclosed', 'salary_min' => null, 'salary_max' => null,
            'monthly_usd_min' => null, 'monthly_usd_max' => null, 'seniority' => null,
            'description' => self::GOOD_BODY.' Fix the checkout bug and add one report page.',
        ];
    }
}

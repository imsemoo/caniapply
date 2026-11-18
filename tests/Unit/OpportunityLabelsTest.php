<?php

namespace Tests\Unit;

use App\Enums\EmploymentType;
use App\Models\Opportunity;
use Tests\TestCase;

class OpportunityLabelsTest extends TestCase
{
    private function job(array $attributes): Opportunity
    {
        return (new Opportunity)->forceFill($attributes + ['employment_type' => EmploymentType::FullTime]);
    }

    public function test_a_quoted_english_sentence_is_isolated_after_an_arabic_label(): void
    {
        app()->setLocale('ar');
        $job = $this->job(['eligibility_evidence' => 'Post says: "You must be located in the United States"']);

        $this->assertSame("ورد في الإعلان: \u{2068}\"You must be located in the United States\"\u{2069}", $job->evidenceLabel());
    }

    public function test_the_english_evidence_label_is_unchanged(): void
    {
        app()->setLocale('en');
        $job = $this->job(['eligibility_evidence' => 'Post says: "You must be located in the United States"']);

        $this->assertSame('Post says: "You must be located in the United States"', $job->evidenceLabel());
    }

    public function test_a_salary_range_does_not_break_after_its_dash(): void
    {
        app()->setLocale('en');
        $job = $this->job(['salary_status' => 'disclosed', 'salary_min' => 9000, 'salary_max' => 11000, 'salary_currency' => 'USD', 'salary_period' => 'month']);

        $this->assertStringContainsString("9,000–\u{2060}11,000", $job->compensationLabel());
    }
}

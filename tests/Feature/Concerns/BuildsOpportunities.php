<?php

namespace Tests\Feature\Concerns;

use App\Enums\CountryEligibility;
use App\Enums\EmploymentType;
use App\Enums\Lifecycle;
use App\Enums\RemoteScope;
use App\Models\JobSource;
use App\Models\Opportunity;

trait BuildsOpportunities
{
    protected const GOOD_BODY = "We need a senior Laravel and PHP developer with Livewire, MySQL and JavaScript.\nYou will maintain production web applications, dashboards and REST APIs.";

    protected function source(string $key = 'himalayas', string $type = 'api'): JobSource
    {
        return JobSource::query()->firstOrCreate(['key' => $key], ['label' => ucfirst($key), 'type' => $type]);
    }

    protected function opportunity(array $attributes = []): Opportunity
    {
        return Opportunity::query()->create($attributes + [
            'track' => 'web',
            'employment_type' => EmploymentType::FullTime,
            'title' => 'Senior Laravel Developer',
            'title_norm' => 'senior laravel developer',
            'company' => 'Acme',
            'company_norm' => 'acme',
            'description' => self::GOOD_BODY,
            'remote_scope' => RemoteScope::Worldwide,
            'country_eligibility' => CountryEligibility::Eligible,
            'seniority' => 'senior',
            'salary_status' => 'disclosed',
            'salary_min' => 3000,
            'salary_max' => 3000,
            'salary_currency' => 'USD',
            'salary_period' => 'month',
            'monthly_usd_min' => 3000,
            'monthly_usd_max' => 3000,
            'posted_at' => now()->subHours(6),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'lifecycle' => Lifecycle::New,
            'primary_url' => 'https://example.com/job',
        ]);
    }
}

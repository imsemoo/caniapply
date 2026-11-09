<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateOpportunity;
use App\Models\CandidateProfile;
use App\Models\JobSource;
use App\Models\Opportunity;
use App\Services\Discovery\ListingIngestor;
use App\Services\Evaluation\OpportunityEvaluator;
use App\Sources\RawListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class HunterDemo extends Command
{
    protected $signature = 'hunter:demo';

    protected $description = 'Run fictional listings through the real pipeline, so that the dashboard has something to show';

    private const STACK = "You will build and maintain production web applications in Laravel, PHP, Livewire, MySQL and JavaScript. You will work on dashboards, admin panels and API integrations, and take part in code reviews.\nWe want 5 or more years of experience.";

    public function handle(ListingIngestor $ingestor, OpportunityEvaluator $evaluator): int
    {
        // Scored right here. Queued scoring would send made-up jobs to Telegram.
        Bus::fake([EvaluateOpportunity::class]);

        $profile = CandidateProfile::current();
        // Disabled, so that nothing ever tries to fetch these made-up links.
        $source = JobSource::query()->updateOrCreate(['key' => 'demo'], ['label' => 'Demo data', 'type' => 'manual', 'enabled' => false, 'health' => 'ok']);

        foreach ($this->listings($profile->country) as $slug => $listing) {
            $ingestor->ingest($source, new RawListing(...$listing + [
                'externalId' => 'demo-'.$slug,
                'url' => 'https://example.com/demo/'.$slug,
                'postedAt' => now()->subHours(random_int(2, 60)),
                'remote' => true,
            ]), $profile);
        }

        Opportunity::query()->where('needs_evaluation', true)->each(fn (Opportunity $opportunity) => $evaluator->evaluate($opportunity));

        $this->info(Opportunity::query()->whereRelation('listings', 'job_source_id', $source->id)->count().' fictional jobs loaded. Every company and link is made up.');

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> */
    private function listings(string $country): array
    {
        return [
            'northwind' => [
                'title' => 'Senior Full-Stack Laravel Developer',
                'company' => 'Northwind Labs (demo)',
                'location' => 'Worldwide',
                'description' => "Work from anywhere in the world. We hire globally.\n".self::STACK,
                'salaryMin' => 5000.0, 'salaryMax' => 6500.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'month', 'employmentType' => 'full_time',
            ],
            'quill' => [
                'title' => 'Senior Laravel Engineer',
                'company' => 'Quill Publishing (demo)',
                'location' => $country,
                'description' => "This role is open to candidates living in {$country}.\n".self::STACK,
                'salaryMin' => 4000.0, 'salaryMax' => 5000.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'month', 'employmentType' => 'full_time',
            ],
            'harbor' => [
                'title' => 'Senior PHP Developer',
                'company' => 'Harbor Analytics (demo)',
                'location' => 'Remote',
                'description' => "This is a fully remote role.\n".self::STACK."\nYou must be located in the United States and authorized to work in the United States.",
                'salaryMin' => 9000.0, 'salaryMax' => 11000.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'month', 'employmentType' => 'full_time',
            ],
            'fennel' => [
                'title' => 'Full Stack Developer',
                'company' => 'Fennel Commerce (demo)',
                'location' => 'EMEA',
                'description' => "Remote across the region.\n".self::STACK,
                'salaryMin' => 3800.0, 'salaryMax' => 4500.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'month', 'employmentType' => 'full_time',
            ],
            'orbit' => [
                'title' => 'Senior Web Developer',
                'company' => 'Orbit Media (demo)',
                'location' => 'Remote',
                'description' => "Remote position. Salary is discussed later.\n".self::STACK,
                'employmentType' => 'full_time',
            ],
            'tallow' => [
                'title' => 'Senior Laravel Developer',
                'company' => 'Tallow Systems (demo)',
                'location' => 'Hybrid - Berlin, Germany',
                'description' => "Three days a week in our Berlin office.\n".self::STACK,
                'salaryMin' => 6000.0, 'salaryMax' => 7000.0, 'salaryCurrency' => 'EUR', 'salaryPeriod' => 'month', 'employmentType' => 'full_time',
                'remote' => false,
            ],
            'kite' => [
                'title' => 'Web Developer',
                'company' => 'Kite Software (demo)',
                'location' => 'Worldwide',
                'description' => "Work from anywhere in the world.\n".self::STACK,
                'salaryMin' => 900.0, 'salaryMax' => 1000.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'month', 'employmentType' => 'full_time',
            ],
            'brightside' => [
                'title' => 'Senior Full-Stack Developer',
                'company' => 'Brightside Health (demo)',
                'location' => 'Worldwide',
                'description' => "Remote, but you must overlap with EST hours every day.\n".self::STACK,
                'salaryMin' => 5500.0, 'salaryMax' => 6000.0, 'salaryCurrency' => 'USD', 'salaryPeriod' => 'month', 'employmentType' => 'full_time',
            ],
        ];
    }
}

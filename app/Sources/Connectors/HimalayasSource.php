<?php

namespace App\Sources\Connectors;

use App\Enums\CountryEligibility;
use App\Enums\RemoteScope;
use App\Models\CandidateProfile;
use App\Sources\RawListing;
use App\Support\HomeCountry;

/** The search endpoint filters by country, so every hit is confirmed open to the candidate's country. */
class HimalayasSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $seen = [];
        $queries = $profile->searchQueries();

        foreach ($this->eachTolerant($queries, fn (string $q) => $this->http->json('https://himalayas.app/jobs/api/search', ['q' => $q, 'country' => HomeCountry::named($profile->country)->code, 'sort' => 'recent'])) as $page) {
            foreach ($page['jobs'] ?? [] as $job) {
                $id = $job['guid'] ?? $job['applicationLink'] ?? null;
                if ($id === null || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                yield $this->listing($job, $id);
            }
        }
    }

    private function listing(array $job, string $id): RawListing
    {
        $countries = $job['locationRestrictions'] ?? [];
        $scope = match (true) {
            $countries === [] => RemoteScope::Worldwide,
            count($countries) === 1 => RemoteScope::SpecificCountries,
            default => RemoteScope::MultiCountry,
        };

        return new RawListing(
            externalId: $id,
            url: $job['applicationLink'] ?? $id,
            title: $job['title'] ?? '',
            company: $job['companyName'] ?? null,
            description: $job['description'] ?? $job['excerpt'] ?? '',
            location: $countries === [] ? 'Worldwide' : implode(', ', array_slice($countries, 0, 6)).(count($countries) > 6 ? ' and others' : ''),
            postedAt: $this->date($job['pubDate'] ?? null),
            expiresAt: $this->date($job['expiryDate'] ?? null),
            employmentType: $job['employmentType'] ?? null,
            tags: array_merge($job['categories'] ?? [], $job['seniority'] ?? []),
            salaryMin: isset($job['minSalary']) ? (float) $job['minSalary'] : null,
            salaryMax: isset($job['maxSalary']) ? (float) $job['maxSalary'] : null,
            salaryCurrency: $job['currency'] ?? null,
            salaryPeriod: $job['salaryPeriod'] ?? null,
            remote: true,
            sourceScope: $scope,
            sourceEligibility: CountryEligibility::Eligible,
            sourceEvidence: 'Himalayas lists this job when filtering by your country',
        );
    }
}

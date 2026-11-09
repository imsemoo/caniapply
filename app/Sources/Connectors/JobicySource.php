<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class JobicySource extends Connector
{
    private const INDUSTRIES = ['dev', 'engineering'];

    public function fetch(CandidateProfile $profile): iterable
    {
        $seen = [];

        foreach ($this->eachTolerant(self::INDUSTRIES, fn (string $industry) => $this->http->json('https://jobicy.com/api/v2/remote-jobs', ['count' => 100, 'industry' => $industry])) as $page) {
            foreach ($page['jobs'] ?? [] as $job) {
                if (isset($seen[$job['id']])) {
                    continue;
                }
                $seen[$job['id']] = true;

                yield new RawListing(
                    externalId: (string) $job['id'],
                    url: $job['url'],
                    title: html_entity_decode($job['jobTitle'] ?? ''),
                    company: $job['companyName'] ?? null,
                    description: $job['jobDescription'] ?? $job['jobExcerpt'] ?? '',
                    location: $job['jobGeo'] ?? null,
                    postedAt: $this->date($job['pubDate'] ?? null),
                    employmentType: implode(' ', (array) ($job['jobType'] ?? [])),
                    tags: array_filter([$job['jobLevel'] ?? null, ...(array) ($job['jobIndustry'] ?? [])]),
                    salaryMin: isset($job['salaryMin']) ? (float) $job['salaryMin'] : null,
                    salaryMax: isset($job['salaryMax']) ? (float) $job['salaryMax'] : null,
                    salaryCurrency: $job['salaryCurrency'] ?? null,
                    salaryPeriod: $job['salaryPeriod'] ?? null,
                    remote: true,
                );
            }
        }
    }
}

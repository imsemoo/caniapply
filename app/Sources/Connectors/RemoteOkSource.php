<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class RemoteOkSource extends Connector
{
    private const TAGS = ['php', 'laravel', 'full-stack', 'front-end', 'wordpress'];

    public function fetch(CandidateProfile $profile): iterable
    {
        $seen = [];

        foreach ($this->eachTolerant(self::TAGS, fn (string $tag) => $this->http->json('https://remoteok.com/api', ['tag' => $tag])) as $page) {
            foreach ($page as $job) {
                // The first element of the array is the legal notice.
                if (! isset($job['id'], $job['position']) || isset($seen[$job['id']])) {
                    continue;
                }
                $seen[$job['id']] = true;

                yield new RawListing(
                    externalId: (string) $job['id'],
                    url: $job['url'] ?? $job['apply_url'],
                    title: html_entity_decode($job['position']),
                    company: $job['company'] ?? null,
                    description: $job['description'] ?? '',
                    location: ($job['location'] ?? '') !== '' ? $job['location'] : null,
                    postedAt: $this->date($job['epoch'] ?? $job['date'] ?? null),
                    tags: $job['tags'] ?? [],
                    salaryMin: ! empty($job['salary_min']) ? (float) $job['salary_min'] : null,
                    salaryMax: ! empty($job['salary_max']) ? (float) $job['salary_max'] : null,
                    salaryCurrency: 'USD',
                    salaryPeriod: 'year',
                    remote: true,
                    salaryIsEstimate: true,
                );
            }
        }
    }
}

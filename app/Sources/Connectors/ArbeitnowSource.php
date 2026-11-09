<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class ArbeitnowSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $body = $this->http->json('https://www.arbeitnow.com/api/job-board-api');

        foreach ($body['data'] ?? [] as $job) {
            // The board is mostly on-site German roles; only remote ones can qualify.
            if (empty($job['remote'])) {
                continue;
            }

            yield new RawListing(
                externalId: $job['slug'],
                url: $job['url'],
                title: $job['title'] ?? '',
                company: $job['company_name'] ?? null,
                description: $job['description'] ?? '',
                location: $job['location'] ?? null,
                postedAt: $this->date($job['created_at'] ?? null),
                employmentType: implode(' ', $job['job_types'] ?? []),
                tags: $job['tags'] ?? [],
                remote: true,
            );
        }
    }
}

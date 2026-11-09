<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

/** Remotive asks for at most four calls a day; min_interval_hours enforces that. */
class RemotiveSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $body = $this->http->json('https://remotive.com/api/remote-jobs', ['category' => 'software-dev']);

        foreach ($body['jobs'] ?? [] as $job) {
            yield new RawListing(
                externalId: (string) $job['id'],
                url: $job['url'],
                title: $job['title'] ?? '',
                company: $job['company_name'] ?? null,
                description: $job['description'] ?? '',
                location: $job['candidate_required_location'] ?? null,
                postedAt: $this->date($job['publication_date'] ?? null),
                employmentType: $job['job_type'] ?? null,
                tags: $job['tags'] ?? [],
                remote: true,
            );
        }
    }
}

<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class GreenhouseSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $boards = $this->options['boards'] ?? [];

        foreach ($this->eachTolerant($boards, fn (string $board) => [$board, $this->http->json("https://boards-api.greenhouse.io/v1/boards/{$board}/jobs", ['content' => 'true'])]) as [$board, $body]) {
            foreach ($body['jobs'] ?? [] as $job) {
                $location = $job['location']['name'] ?? null;

                yield new RawListing(
                    externalId: $board.':'.$job['id'],
                    url: $job['absolute_url'],
                    title: $job['title'] ?? '',
                    company: $job['company_name'] ?? $board,
                    description: html_entity_decode($job['content'] ?? ''),
                    location: $location,
                    postedAt: $this->date($job['first_published'] ?? $job['updated_at'] ?? null),
                    remote: $location !== null && stripos($location, 'remote') !== false ? true : null,
                );
            }
        }
    }
}

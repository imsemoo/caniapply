<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class WorkableSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $boards = $this->options['boards'] ?? [];

        foreach ($this->eachTolerant($boards, fn (string $board) => [$board, $this->http->json("https://apply.workable.com/api/v1/widget/accounts/{$board}", ['details' => 'true'])]) as [$board, $body]) {
            foreach ($body['jobs'] ?? [] as $job) {
                yield new RawListing(
                    externalId: $board.':'.$job['shortcode'],
                    url: $job['url'],
                    title: $job['title'] ?? '',
                    company: $body['name'] ?? ucfirst($board),
                    description: $job['description'] ?? '',
                    location: trim(($job['city'] ?? '').', '.($job['country'] ?? ''), ', '),
                    postedAt: $this->date($job['published_on'] ?? $job['created_at'] ?? null),
                    employmentType: $job['employment_type'] ?? null,
                    remote: ! empty($job['telecommuting']) ? true : null,
                );
            }
        }
    }
}

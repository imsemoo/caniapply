<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class AshbySource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $boards = $this->options['boards'] ?? [];

        foreach ($this->eachTolerant($boards, fn (string $board) => [$board, $this->http->json("https://api.ashbyhq.com/posting-api/job-board/{$board}")]) as [$board, $body]) {
            foreach ($body['jobs'] ?? [] as $job) {
                if (($job['isListed'] ?? true) === false) {
                    continue;
                }

                $locations = array_filter([$job['location'] ?? null, ...array_column($job['secondaryLocations'] ?? [], 'location')]);

                yield new RawListing(
                    externalId: $board.':'.$job['id'],
                    url: $job['jobUrl'],
                    title: $job['title'] ?? '',
                    company: ucfirst($board),
                    description: $job['descriptionPlain'] ?? $job['descriptionHtml'] ?? '',
                    location: implode(', ', array_slice($locations, 0, 8)),
                    postedAt: $this->date($job['publishedAt'] ?? null),
                    employmentType: $job['employmentType'] ?? null,
                    remote: ! empty($job['isRemote']) ? true : (($job['workplaceType'] ?? null) === 'OnSite' ? false : null),
                );
            }
        }
    }
}

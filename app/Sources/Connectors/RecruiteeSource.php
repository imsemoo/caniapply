<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class RecruiteeSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $boards = $this->options['boards'] ?? [];

        foreach ($this->eachTolerant($boards, fn (string $board) => [$board, $this->http->json("https://{$board}.recruitee.com/api/offers/")]) as [$board, $body]) {
            foreach ($body['offers'] ?? [] as $job) {
                if (($job['status'] ?? 'published') !== 'published') {
                    continue;
                }

                $salary = $job['salary'] ?? [];

                yield new RawListing(
                    externalId: $board.':'.$job['id'],
                    url: $job['careers_url'],
                    title: $job['title'] ?? '',
                    company: $job['company_name'] ?? ucfirst($board),
                    description: ($job['description'] ?? '').' '.($job['requirements'] ?? ''),
                    location: $job['location'] ?? null,
                    postedAt: $this->date($job['published_at'] ?? $job['created_at'] ?? null),
                    employmentType: $job['employment_type_code'] ?? null,
                    salaryMin: isset($salary['min']) && is_numeric($salary['min']) ? (float) $salary['min'] : null,
                    salaryMax: isset($salary['max']) && is_numeric($salary['max']) ? (float) $salary['max'] : null,
                    salaryCurrency: $salary['currency'] ?? null,
                    salaryPeriod: $salary['period'] ?? null,
                    remote: ! empty($job['remote']) ? true : null,
                );
            }
        }
    }
}

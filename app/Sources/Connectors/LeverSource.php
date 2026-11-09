<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class LeverSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $boards = $this->options['boards'] ?? [];

        foreach ($this->eachTolerant($boards, fn (string $board) => [$board, $this->http->json("https://api.lever.co/v0/postings/{$board}", ['mode' => 'json'])]) as [$board, $postings]) {
            foreach ($postings as $job) {
                $categories = $job['categories'] ?? [];
                $range = $job['salaryRange'] ?? [];

                yield new RawListing(
                    externalId: $board.':'.$job['id'],
                    url: $job['hostedUrl'],
                    title: $job['text'] ?? '',
                    company: ucfirst($board),
                    description: $job['descriptionPlain'] ?? $job['description'] ?? '',
                    location: implode(', ', $categories['allLocations'] ?? array_filter([$categories['location'] ?? null])),
                    postedAt: $this->date(isset($job['createdAt']) ? (int) ($job['createdAt'] / 1000) : null),
                    employmentType: $categories['commitment'] ?? null,
                    salaryMin: isset($range['min']) ? (float) $range['min'] : null,
                    salaryMax: isset($range['max']) ? (float) $range['max'] : null,
                    salaryCurrency: $range['currency'] ?? null,
                    salaryPeriod: isset($range['interval']) ? str_replace(['per-', '-salary'], '', $range['interval']) : null,
                    remote: match ($job['workplaceType'] ?? null) {
                        'remote' => true,
                        'onsite' => false,
                        default => null,
                    },
                );
            }
        }
    }
}

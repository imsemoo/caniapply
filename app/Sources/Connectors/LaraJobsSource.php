<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class LaraJobsSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $xml = $this->feed('https://larajobs.com/feed');

        foreach ($xml->channel->item as $item) {
            $job = $item->children('job', true);
            $url = (string) $item->link;
            $location = (string) $job->location;

            yield new RawListing(
                externalId: $url,
                url: $url,
                title: html_entity_decode((string) $item->title),
                company: (string) $job->company ?: (string) $item->children('dc', true)->creator ?: null,
                description: $this->withSalaryLine((string) $job->salary, (string) $item->description ?: (string) $item->children('content', true)->encoded),
                location: $location !== '' ? $location : null,
                postedAt: $this->date((string) $item->pubDate),
                employmentType: (string) $job->job_type,
                tags: array_filter(explode(',', (string) $job->tags)),
                remote: stripos($location, 'remote') !== false ? true : null,
            );
        }
    }

    private function withSalaryLine(string $salary, string $body): string
    {
        return trim($salary) === '' ? $body : 'Salary: '.trim($salary)."\n\n".$body;
    }
}

<?php

namespace App\Sources\Connectors;

use App\Enums\RemoteScope;
use App\Models\CandidateProfile;
use App\Sources\RawListing;

class WeWorkRemotelySource extends Connector
{
    private const FEEDS = [
        'https://weworkremotely.com/categories/remote-full-stack-programming-jobs.rss',
        'https://weworkremotely.com/categories/remote-front-end-programming-jobs.rss',
        'https://weworkremotely.com/categories/remote-back-end-programming-jobs.rss',
    ];

    public function fetch(CandidateProfile $profile): iterable
    {
        $seen = [];

        foreach ($this->eachTolerant(self::FEEDS, fn (string $feed) => $this->feed($feed)) as $xml) {
            foreach ($xml->channel->item as $item) {
                $url = (string) $item->link;
                if ($url === '' || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                [$company, $title] = array_pad(explode(': ', (string) $item->title, 2), 2, null);
                $region = trim((string) $item->region);

                yield new RawListing(
                    externalId: $url,
                    url: $url,
                    title: html_entity_decode($title ?? $company),
                    company: $title === null ? null : html_entity_decode($company),
                    description: (string) $item->description,
                    location: $region !== '' ? $region : null,
                    postedAt: $this->date((string) $item->pubDate),
                    tags: [(string) $item->category],
                    remote: true,
                    // A tag only. The eligibility detector still reads the post body,
                    // because "Anywhere" posts often restrict applicants in the text.
                    sourceScope: stripos($region, 'anywhere') !== false ? RemoteScope::Worldwide : null,
                );
            }
        }
    }
}

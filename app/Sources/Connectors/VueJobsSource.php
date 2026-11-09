<?php

namespace App\Sources\Connectors;

use App\Models\CandidateProfile;
use App\Sources\RawListing;

class VueJobsSource extends Connector
{
    public function fetch(CandidateProfile $profile): iterable
    {
        $xml = $this->feed('https://vuejobs.com/feed');

        foreach ($xml->channel->item as $item) {
            $guid = (string) $item->guid;
            $html = (string) $item->description;
            preg_match('#Employer:</strong>\s*(.*?)</p>#si', $html, $employer);
            preg_match('#Location:</strong>\s*(.*?)</p>#si', $html, $location);
            $place = isset($location[1]) ? trim(strip_tags($location[1])) : null;

            yield new RawListing(
                externalId: $guid,
                url: strtok((string) $item->link, '?') ?: $guid,
                title: html_entity_decode((string) $item->title),
                company: isset($employer[1]) ? html_entity_decode(trim(strip_tags($employer[1]))) : null,
                description: $html,
                location: $place,
                postedAt: $this->date((string) $item->pubDate),
                remote: $place !== null && stripos($place, 'remote') !== false ? true : null,
            );
        }
    }
}

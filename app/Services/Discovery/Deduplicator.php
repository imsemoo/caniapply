<?php

namespace App\Services\Discovery;

use App\Models\Opportunity;
use App\Models\OpportunityListing;
use App\Services\Normalization\NormalizedListing;

/** Finds the existing record a listing from another source describes. */
class Deduplicator
{
    private const WINDOW_DAYS = 90;

    public function findMatch(NormalizedListing $listing, int $sourceId): ?Opportunity
    {
        $byUrl = OpportunityListing::query()
            ->where('url_key', OpportunityListing::urlKey($listing->raw->url))
            ->with('opportunity')
            ->first();

        if ($byUrl !== null) {
            return $byUrl->opportunity;
        }

        if ($listing->raw->isFreelance || $listing->companyNorm === null) {
            return null;
        }

        $candidates = Opportunity::query()
            ->where('company_norm', $listing->companyNorm)
            ->where('first_seen_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->with('listings:id,opportunity_id,job_source_id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->isSameJob($listing, $candidate, $sourceId)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isSameJob(NormalizedListing $listing, Opportunity $candidate, int $sourceId): bool
    {
        $titleOverlap = self::tokenOverlap($listing->titleNorm, $candidate->title_norm);
        if ($titleOverlap < 0.75 || ! $this->locationsCompatible($listing, $candidate)) {
            return false;
        }

        // One board listing a role twice with different places means two openings.
        $sameBoard = $candidate->listings->contains('job_source_id', $sourceId);
        if ($sameBoard && mb_strtolower((string) $listing->raw->location) !== mb_strtolower((string) $candidate->location_text)) {
            return false;
        }

        $bothDescribed = mb_strlen($listing->description) >= 200 && mb_strlen($candidate->description) >= 200;
        if (! $bothDescribed) {
            return $titleOverlap === 1.0;
        }

        $similarity = self::textContainment($listing->description, $candidate->description);

        // Identical titles need less text agreement: aggregators often rewrite or truncate.
        return $similarity >= ($titleOverlap === 1.0 ? 0.2 : 0.5);
    }

    private function locationsCompatible(NormalizedListing $listing, Opportunity $candidate): bool
    {
        $a = mb_strtolower((string) $listing->raw->location);
        $b = mb_strtolower((string) $candidate->location_text);

        if ($a === '' || $b === '' || $a === $b) {
            return true;
        }

        $regionsA = $this->regionsIn($a);
        $regionsB = $this->regionsIn($b);
        if ($regionsA !== [] && $regionsB !== [] && array_intersect($regionsA, $regionsB) === []) {
            return false;
        }

        // The same role posted separately for two named cities is two openings.
        $isPlace = fn (string $text) => ! preg_match('/remote|anywhere|world|global|emea|europe|america/', $text);

        return ! ($isPlace($a) && $isPlace($b));
    }

    /** @return string[] */
    private function regionsIn(string $text): array
    {
        preg_match_all('/\b(emea|mena|apac|latam|europe|americas?|north america|asia|africa|worldwide|anywhere|global)\b/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    public static function tokenOverlap(string $a, string $b): float
    {
        $tokensA = array_unique(preg_split('/\s+/u', $a, flags: PREG_SPLIT_NO_EMPTY));
        $tokensB = array_unique(preg_split('/\s+/u', $b, flags: PREG_SPLIT_NO_EMPTY));
        $union = count(array_unique([...$tokensA, ...$tokensB]));

        return $union === 0 ? 0.0 : count(array_intersect($tokensA, $tokensB)) / $union;
    }

    /** Word-trigram containment, so a truncated copy still matches its full original. */
    public static function textContainment(string $a, string $b): float
    {
        $gramsA = self::trigrams($a);
        $gramsB = self::trigrams($b);
        $smaller = min(count($gramsA), count($gramsB));

        return $smaller === 0 ? 0.0 : count(array_intersect_key($gramsA, $gramsB)) / $smaller;
    }

    /** Symmetric, so text appended to one side counts as a difference. */
    public static function textOverlap(string $a, string $b): float
    {
        $gramsA = self::trigrams($a);
        $gramsB = self::trigrams($b);
        $union = count($gramsA + $gramsB);

        return $union === 0 ? 1.0 : count(array_intersect_key($gramsA, $gramsB)) / $union;
    }

    private static function trigrams(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(mb_substr($text, 0, 6000)), flags: PREG_SPLIT_NO_EMPTY);
        $grams = [];

        for ($i = 0, $n = count($words) - 2; $i < $n; $i++) {
            $grams[$words[$i].' '.$words[$i + 1].' '.$words[$i + 2]] = true;
        }

        return $grams;
    }
}

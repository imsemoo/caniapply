<?php

namespace App\Services\Normalization;

use App\Enums\CountryEligibility as Eligibility;
use App\Enums\RemoteScope as Scope;
use App\Sources\RawListing;
use App\Support\HomeCountry;

/**
 * Decides who can actually take a job from the candidate's country.
 * "Worldwide" comes only from the source or an explicit sentence, and a body
 * that restricts applicants overrides any tag.
 */
class EligibilityDetector
{
    /** Regions outside every list below. By now they are known not to contain the candidate. */
    private const MIDDLE_EAST_AFRICA = '/\bmena\b|middle east|\bemea\b|\bafrica\b|الوطن العربي|الدول العربية|الشرق الأوسط/iu';

    private const ARAB_PLACES = 'مصر|القاهرة|السعودية|الرياض|جدة|الإمارات|دبي|الكويت|قطر|عمان|البحرين|الأردن|تركيا|لبنان|العراق|المغرب|تونس|الجزائر|فلسطين|ليبيا|السودان|اليمن|سوريا';

    /** "The salary range is for US residents" limits the pay information, not who may apply. */
    private const ABOUT_PAY = '/\b(?:salary|base pay|pay (?:range|band|scale)|compensation (?:range|package)|benefits?|(?:medical|dental|vision|health)(?:\s*(?:and|&|,)\s*\w+)?\s+(?:coverage|insurance|plans?|care)|401\(?k\)?)\b/i';

    private const WORLD = '/\b(anywhere|world[\s-]?wide|global|globally|all countries|any country|any location)\b|من أي مكان|أي دولة|جميع الدول/i';

    private const EUROPE = '/\beurope(an)?\b|\beu\b|european union|\beea\b|\bcet\b|\bcest\b|germany|france|spain|portugal|netherlands|poland|romania|italy|ireland|sweden|norway|denmark|finland|austria|belgium|switzerland|czech|hungary|greece|bulgaria|croatia|serbia|ukraine|estonia|latvia|lithuania|united kingdom|\buk\b|(?<!new )england|scotland|london|berlin|munich|hamburg|amsterdam|paris|madrid|barcelona|lisbon|warsaw|dublin/i';

    private const AMERICAS = '/\busa?\b|u\.s\.|united states|\bamericas?\b|north america|canada|new york|\bnyc\b|san francisco|california|texas|seattle|boston|chicago|toronto|vancouver|latam|latin america|south america|brazil|mexico|argentina|colombia/i';

    private const APAC = '/\bapac\b|\basia\b|india|philippines|pakistan|bangladesh|indonesia|vietnam|japan|singapore|australia|new zealand|korea|china|malaysia|thailand/i';

    private const OTHER_COUNTRIES = '/saudi|riyadh|jeddah|\buae\b|dubai|abu dhabi|qatar|doha|kuwait|bahrain|\boman\b|jordan|amman|lebanon|morocco|tunisia|turkey|türkiye|istanbul|israel|tel aviv|cyprus|south africa|nigeria|kenya|السعودية|الرياض|جدة|الإمارات|دبي|أبوظبي|قطر|الدوحة|الكويت|البحرين|الأردن|تركيا/i';

    private const REGION_TOKENS = '(?:US|U\.S\.A?\.?|USA|UK|U\.K\.|EU|EEA|(?i:the united states|united states|north america|canada|europe|european union|united kingdom|latam|latin america|apac|india|australia|germany|france|spain|brazil|philippines|poland|the netherlands|netherlands|ireland|mexico|argentina|colombia))';

    private const POSITIVE_WORLD = '/work from anywhere|anywhere in the world|hir(?:e|ing) (?:globally|worldwide|from anywhere)|open to candidates? (?:worldwide|globally|from anywhere)|no location restrictions?|location[\s-]independent|من أي مكان في العالم/i';

    private HomeCountry $home;

    /** Tests pin a country. Otherwise it is read from the profile for every listing. */
    public function __construct(private ?HomeCountry $pinnedHome = null) {}

    public function detect(RawListing $raw, string $text): EligibilityFinding
    {
        $this->home = $this->pinnedHome ?? HomeCountry::current();

        $location = trim((string) $raw->location);
        $timezoneNote = $this->timezoneNote($text.' '.$location);
        // Titles carry restrictions too: "Web Developer (U.S. Based)".
        $restriction = $this->restrictionIn($raw->title."\n".$text);
        $relocation = $this->requiresRelocation($text, $raw);

        if ($raw->isFreelance) {
            return $restriction !== null
                ? new EligibilityFinding(Scope::SpecificCountries, Eligibility::Excluded, $restriction, $timezoneNote)
                : new EligibilityFinding(Scope::Worldwide, Eligibility::Eligible, 'Open project on a freelance marketplace', $timezoneNote);
        }

        $mode = $this->workMode($raw, $location, $text);

        // A foreign location decides it, and is better evidence than a passing mention of an office.
        if ($mode !== null && $mode['from_body'] && ($this->fromLocation($location, $raw)[1] ?? null) === Eligibility::Excluded) {
            $mode = null;
        }

        if ($mode !== null) {
            $atHome = $this->home->isLocation($location);
            // A remote post that mentions office days may only mean local staff. Keep it, unconfirmed.
            $mayStillBeRemote = $mode['from_body'] && $raw->remote === true && ($this->fromLocation($location, $raw)[1] ?? null) !== Eligibility::Excluded;

            return new EligibilityFinding(
                $mode['scope'],
                $atHome ? Eligibility::Eligible : ($mayStillBeRemote || $this->isUnrecognisedPlace($location) ? Eligibility::Unknown : Eligibility::Excluded),
                $mode['evidence'],
                $timezoneNote,
                $relocation && ! $atHome,
            );
        }

        if ($raw->sourceEligibility === Eligibility::Eligible) {
            // A restriction in the job's own title outweighs a source's country list.
            $titleRestriction = $this->restrictionIn($raw->title);
            if ($titleRestriction !== null) {
                return new EligibilityFinding(Scope::SpecificCountries, Eligibility::Excluded, $titleRestriction, $timezoneNote, $relocation);
            }

            return $restriction !== null
                ? new EligibilityFinding(Scope::Unknown, Eligibility::Unknown, 'The source lists your country, but the '.lcfirst($restriction), $timezoneNote, $relocation)
                : $this->withTimezone(new EligibilityFinding($raw->sourceScope ?? Scope::MultiCountry, Eligibility::Eligible, $raw->sourceEvidence, $timezoneNote, $relocation));
        }

        if ($restriction !== null) {
            return new EligibilityFinding(Scope::SpecificCountries, Eligibility::Excluded, $restriction, $timezoneNote, $relocation);
        }

        $byLocation = $this->fromLocation($location, $raw);
        if ($byLocation !== null) {
            return $this->withTimezone(new EligibilityFinding($byLocation[0], $byLocation[1], 'Listing location: '.($location !== '' ? $location : 'source tag'), $timezoneNote, $relocation));
        }

        if (preg_match(self::POSITIVE_WORLD, $text, $match)) {
            return $this->withTimezone(new EligibilityFinding(Scope::Worldwide, Eligibility::Eligible, 'Post says: "'.$match[0].'"', $timezoneNote, $relocation));
        }

        if (preg_match('/[^.\n]{0,60}\b(?:hiring|candidates?|applicants?|remote|(?:must|should|need to|you)\b[^.\n]{0,20}\b(?:based|located|living))\b[^.\n]{0,60}(?:\b(?:in|from|within|across)\s+(?:the\s+)?|[,:-]\s*)(?:'.$this->home->pattern.')[^.\n]{0,30}/iu', $text, $match)
            && ! preg_match('/\b(?:company|startup|headquarter\w*|hq|offices?|founded|agency|studio)\b/i', $match[0])) {
            return new EligibilityFinding(Scope::SpecificCountries, Eligibility::Eligible, 'Post says: "'.trim($match[0]).'"', $timezoneNote, $relocation);
        }

        return new EligibilityFinding(Scope::Unknown, Eligibility::Unknown, null, $timezoneNote, $relocation);
    }

    private function withTimezone(EligibilityFinding $finding): EligibilityFinding
    {
        $narrow = $finding->timezoneNote !== null
            && str_starts_with($finding->timezoneNote, 'Needs')
            && in_array($finding->scope, [Scope::Worldwide, Scope::Unknown], true);

        return $narrow
            ? new EligibilityFinding(Scope::TimezoneBound, $finding->country === Eligibility::Eligible ? Eligibility::Likely : $finding->country, $finding->evidence, $finding->timezoneNote, $finding->requiresRelocation)
            : $finding;
    }

    /** @return array{0: Scope, 1: Egypt}|null */
    private function fromLocation(string $location, RawListing $raw): ?array
    {
        if ($location === '' || preg_match('/^(remote|fully remote|100% remote)$/i', $location)) {
            return $raw->sourceScope === Scope::Worldwide ? [Scope::Worldwide, Eligibility::Eligible] : null;
        }

        if (preg_match('/^(.*?)\b(?:except|excluding|not available in)\b(.*)/iu', $location, $except)) {
            if (! $this->home->isNamedIn($except[1]) && ($this->home->isNamedIn($except[2]) || $this->home->regionNamedIn($except[2]) !== null)) {
                return [Scope::MultiCountry, Eligibility::Excluded];
            }

            $location = trim($except[1]);
        }

        if ($this->home->isLocation($location)) {
            return [Scope::SpecificCountries, Eligibility::Eligible];
        }

        $homeRegion = $this->home->regionNamedIn($location);
        if ($homeRegion !== null) {
            return [match ($homeRegion) {
                'mena' => Scope::Mena,
                'emea' => Scope::Emea,
                'eu', 'europe' => Scope::Europe,
                default => Scope::MultiCountry,
            }, Eligibility::Likely];
        }

        $hits = [
            'middle_east_africa' => preg_match(self::MIDDLE_EAST_AFRICA, $location) === 1,
            'europe' => preg_match(self::EUROPE, $location) === 1,
            'americas' => preg_match(self::AMERICAS, $location) === 1,
            'apac' => preg_match(self::APAC, $location) === 1,
            'other' => preg_match(self::OTHER_COUNTRIES, $location) === 1,
        ];

        $hits['other'] = $hits['other'] || (! $hits['europe'] && ! $hits['americas'] && ! $hits['apac'] && $this->home->isForeignLocation($location));

        $regions = count(array_filter([$hits['middle_east_africa'], $hits['europe'], $hits['americas'], $hits['apac'], $hits['other']]));

        if ($regions === 0) {
            return preg_match(self::WORLD, $location) ? [Scope::Worldwide, Eligibility::Eligible] : null;
        }

        return match (true) {
            $regions > 1 => [Scope::MultiCountry, Eligibility::Excluded],
            $hits['europe'] => [Scope::Europe, Eligibility::Excluded],
            $hits['americas'] && preg_match('/\busa?\b|u\.s\.|united states/i', $location) === 1 => [Scope::UnitedStates, Eligibility::Excluded],
            default => [Scope::SpecificCountries, Eligibility::Excluded],
        };
    }

    /** A city with no row of its own may be in the candidate's country, so it stays unconfirmed. */
    private function isUnrecognisedPlace(string $location): bool
    {
        if (preg_match('/^(?:on[\s-]?site|in[\s-]office|hybrid)?$/i', $location)) {
            return false;
        }

        foreach ([self::MIDDLE_EAST_AFRICA, self::EUROPE, self::AMERICAS, self::APAC, self::OTHER_COUNTRIES] as $known) {
            if (preg_match($known, $location)) {
                return false;
            }
        }

        return ! $this->home->isForeignLocation($location);
    }

    /** @return array{scope: Scope, evidence: string, from_body: bool}|null */
    private function workMode(RawListing $raw, string $location, string $text): ?array
    {
        if (preg_match('/\bhybrid\b|هجين/i', $location)) {
            return ['scope' => Scope::Hybrid, 'evidence' => 'Listing location: '.$location, 'from_body' => false];
        }

        if ($raw->sourceEligibility !== Eligibility::Eligible && preg_match('/(?:\bhybrid[\s-](?:role|position|work|model|schedule|environment|setup|working)|work(?:ing)? hybrid|(?:on-?site|in[\s-]office)\s+\w+\s+days?\s+(?:a|per)\s+week|\b\w+\s+days?\s+(?:a|per)\s+week\s+(?:in|at)\s+(?:the|our)\s+office)/i', $text, $match, PREG_OFFSET_CAPTURE)) {
            return ['scope' => Scope::Hybrid, 'evidence' => 'Post says: "'.$this->sentenceAround($text, $match[0][1], strlen($match[0][0])).'"', 'from_body' => true];
        }

        if ($raw->remote === false || preg_match('/^(on[\s-]?site|in[\s-]office)\b|حضوري|من المقر/iu', $location)) {
            return ['scope' => Scope::Onsite, 'evidence' => 'Listing location: '.($location !== '' ? $location : 'on-site'), 'from_body' => false];
        }

        if ($raw->remote === null && $location !== '' && ! preg_match('/remote|عن ب|anywhere|world[\s-]?wide|global|\b(?:emea|mena|apac|latam|europe|americas?|africa|asia|eu|eea|european union|middle east)\b/i', $location)) {
            return ['scope' => Scope::Onsite, 'evidence' => 'Listing gives a place and does not say remote: '.$location, 'from_body' => false];
        }

        return null;
    }

    private function restrictionIn(string $text): ?string
    {
        $anyCountry = '(?:'.self::REGION_TOKENS.'|(?i:'.$this->home->otherCountriesPattern().'))';

        $patterns = [
            '/(?i:(?:must|need to|required to|have to)\s+(?:be\s+)?(?:currently\s+|legally\s+)?(?:based|located|living|residing|reside|live)\b|only (?:open|available|accepting|hiring|considering)|authori[sz]ed to work in|right to work in|work authori[sz]ation (?:in|for)|eligible to work in|citizens? of|resident of|residents of)[^.\n]{0,50}?\b'.$anyCountry.'(?![a-zA-Z])/u',
            '/(?i:candidates?|applicants?|you)[^.\n]{0,30}(?i:located|based|residing|living) (?i:in|within)[^.\n]{0,25}?\b'.$anyCountry.'(?![a-zA-Z])/u',
            '/\b'.$anyCountry.'[\s-]*(?i:only|residents?|citizens?)\b/u',
            '/\b'.self::REGION_TOKENS.'[\s-](?i:based)\b(?!\s+(?i:company|startup|team|business|firm|clients?|customers?|agency|organi[sz]ation|fintech|scale-?up|saas|platform|product|brand|bank|group|employer|provider))/u',
            '/\b'.$anyCountry.'[\s-](?i:based)\s+(?i:roles?|positions?|jobs?|candidates?|applicants?|developers?|engineers?|only)\b/u',
            '/(?i:remote)(?:\s+(?i:position|role|job|opportunity))?\s+(?i:in|within)\s+'.$anyCountry.'(?![a-zA-Z])/u',
        ];

        foreach (preg_split('/[.\n؟]+/u', $text) as $sentence) {
            if (preg_match('/(?:للمقيمين|المقيمين|مقيم\S*|يشترط الإقامة|الإقامة)[^.\n]{0,25}(?:'.self::ARAB_PLACES.')|(?:داخل|في)\s+(?:'.self::ARAB_PLACES.')\s+فقط/u', $sentence)
                && ! $this->home->isCoveredBy($sentence)
                && ! preg_match('/يفضل|يُفضل/u', $sentence)) {
                return 'Post says: "'.trim(mb_substr($sentence, 0, 200)).'"';
            }
        }

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$found, $offset]) {
                [$start, $end] = $this->sentenceBounds($text, $offset, strlen($found));
                $sentence = substr($text, $start, $end - $start);

                // "Must live in the US and get our dental plan" still restricts. "US-based staff get dental" does not.
                $aboutPay = preg_match(self::ABOUT_PAY, substr($text, $start, $offset - $start))
                    || (! preg_match('/\b(?:must|need to|required to|have to|only)\b/i', $sentence) && preg_match(self::ABOUT_PAY, substr($text, $offset + strlen($found), $end - $offset - strlen($found))));

                if ($this->home->isCoveredBy($sentence) || $aboutPay) {
                    continue;
                }

                return 'Post says: "'.$this->quoted($sentence).'"';
            }
        }

        return null;
    }

    private function sentenceAround(string $text, int $offset, int $length): string
    {
        [$start, $end] = $this->sentenceBounds($text, $offset, $length);

        return $this->quoted(substr($text, $start, $end - $start));
    }

    /** @return array{0: int, 1: int} byte offsets of the sentence holding the match */
    private function sentenceBounds(string $text, int $offset, int $length): array
    {
        // The dots of "U.S." and "e.g." end nothing, unless a new sentence follows.
        $masked = preg_replace_callback(
            '/\b(?:U\.S\.A|U\.S|U\.K|e\.g|i\.e)(?:\.(?!\s+[A-Z]|\s*\z))?/',
            fn (array $match) => str_replace('.', '_', $match[0]),
            $text,
        );

        $start = preg_match('/^.*(?:\n|(?:\.|!|\?|؟)(?=\s))/s', substr($masked, 0, $offset), $before) ? strlen($before[0]) : 0;
        $end = preg_match('/\n|(?:\.|!|\?|؟)(?=\s|\z)/', $masked, $after, PREG_OFFSET_CAPTURE, $offset + $length) ? $after[0][1] : strlen($text);

        return [$start, $end];
    }

    private function quoted(string $sentence): string
    {
        return trim(mb_strcut(trim($sentence), 0, 240), " .\n");
    }

    private function timezoneNote(string $text): ?string
    {
        if (preg_match('/\b(?:EST|EDT|PST|PDT|CST|CDT|MST)\b|(?i:eastern (?:time|hours|standard)|pacific (?:time|hours|standard)|central time|u\.?s\.? (?:time ?zones?|hours|business hours)|americas? time ?zones?)/', $text, $match)) {
            return $this->zoneNote(-8, -5, 'Needs overlap with US hours', $match[0]);
        }

        if (preg_match('/\b(?:AEST|AEDT|JST|SGT)\b/', $text, $match)) {
            return $this->zoneNote(8, 11, 'Needs overlap with APAC hours', $match[0]);
        }

        if (preg_match('/\b(?:UTC|GMT)\s*([+\-−–]\s*\d{1,2})(?:\s*(?:to|and|-|–)\s*(?:UTC|GMT)?\s*([+\-−–]\s*\d{1,2}))?/u', $text, $match)) {
            $low = $this->offset($match[1]);
            $high = isset($match[2]) ? $this->offset($match[2]) : $low;
            [$low, $high] = [min($low, $high), max($low, $high)];

            return $this->zoneNote($low, $high, 'Needs hours far from your time zone', trim($match[0]));
        }

        if (preg_match('/\bCET\b|\bCEST\b|(?i:european (?:time ?zones?|hours|business hours))/', $text, $match)) {
            return $this->zoneNote(1, 2, 'Needs overlap with European hours', $match[0]);
        }

        return null;
    }

    private function zoneNote(int $low, int $high, string $whenFar, string $quoted): string
    {
        return ($this->home->overlapsUtcRange($low, $high) ? 'Time zone fits yours' : $whenFar).' ('.$quoted.')';
    }

    private function offset(string $value): int
    {
        return (int) str_replace(['−', '–', ' '], ['-', '-', ''], $value);
    }

    private function requiresRelocation(string $text, RawListing $raw): bool
    {
        if (preg_match('/no relocation|not require relocation|without relocation|relocation (?:is )?not (?:required|needed|necessary|available|offered)/i', $text)) {
            return false;
        }

        if (preg_match('/must (?:be willing to )?relocate|relocation (?:is )?required|requires? relocation|willing(?:ness)? to relocate|يتطلب الانتقال|الاستعداد للانتقال/iu', $text)) {
            return true;
        }

        return $raw->remote !== true && preg_match('/relocation (?:package|assistance|support|bonus)|relocate to/i', $text) === 1;
    }
}

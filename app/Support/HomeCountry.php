<?php

namespace App\Support;

use App\Models\CandidateProfile;
use InvalidArgumentException;

/**
 * The country the candidate works from. Every eligibility decision is made
 * relative to it. To add one, add a row to COUNTRIES: nothing else changes.
 */
final class HomeCountry
{
    /** Words that name a region, as opposed to a list of its countries. */
    private const REGION_WORDS = [
        'mena' => '\bmena\b|middle east|north africa|الوطن العربي|الدول العربية|الشرق الأوسط',
        'emea' => '\bemea\b',
        'africa' => '(?<!south )(?<!north )\bafrica\b',
        'eu' => '\beu\b|european union',
        'eea' => '\beea\b',
        'europe' => '\beurope(?:an)?\b(?!\s+union)|\bcet\b|\bcest\b',
        'north_america' => 'north america',
        'latam' => 'latam|latin america',
        'south_america' => 'south america',
        'americas' => '\bamericas\b',
        'apac' => '\bapac\b',
        'asia' => '\basia\b',
    ];

    private const WORLD_WORDS = 'worldwide|anywhere';

    private const ARAB_LEAGUE = ['mena', 'emea'];

    private const AFRICAN = ['africa', 'emea'];

    private const NORTH_AFRICAN = ['mena', 'emea', 'africa'];

    private const EU_MEMBER = ['eu', 'eea', 'europe', 'emea'];

    private const NON_EU_EUROPE = ['europe', 'emea'];

    private const LATIN = ['latam', 'south_america', 'americas'];

    private const ASIA_PACIFIC = ['apac', 'asia'];

    private const OCEANIA = ['apac'];

    /** name => [ISO code, usual UTC offset, regions, names and main cities as a regex] */
    private const COUNTRIES = [
        'Algeria' => ['DZ', 1, self::NORTH_AFRICAN, '\b(?:algeria|algiers)\b|الجزائر'],
        'Argentina' => ['AR', -3, self::LATIN, '\b(?:argentina|buenos aires)\b'],
        'Australia' => ['AU', 10, self::OCEANIA, '\b(?:australia|sydney(?!,?\s*(?:ns|nova))|melbourne(?!,?\s*(?:fl|florida)))\b'],
        'Austria' => ['AT', 1, self::EU_MEMBER, '\b(?:austria|vienna)\b'],
        'Bahrain' => ['BH', 3, self::ARAB_LEAGUE, '\b(?:bahrain|manama)\b|البحرين'],
        'Bangladesh' => ['BD', 6, self::ASIA_PACIFIC, '\b(?:bangladesh|dhaka)\b'],
        'Belgium' => ['BE', 1, self::EU_MEMBER, '\b(?:belgium|brussels)\b'],
        'Brazil' => ['BR', -3, self::LATIN, '\b(?:brazil|brasil|são paulo|sao paulo)\b'],
        'Bulgaria' => ['BG', 2, self::EU_MEMBER, '\b(?:bulgaria|sofia)\b'],
        'Canada' => ['CA', -5, ['north_america', 'americas'], '\b(?:canada|toronto|vancouver|montreal|ottawa|calgary)\b|,\s*(?-i:ON|BC|AB|QC)\b'],
        'Chile' => ['CL', -4, self::LATIN, '\b(?:chile|santiago)\b'],
        'China' => ['CN', 8, self::ASIA_PACIFIC, '\b(?:china|beijing|shanghai|shenzhen)\b'],
        'Colombia' => ['CO', -5, self::LATIN, '\b(?:colombia|bogot[aá]|medell[ií]n)\b'],
        'Croatia' => ['HR', 1, self::EU_MEMBER, '\b(?:croatia|zagreb)\b'],
        'Czech Republic' => ['CZ', 1, self::EU_MEMBER, '\b(?:czech republic|czechia|prague)\b'],
        'Denmark' => ['DK', 1, self::EU_MEMBER, '\b(?:denmark|copenhagen)\b'],
        'Egypt' => ['EG', 2, self::NORTH_AFRICAN, '\b(?:egypt|cairo|alexandria(?!,?\s*(?:va|virginia|la|louisiana)\b)|giza)\b|مصر|القاهرة'],
        'Estonia' => ['EE', 2, self::EU_MEMBER, '\b(?:estonia|tallinn)\b'],
        'Ethiopia' => ['ET', 3, self::AFRICAN, '\b(?:ethiopia|addis ababa)\b'],
        'Finland' => ['FI', 2, self::EU_MEMBER, '\b(?:finland|helsinki)\b'],
        'France' => ['FR', 1, self::EU_MEMBER, '\b(?:france|paris(?!,?\s*(?:tx|texas))|lyon)\b'],
        'Germany' => ['DE', 1, self::EU_MEMBER, '\b(?:germany|deutschland|berlin|munich|m[üu]nchen|hamburg|frankfurt|cologne|stuttgart)\b'],
        'Ghana' => ['GH', 0, self::AFRICAN, '\b(?:ghana|accra)\b'],
        'Greece' => ['GR', 2, self::EU_MEMBER, '\b(?:greece|athens(?!,?\s*(?:ga|georgia|oh|ohio)))\b'],
        'Hungary' => ['HU', 1, self::EU_MEMBER, '\b(?:hungary|budapest)\b'],
        'India' => ['IN', 5, self::ASIA_PACIFIC, '\b(?:india|bengaluru|bangalore|mumbai|delhi|hyderabad|pune)\b'],
        'Indonesia' => ['ID', 7, self::ASIA_PACIFIC, '\b(?:indonesia|jakarta)\b'],
        'Iraq' => ['IQ', 3, self::ARAB_LEAGUE, '\b(?:iraq|baghdad)\b|العراق|بغداد'],
        'Ireland' => ['IE', 0, self::EU_MEMBER, '\b(?:ireland|dublin(?!,?\s*(?:oh|ohio|ca|california)))\b'],
        'Italy' => ['IT', 1, self::EU_MEMBER, '\b(?:italy|milan|rome(?!,?\s*(?:ga|georgia|ny)))\b'],
        'Japan' => ['JP', 9, self::ASIA_PACIFIC, '\b(?:japan|tokyo)\b'],
        'Jordan' => ['JO', 3, self::ARAB_LEAGUE, '\b(?:jordan|amman)\b|الأردن|عمّان|عمان'],
        'Kenya' => ['KE', 3, self::AFRICAN, '\b(?:kenya|nairobi)\b'],
        'Kuwait' => ['KW', 3, self::ARAB_LEAGUE, '\bkuwait\b|الكويت'],
        'Latvia' => ['LV', 2, self::EU_MEMBER, '\b(?:latvia|riga)\b'],
        'Lebanon' => ['LB', 2, self::ARAB_LEAGUE, '\b(?:lebanon|beirut)\b|لبنان|بيروت'],
        'Libya' => ['LY', 2, self::NORTH_AFRICAN, '\b(?:libya|tripoli(?!,?\s*lebanon))\b|ليبيا'],
        'Lithuania' => ['LT', 2, self::EU_MEMBER, '\b(?:lithuania|vilnius)\b'],
        'Malaysia' => ['MY', 8, self::ASIA_PACIFIC, '\b(?:malaysia|kuala lumpur)\b'],
        'Mexico' => ['MX', -6, ['latam', 'north_america', 'americas'], '(?<!new )\b(?:mexico|cdmx)\b'],
        'Morocco' => ['MA', 1, self::NORTH_AFRICAN, '\b(?:morocco|casablanca|rabat)\b|المغرب'],
        'Nepal' => ['NP', 6, self::ASIA_PACIFIC, '\b(?:nepal|kathmandu)\b'],
        'Netherlands' => ['NL', 1, self::EU_MEMBER, '\b(?:netherlands|amsterdam|rotterdam)\b'],
        'New Zealand' => ['NZ', 12, self::OCEANIA, '\b(?:new zealand|auckland)\b'],
        'Nigeria' => ['NG', 1, self::AFRICAN, '\b(?:nigeria|lagos|abuja)\b'],
        'Norway' => ['NO', 1, [...self::NON_EU_EUROPE, 'eea'], '\b(?:norway|oslo)\b'],
        'Oman' => ['OM', 4, self::ARAB_LEAGUE, '\b(?:oman|muscat)\b|عمان'],
        'Pakistan' => ['PK', 5, self::ASIA_PACIFIC, '\b(?:pakistan|karachi|lahore|islamabad)\b'],
        'Palestine' => ['PS', 2, self::ARAB_LEAGUE, '\b(?:palestine|ramallah|gaza)\b|فلسطين|رام الله|غزة'],
        'Peru' => ['PE', -5, self::LATIN, '\b(?:peru|lima(?!,?\s*(?:oh|ohio)))\b'],
        'Philippines' => ['PH', 8, self::ASIA_PACIFIC, '\b(?:philippines|manila)\b'],
        'Poland' => ['PL', 1, self::EU_MEMBER, '\b(?:poland|warsaw|krak[oó]w)\b'],
        'Portugal' => ['PT', 0, self::EU_MEMBER, '\b(?:portugal|lisbon|porto(?!\s+alegre))\b'],
        'Qatar' => ['QA', 3, self::ARAB_LEAGUE, '\b(?:qatar|doha)\b|قطر|الدوحة'],
        'Romania' => ['RO', 2, self::EU_MEMBER, '\b(?:romania|bucharest)\b'],
        'Rwanda' => ['RW', 2, self::AFRICAN, '\b(?:rwanda|kigali)\b'],
        'Saudi Arabia' => ['SA', 3, self::ARAB_LEAGUE, '\b(?:saudi(?: arabia)?|ksa|riyadh|jeddah)\b|السعودية|الرياض|جدة'],
        'Serbia' => ['RS', 1, self::NON_EU_EUROPE, '\b(?:serbia|belgrade)\b'],
        'Singapore' => ['SG', 8, self::ASIA_PACIFIC, '\bsingapore\b'],
        'South Africa' => ['ZA', 2, self::AFRICAN, '\b(?:south africa|cape town|johannesburg)\b'],
        'South Korea' => ['KR', 9, self::ASIA_PACIFIC, '\b(?:south korea|korea|seoul)\b'],
        'Spain' => ['ES', 1, self::EU_MEMBER, '\b(?:spain|madrid|barcelona)\b'],
        'Sri Lanka' => ['LK', 5, self::ASIA_PACIFIC, '\b(?:sri lanka|colombo)\b'],
        'Sudan' => ['SD', 2, self::NORTH_AFRICAN, '\b(?:sudan|khartoum)\b|السودان'],
        'Sweden' => ['SE', 1, self::EU_MEMBER, '\b(?:sweden|stockholm)\b'],
        'Switzerland' => ['CH', 1, self::NON_EU_EUROPE, '\b(?:switzerland|z[üu]rich|geneva)\b'],
        'Syria' => ['SY', 3, self::ARAB_LEAGUE, '\b(?:syria|damascus)\b|سوريا|دمشق'],
        'Tanzania' => ['TZ', 3, self::AFRICAN, '\b(?:tanzania|dar es salaam)\b'],
        'Thailand' => ['TH', 7, self::ASIA_PACIFIC, '\b(?:thailand|bangkok)\b'],
        'Tunisia' => ['TN', 1, self::NORTH_AFRICAN, '\b(?:tunisia|tunis)\b|تونس'],
        'Turkey' => ['TR', 3, self::ARAB_LEAGUE, '\b(?:turkey|türkiye|istanbul|ankara)\b|İstanbul|Türkiye|تركيا|إسطنبول'],
        'Uganda' => ['UG', 3, self::AFRICAN, '\b(?:uganda|kampala)\b'],
        'Ukraine' => ['UA', 2, self::NON_EU_EUROPE, '\b(?:ukraine|kyiv)\b'],
        'United Arab Emirates' => ['AE', 4, self::ARAB_LEAGUE, '\b(?:uae|united arab emirates|dubai|abu dhabi)\b|الإمارات|دبي|أبوظبي'],
        'United Kingdom' => ['GB', 0, self::NON_EU_EUROPE, '\b(?:united kingdom|england|scotland|wales|london(?!,?\s*(?:on|ontario)\b)|manchester(?!,?\s*(?:nh|new hampshire))|uk)\b|u\.k\.'],
        'United States' => ['US', -5, ['north_america', 'americas'], '\b(?:united states|usa|new york|nyc|san francisco|california|texas|seattle|boston|chicago|(?-i:US))\b|u\.s\.|,\s*(?-i:AL|AK|AZ|AR|CA|CO|CT|FL|GA|HI|IA|IL|KS|KY|LA|MA|MD|ME|MI|MN|MO|MS|MT|NC|ND|NE|NH|NJ|NM|NV|NY|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VA|VT|WA|WI|WV|WY|DC)\b'],
        'Vietnam' => ['VN', 7, self::ASIA_PACIFIC, '\b(?:vietnam|hanoi|ho chi minh)\b'],
        'Yemen' => ['YE', 3, self::ARAB_LEAGUE, '\b(?:yemen|sanaa)\b|اليمن|صنعاء'],
    ];

    /** "Boston, MA" is a state. "Lille, Nord, MA" or a bare "MA" is a country code. */
    private const US_STATES = ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'FL', 'GA', 'HI', 'IA', 'IL', 'KS', 'KY', 'LA', 'MA', 'MD', 'ME', 'MI', 'MN', 'MO', 'MS', 'MT', 'NC', 'ND', 'NE', 'NH', 'NJ', 'NM', 'NV', 'NY', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VA', 'VT', 'WA', 'WI', 'WV', 'WY', 'DC'];

    private const ISO_CODES = 'AD AE AF AG AI AL AM AO AR AS AT AU AW AZ BA BB BD BE BF BG BH BI BJ BM BN BO BR BS BT BW BY BZ CA CD CF CG CH CI CK CL CM CN CO CR CU CV CY CZ DE DJ DK DM DO DZ EC EE EG ER ES ET FI FJ FK FM FO FR GA GB GD GE GG GH GI GL GM GN GP GQ GR GT GU GW GY HK HN HR HT HU ID IE IL IM IN IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MG MH MK ML MM MN MO MR MS MT MU MV MW MX MY MZ NA NC NE NG NI NL NO NP NR NZ OM PA PE PF PG PH PK PL PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SI SK SL SM SN SO SR SS ST SV SY SZ TD TG TH TJ TL TM TN TO TR TT TV TW TZ UA UG US UY UZ VA VC VE VN VU WS XK YE ZA ZM ZW';

    /** @var array<string, string> */
    private static array $others = [];

    private function __construct(
        public readonly string $name,
        public readonly string $code,
        private readonly int $utcOffset,
        private readonly array $regions,
        public readonly string $pattern,
    ) {}

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::COUNTRIES);
    }

    public static function named(string $name): self
    {
        if (! isset(self::COUNTRIES[$name])) {
            throw new InvalidArgumentException("Unknown country \"{$name}\". Add it to App\\Support\\HomeCountry::COUNTRIES or pick one that is listed there.");
        }

        return new self($name, ...self::COUNTRIES[$name]);
    }

    public static function current(): self
    {
        return self::named(CandidateProfile::current()->country);
    }

    public function isNamedIn(string $text): bool
    {
        return preg_match('/'.$this->pattern.'/iu', $text) === 1;
    }

    /** A trailing country code settles it: "Cairo, GA, US" is not in Egypt. */
    public function isLocation(string $location): bool
    {
        $codes = $this->countryCodesIn($location);

        return $codes !== [] ? in_array($this->code, $codes, true) : $this->isNamedIn($location);
    }

    public function isForeignLocation(string $location): bool
    {
        $codes = $this->countryCodesIn($location);

        return $codes !== [] ? ! in_array($this->code, $codes, true) : $this->namesAnotherCountry($location);
    }

    /** @return list<string> */
    private function countryCodesIn(string $location): array
    {
        $codes = [];

        foreach (preg_split('/[|;]/', $location) as $place) {
            $parts = array_map('trim', explode(',', $place));
            $code = str_replace('UK', 'GB', end($parts));

            if (in_array($code, explode(' ', self::ISO_CODES), true) && ! (count($parts) === 2 && in_array($code, self::US_STATES, true))) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function namesAnotherCountry(string $text): bool
    {
        return preg_match('/'.$this->otherCountriesPattern().'/iu', $text) === 1;
    }

    public function otherCountriesPattern(): string
    {
        return self::$others[$this->name] ??= implode('|', array_map(
            fn (array $row) => $row[3],
            array_filter(self::COUNTRIES, fn (string $name) => $name !== $this->name, ARRAY_FILTER_USE_KEY),
        ));
    }

    /** The first of this country's regions that the text names, if any. */
    public function regionNamedIn(string $text): ?string
    {
        foreach ($this->regions as $region) {
            if (preg_match('/'.self::REGION_WORDS[$region].'/iu', $text) === 1) {
                return $region;
            }
        }

        return null;
    }

    /** True when the text names this country, a region containing it, or the whole world. */
    public function isCoveredBy(string $text): bool
    {
        return $this->isNamedIn($text)
            || $this->regionNamedIn($text) !== null
            || preg_match('/\b(?:'.self::WORLD_WORDS.')\b/i', $text) === 1;
    }

    /** Working hours reach from three hours behind this country to four hours ahead. */
    public function overlapsUtcRange(int $low, int $high): bool
    {
        return $high >= $this->utcOffset - 3 && $low <= $this->utcOffset + 4;
    }
}

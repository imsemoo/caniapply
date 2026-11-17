<?php

namespace Tests\Unit;

use App\Enums\CountryEligibility as Eligibility;
use App\Enums\RemoteScope as Scope;
use App\Services\Normalization\EligibilityDetector;
use App\Services\Normalization\EligibilityFinding;
use App\Sources\RawListing;
use App\Support\HomeCountry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** One test per row of the verdict table in README.md. If a row changes there, it changes here. */
class ReadmeVerdictTableTest extends TestCase
{
    private const US_ONLY = 'You must be located in the United States.';

    private function verdict(string $country, string $location, string $body = 'A remote role building web apps.'): EligibilityFinding
    {
        $raw = new RawListing(externalId: '1', url: 'https://example.com/1', title: 'Web Developer', location: $location, description: $body, remote: true);

        return (new EligibilityDetector(HomeCountry::named($country)))->detect($raw, $body);
    }

    public function test_a_us_only_sentence_excludes_egypt_and_is_quoted(): void
    {
        $finding = $this->verdict('Egypt', 'Remote', self::US_ONLY);

        $this->assertSame(Eligibility::Excluded, $finding->country);
        $this->assertSame('Post says: "You must be located in the United States"', $finding->evidence);
    }

    public function test_the_same_sentence_is_open_to_the_united_states(): void
    {
        $this->assertSame(Eligibility::Eligible, $this->verdict('United States', 'Remote', self::US_ONLY)->country);
    }

    public function test_emea_is_likely_open_to_germany(): void
    {
        $finding = $this->verdict('Germany', 'EMEA');

        $this->assertSame(Eligibility::Likely, $finding->country);
        $this->assertSame(Scope::Emea, $finding->scope);
    }

    public function test_emea_excludes_india(): void
    {
        $this->assertSame(Eligibility::Excluded, $this->verdict('India', 'EMEA')->country);
    }

    public function test_worldwide_with_est_hours_is_likely_and_time_zone_bound_for_egypt(): void
    {
        $finding = $this->verdict('Egypt', 'Worldwide', 'Remote, but you must overlap with EST hours.');

        $this->assertSame(Eligibility::Likely, $finding->country);
        $this->assertSame(Scope::TimezoneBound, $finding->scope);
    }

    #[DataProvider('everyCountry')]
    public function test_plain_remote_is_not_confirmed_for_any_country(string $country): void
    {
        $finding = $this->verdict($country, 'Remote');

        $this->assertSame(Eligibility::Unknown, $finding->country);
        $this->assertSame(Scope::Unknown, $finding->scope);
    }

    public static function everyCountry(): array
    {
        return array_map(fn (string $name) => [$name], HomeCountry::names());
    }

    public function test_the_country_count_in_the_readme_is_current(): void
    {
        preg_match('/for (\d+) countries/', file_get_contents(__DIR__.'/../../README.md'), $match);

        $this->assertSame(count(HomeCountry::names()), (int) ($match[1] ?? 0));
    }
}

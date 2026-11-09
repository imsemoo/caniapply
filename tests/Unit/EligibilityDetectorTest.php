<?php

namespace Tests\Unit;

use App\Enums\CountryEligibility as Eligibility;
use App\Enums\RemoteScope as Scope;
use App\Services\Normalization\EligibilityDetector;
use App\Sources\RawListing;
use App\Support\HomeCountry;
use PHPUnit\Framework\TestCase;

class EligibilityDetectorTest extends TestCase
{
    private function detect(string $body, ?string $location = null, array $extra = [], string $title = 'Laravel Developer')
    {
        $raw = new RawListing(...['externalId' => '1', 'url' => 'https://example.com/1', 'title' => $title, 'location' => $location, 'description' => $body, 'remote' => true] + $extra);

        return (new EligibilityDetector(HomeCountry::named('Egypt')))->detect($raw, $body);
    }

    public function test_plain_remote_is_never_called_worldwide(): void
    {
        $finding = $this->detect('A fully remote role building web apps.', 'Remote');

        $this->assertSame(Scope::Unknown, $finding->scope);
        $this->assertSame(Eligibility::Unknown, $finding->country);
    }

    public function test_anywhere_tag_is_overridden_by_a_restriction_in_the_body(): void
    {
        $finding = $this->detect('You must be located in the United States to apply.', 'Anywhere in the World', ['sourceScope' => Scope::Worldwide]);

        $this->assertSame(Eligibility::Excluded, $finding->country);
        $this->assertStringContainsString('United States', $finding->evidence);
    }

    public function test_restriction_in_the_title_counts(): void
    {
        $finding = $this->detect('Build WordPress sites.', 'Anywhere in the World', ['sourceScope' => Scope::Worldwide], 'Web Developer (U.S. Based, Remote Contractor)');

        $this->assertSame(Eligibility::Excluded, $finding->country);
    }

    public function test_worldwide_tag_with_clean_body_is_eligible(): void
    {
        $this->assertSame(Eligibility::Eligible, $this->detect('Build things with us.', 'Anywhere in the World', ['sourceScope' => Scope::Worldwide])->country);
    }

    public function test_the_word_us_as_a_pronoun_is_not_a_country(): void
    {
        $this->assertNotSame(Eligibility::Excluded, $this->detect('Join us and only work with us remote in us.', 'Remote')->country);
    }

    public function test_company_based_in_the_us_does_not_restrict_applicants(): void
    {
        $this->assertNotSame(Eligibility::Excluded, $this->detect('We are a US-based company hiring remote engineers.', 'Remote')->country);
    }

    public function test_europe_only_excludes_egypt(): void
    {
        $finding = $this->detect('Remote role.', 'Remote - Europe');

        $this->assertSame(Scope::Europe, $finding->scope);
        $this->assertSame(Eligibility::Excluded, $finding->country);
    }

    public function test_us_only_excludes_egypt(): void
    {
        $this->assertSame(Scope::UnitedStates, $this->detect('Remote role.', 'Remote, USA')->scope);
    }

    public function test_emea_and_mena_are_likely_not_confirmed(): void
    {
        $this->assertSame(Eligibility::Likely, $this->detect('Remote role.', 'Remote - EMEA')->country);
        $this->assertSame(Scope::Mena, $this->detect('Remote role.', 'MENA region')->scope);
    }

    public function test_source_confirmation_for_egypt_is_used(): void
    {
        $finding = $this->detect('Remote role.', 'Egypt, Jordan', ['sourceScope' => Scope::MultiCountry, 'sourceEligibility' => Eligibility::Eligible, 'sourceEvidence' => 'listed']);

        $this->assertSame(Eligibility::Eligible, $finding->country);
        $this->assertSame(Scope::MultiCountry, $finding->scope);
    }

    public function test_source_confirmation_conflicting_with_body_becomes_unknown(): void
    {
        $finding = $this->detect('You must be authorized to work in the United States.', null, ['sourceEligibility' => Eligibility::Eligible]);

        $this->assertSame(Eligibility::Unknown, $finding->country);
    }

    public function test_hybrid_abroad_is_excluded_and_hybrid_in_cairo_is_not(): void
    {
        $this->assertSame(Eligibility::Excluded, $this->detect('We work onsite three days a week.', 'New York')->country);

        $cairo = $this->detect('Good role.', 'Hybrid - Cairo, Egypt');
        $this->assertSame(Scope::Hybrid, $cairo->scope);
        $this->assertSame(Eligibility::Eligible, $cairo->country);
    }

    public function test_us_hours_narrow_a_worldwide_role(): void
    {
        $finding = $this->detect('Must overlap with EST hours daily.', 'Worldwide');

        $this->assertSame(Scope::TimezoneBound, $finding->scope);
        $this->assertSame(Eligibility::Likely, $finding->country);
    }

    public function test_ordinary_must_be_sentences_are_not_restrictions(): void
    {
        $this->assertSame(Eligibility::Likely, $this->detect('Must be able to overlap with US hours.', 'Worldwide')->country);
        $this->assertSame(Eligibility::Eligible, $this->detect('Must be familiar with US GAAP.', 'Worldwide')->country);
        $this->assertSame(Eligibility::Eligible, $this->detect('Our remote team across Europe and North America is growing.', 'Worldwide')->country);
    }

    public function test_office_mention_in_a_remote_post_stays_unconfirmed_not_rejected(): void
    {
        $finding = $this->detect('We work onsite three days a week at our office.', 'Anywhere in the World', ['sourceScope' => Scope::Worldwide]);

        $this->assertSame(Scope::Hybrid, $finding->scope);
        $this->assertSame(Eligibility::Unknown, $finding->country);
    }

    public function test_source_confirmed_job_ignores_an_office_mention(): void
    {
        $finding = $this->detect('We also offer a hybrid work setup near our office.', 'Worldwide', ['sourceEligibility' => Eligibility::Eligible, 'sourceScope' => Scope::Worldwide]);

        $this->assertSame(Eligibility::Eligible, $finding->country);
    }

    public function test_region_only_location_is_not_treated_as_on_site(): void
    {
        $this->assertSame(Eligibility::Likely, $this->detect('Good role.', 'EMEA', ['remote' => null])->country);
    }

    public function test_alexandria_virginia_is_not_egypt(): void
    {
        $this->assertNotSame(Eligibility::Eligible, $this->detect('Good role.', 'Remote, Alexandria, VA')->country);
    }

    public function test_relocation_is_detected_unless_negated(): void
    {
        $this->assertTrue($this->detect('You must relocate to Berlin.', 'Remote')->requiresRelocation);
        $this->assertFalse($this->detect('No relocation needed.', 'Remote')->requiresRelocation);
    }

    /** @return array{0: Eligibility, 1: Scope} */
    private function fromCountry(string $country, string $body, ?string $location = null): array
    {
        $raw = new RawListing(externalId: '1', url: 'https://example.com/1', title: 'Laravel Developer', location: $location, description: $body, remote: true);
        $finding = (new EligibilityDetector(HomeCountry::named($country)))->detect($raw, $body);

        return [$finding->country, $finding->scope];
    }

    public function test_a_candidate_in_germany_gets_verdicts_for_germany(): void
    {
        $this->assertSame(Eligibility::Eligible, $this->fromCountry('Germany', 'Good role.', 'Germany')[0]);
        $this->assertSame([Eligibility::Likely, Scope::Europe], $this->fromCountry('Germany', 'Good role.', 'Europe'));
        $this->assertSame(Eligibility::Likely, $this->fromCountry('Germany', 'Good role.', 'EMEA')[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Germany', 'Good role.', 'France')[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Germany', 'Good role.', 'United States')[0]);
    }

    public function test_a_restriction_to_your_own_country_is_not_a_restriction(): void
    {
        $body = 'You must be based in the United States.';

        $this->assertSame(Eligibility::Eligible, $this->fromCountry('United States', $body)[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Egypt', $body)[0]);
    }

    public function test_the_lowercase_word_us_does_not_mean_the_united_states(): void
    {
        $this->assertFalse(HomeCountry::named('United States')->isNamedIn('Contact us to apply'));
        $this->assertTrue(HomeCountry::named('United States')->isNamedIn('Remote, US'));
    }

    public function test_regions_only_count_for_the_countries_inside_them(): void
    {
        $this->assertSame(Eligibility::Likely, $this->fromCountry('Brazil', 'Good role.', 'LATAM')[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Brazil', 'Good role.', 'Europe')[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('India', 'Good role.', 'EMEA')[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Brazil', 'Good role.', 'MENA')[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Kenya', 'Good role.', 'South Africa')[0]);
        $this->assertSame(Eligibility::Likely, $this->fromCountry('Nigeria', 'Good role.', 'Africa')[0]);
    }

    public function test_arabic_residency_rule_depends_on_where_you_live(): void
    {
        $body = 'الوظيفة للمقيمين في السعودية فقط';

        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Egypt', $body)[0]);
        $this->assertNotSame(Eligibility::Excluded, $this->fromCountry('Saudi Arabia', $body)[0]);
    }

    public function test_time_zone_fit_follows_the_candidate(): void
    {
        $note = fn (string $country, string $body) => (new EligibilityDetector(HomeCountry::named($country)))
            ->detect(new RawListing(externalId: '1', url: 'https://example.com/1', title: 'Dev', description: $body, remote: true), $body)->timezoneNote;

        $this->assertStringStartsWith('Time zone fits', $note('United States', 'Work EST hours.'));
        $this->assertStringStartsWith('Needs overlap', $note('Egypt', 'Work EST hours.'));
        $this->assertStringStartsWith('Time zone fits', $note('Japan', 'Must overlap JST.'));
    }

    public function test_every_country_row_is_well_formed(): void
    {
        foreach (HomeCountry::names() as $name) {
            $country = HomeCountry::named($name);

            $this->assertSame(2, strlen($country->code), $name);
            $this->assertNotFalse(@preg_match('/'.$country->pattern.'/iu', ''), $name);
            $this->assertTrue($country->isNamedIn($name), $name);
        }
    }

    public function test_reviewed_edge_cases_for_other_countries(): void
    {
        $cases = [
            ['United States', 'Austin, TX', Eligibility::Eligible],
            ['Egypt', 'Austin, TX', Eligibility::Excluded],
            ['Germany', 'Cairo, Egypt', Eligibility::Excluded],
            ['Mexico', 'Santa Fe, New Mexico', Eligibility::Excluded],
            ['Portugal', 'Porto Alegre, Brazil', Eligibility::Excluded],
            ['France', 'Paris, TX', Eligibility::Excluded],
            ['United Kingdom', 'London, ON', Eligibility::Excluded],
            ['United Kingdom', 'European Union', Eligibility::Excluded],
            ['Norway', 'EEA', Eligibility::Likely],
            ['Kenya', 'North Africa', Eligibility::Excluded],
            ['Mexico', 'South America', Eligibility::Excluded],
            ['Australia', 'Asia', Eligibility::Excluded],
            ['Germany', 'Worldwide except EU', Eligibility::Excluded],
            ['United Kingdom', 'uk', Eligibility::Eligible],
            ['Switzerland', 'Zürich', Eligibility::Eligible],
            ['Turkey', 'İstanbul', Eligibility::Eligible],
        ];

        foreach ($cases as [$country, $location, $expected]) {
            $this->assertSame($expected, $this->fromCountry($country, 'Good role.', $location)[0], "{$country} / {$location}");
        }
    }

    public function test_an_unlisted_city_is_unconfirmed_not_excluded(): void
    {
        $raw = new RawListing(externalId: '1', url: 'https://example.com/1', title: 'Dev', location: 'Leipzig', description: 'Good role.');

        $this->assertSame(Eligibility::Unknown, (new EligibilityDetector(HomeCountry::named('Germany')))->detect($raw, 'Good role.')->country);
    }

    public function test_restrictions_to_any_listed_country_are_recognised(): void
    {
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Germany', 'You must be located in Saudi Arabia.')[0]);
        $this->assertSame(Eligibility::Excluded, $this->fromCountry('Germany', 'الوظيفة للمقيمين في مصر فقط')[0]);
        $this->assertSame(Eligibility::Eligible, $this->fromCountry('United Kingdom', 'You must be based in the U.K.')[0]);
        $this->assertNotSame(Eligibility::Excluded, $this->fromCountry('Oman', 'الوظيفة للمقيمين في عمان فقط')[0]);
    }

    public function test_a_person_called_jordan_is_not_the_country(): void
    {
        $this->assertSame(Eligibility::Unknown, $this->fromCountry('Jordan', 'A remote role, reporting to Jordan our CTO.')[0]);
    }
}

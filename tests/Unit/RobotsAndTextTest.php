<?php

namespace Tests\Unit;

use App\Services\Discovery\Deduplicator;
use App\Services\Normalization\TextCleaner;
use App\Sources\SourceHttp;
use PHPUnit\Framework\TestCase;

class RobotsAndTextTest extends TestCase
{
    public function test_only_rules_for_every_agent_apply(): void
    {
        $rules = SourceHttp::parseRobots("User-agent: BadBot\nDisallow: /\n\nUser-agent: *\nDisallow: /search*\nDisallow: /ajax/\n");

        $this->assertSame(['/search*', '/ajax/'], $rules);
    }

    public function test_html_becomes_plain_text_and_scripts_vanish(): void
    {
        $plain = TextCleaner::plain('<p>Hello &amp; welcome</p><script>alert(1)</script><ul><li>One</li></ul>');

        $this->assertStringContainsString('Hello & welcome', $plain);
        $this->assertStringContainsString('- One', $plain);
        $this->assertStringNotContainsString('alert', $plain);
        $this->assertStringNotContainsString('<', $plain);
    }

    public function test_truncated_copy_still_matches_its_original(): void
    {
        $original = str_repeat('We build reliable payment software for small shops across many markets every day. ', 12);

        $this->assertGreaterThan(0.9, Deduplicator::textContainment($original, mb_substr($original, 0, 300)));
        $this->assertLessThan(0.1, Deduplicator::textContainment($original, 'A totally different listing about gardening tools and how to sell them online today.'));
    }
}

<?php

namespace App\Services\Normalization;

use App\Models\CandidateProfile;

/** Keeps listings that could plausibly be for this candidate. The scorer judges the rest. */
class RelevanceFilter
{
    private const NOT_ENGINEERING = '/\b(sales|account (executive|manager)|marketing|recruiter|talent|customer (success|support)|accountant|hr\b|copywriter|data analyst|data scientist|data engineer|machine learning|ml engineer|qa\b|test engineer|sdet|devops|sre\b|site reliability|mechanical|electrical|civil|embedded|firmware|android|ios\b|mobile (developer|engineer)|game|unity|unreal|salesforce|sap\b|odoo|oracle)\b/i';

    private const FREELANCE_OUT = '/flutter|فلاتر|android|أندرويد|اندرويد|\bios\b|تطبيق جوال|تطبيق موبايل|odoo|أودو|shopify|شوبيفاي|\bsalla\b|سلة|webflow|\bwix\b|فيديو|مونتاج|موشن|شعار|لوجو|هوية بصرية|بوربوينت|excel|إكسل|اكسل|شات بوت|chatbot|تليجرام|\btelegram\b/iu';

    public function __construct(private SkillExtractor $skills) {}

    /** @return string|null the matching track key */
    public function trackFor(string $title, string $text, bool $isFreelance, CandidateProfile $profile): ?string
    {
        if ($isFreelance) {
            return $this->freelanceTrack($title, $text, $profile);
        }

        $security = $profile->track('security');
        if ($this->anyMatch($security['title_patterns'], $title)) {
            return 'security';
        }

        if ($this->anyMatch($security['generic_title_patterns'], $title) && preg_match('/\bsoc\b|\bsiem\b|security operations/i', $text)) {
            return 'security';
        }

        if (preg_match(self::NOT_ENGINEERING, $title)) {
            return null;
        }

        $web = $profile->track('web');
        if ($this->anyMatch($web['title_patterns'], $title)) {
            return 'web';
        }

        if ($this->anyMatch($web['generic_title_patterns'], $title)) {
            return array_intersect($this->skills->find($text), $web['anchor_skills']) !== [] ? 'web' : null;
        }

        return null;
    }

    private function freelanceTrack(string $title, string $text, CandidateProfile $profile): ?string
    {
        if (preg_match(self::FREELANCE_OUT, $title)) {
            return null;
        }

        return $this->anyMatch($profile->track('web')['freelance_patterns'], $title.' '.$text) ? 'web' : null;
    }

    private function anyMatch(array $patterns, string $subject): bool
    {
        return $patterns !== [] && preg_match('~'.implode('|', $patterns).'~iu', $subject) === 1;
    }
}

<?php

namespace App\Services\Normalization;

class SkillExtractor
{
    private const OPTIONAL_MARKER = '/nice[\s-]to[\s-]have|preferred|bonus|a plus|\bplus\b:|good to have|desirable|would be great|not required|optional|familiarity with|exposure to|يفضل|يُفضل|ميزة إضافية|مستحسن/iu';

    private const HEADING_ENDS = '/^(about|what we offer|benefits|perks|responsibilities|requirements|qualifications|what you|who you|the role|you will|must have)/i';

    /** @return array{required: string[], optional: string[]} */
    public function extract(string $title, string $text, array $tags = []): array
    {
        $optionalLines = [];
        $requiredLines = [];
        $inOptionalSection = false;

        foreach (preg_split('/\n+/', $text) as $line) {
            $isShort = mb_strlen($line) < 60;
            if ($isShort && preg_match(self::OPTIONAL_MARKER, $line)) {
                $inOptionalSection = true;
                $optionalLines[] = $line;

                continue;
            }
            if ($isShort && preg_match(self::HEADING_ENDS, $line)) {
                $inOptionalSection = false;
            }

            if ($inOptionalSection || preg_match(self::OPTIONAL_MARKER, $line)) {
                $optionalLines[] = $line;
            } else {
                $requiredLines[] = $line;
            }
        }

        $required = $this->find($title.' '.implode(' ', $tags)."\n".implode("\n", $requiredLines));
        $optional = array_values(array_diff($this->find(implode("\n", $optionalLines)), $required));

        return ['required' => $required, 'optional' => $optional];
    }

    /** @return string[] */
    public function find(string $text): array
    {
        $found = [];

        foreach (config('skills.dictionary') as $name => $pattern) {
            if (preg_match('~'.$pattern.'~iu', $text)) {
                $found[] = $name;
            }
        }

        return $found;
    }
}

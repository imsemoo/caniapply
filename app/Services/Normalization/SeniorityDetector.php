<?php

namespace App\Services\Normalization;

class SeniorityDetector
{
    /** @return array{level: ?string, years: ?int} */
    public function detect(string $title, string $text, array $tags = []): array
    {
        return ['level' => $this->level($title, $tags), 'years' => $this->requiredYears(TextCleaner::westernDigits($text))];
    }

    private function level(string $title, array $tags): ?string
    {
        $haystack = strtolower($title);
        $fromTitle = $this->match($haystack);

        return $fromTitle ?? $this->match(strtolower(implode(' ', $tags)));
    }

    private function match(string $text): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(intern|internship|trainee|apprentice)\b/', $text) => 'intern',
            (bool) preg_match('/\b(junior|jr\.?|entry[\s-]level|graduate)\b|مبتدئ|حديث التخرج/u', $text) => 'junior',
            (bool) preg_match('/\b(director|head of|vp|vice president|cto)\b|مدير تقني|رئيس قسم/u', $text) => 'director',
            (bool) preg_match('/\b(engineering manager|manager)\b/', $text) => 'manager',
            (bool) preg_match('/\b(principal|staff|distinguished)\b/', $text) => 'principal',
            (bool) preg_match('/\b(lead|tech lead|team lead)\b|قائد فريق|قائد تقني/u', $text) => 'lead',
            (bool) preg_match('/\b(senior|sr\.?)\b|(?:مطور|مبرمج|مهندس)[^\n]{0,20}(?:أول|خبير)|خبير/u', $text) => 'senior',
            (bool) preg_match('/\b(mid[\s-]?level|mid|intermediate)\b/', $text) => 'mid',
            default => null,
        };
    }

    private function requiredYears(string $text): ?int
    {
        if (preg_match('/(\d{1,2})\s*\+?\s*(?:سنوات|سنة|أعوام|عام)[^.\n]{0,15}خبرة|خبرة[^.\n]{0,25}?(\d{1,2})\s*\+?\s*(?:سنوات|سنة|أعوام|عام)/u', $text, $arabic)) {
            $stated = (int) (($arabic[1] ?? '') !== '' ? $arabic[1] : ($arabic[2] ?? 0));

            return $stated >= 1 && $stated <= 25 ? $stated : null;
        }

        if (! preg_match_all('/\b(\d{1,2})\s*(?:\+|-\s*\d{1,2}|to\s*\d{1,2}|or more)?\s*(?:years?|yrs?)\b[^.\n]{0,40}\b(?:experience|exp\b|background|building|developing|working)/i', $text, $matches)) {
            return null;
        }

        $years = array_filter(array_map('intval', $matches[1]), fn (int $y) => $y >= 1 && $y <= 25);

        return $years === [] ? null : max($years);
    }
}

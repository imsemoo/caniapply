<?php

namespace App\Services\Normalization;

/** Reads a stated salary out of free text. Returns null rather than guess. */
class SalaryParser
{
    private const CURRENCY_SYMBOLS = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP'];

    private const CODES = 'USD|EUR|GBP|CAD|AUD|CHF|SAR|AED|QAR|KWD|EGP|SEK|NOK|DKK|PLN';

    /** Pay in these markets is quoted per month, so a bare figure is a monthly one. */
    private const MONTHLY_BY_DEFAULT = ['SAR', 'AED', 'QAR', 'KWD', 'EGP'];

    private const ARABIC_CURRENCIES = ['$' => 'USD', 'دولار' => 'USD', 'جنيه' => 'EGP', 'جنيه إسترليني' => 'GBP', 'ريال' => 'SAR', 'ريال عماني' => 'OMR', 'ريال قطري' => 'QAR', 'درهم' => 'AED', 'يورو' => 'EUR'];

    private const CONTEXT = '/\b(salary|compensation|pay|paying|paid|base|ote|package|rate|earn|remuneration)\b|راتب|مرتب|الأجر/iu';

    private const NOT_PAY = '/\b(raised|funding|funded|revenue|valuation|billion|million|arr|mrr|budget|customers|series [a-e]|investors?|assets|transactions?|grants?|stipend for|allowance|bonus of|equipment|home office|learning|certifications?|workshops?|conferences?|courses?|training|reimburse\w*|support projects|open source|wellness|gym|coworking|per employee|stipends?|internet|allowance|per diem|phone)\b/i';

    /** @return array{min:float,max:float,currency:string,period:string,quote:string}|null */
    public function parse(string $text): ?array
    {
        $text = TextCleaner::westernDigits($text);

        return $this->parseLatin($text) ?? $this->parseArabic($text);
    }

    /** Arabic posts put the currency word after the figure: 15000 جنيه شهريًا. */
    private function parseArabic(string $text): ?array
    {
        $figure = '\d{1,3}(?:[,.]\d{3})+|\d+(?:\.\d+)?';
        $pattern = '~(?<low>'.$figure.')(?:\s*(?:-|–|إلى|الى|حتى)\s*(?<high>'.$figure.'))?\s*(?<cur>\$|دولار|جنيه(?:\s+إسترليني)?|ريال(?:\s+(?:عماني|قطري))?|درهم|يورو)~u';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $length = strlen($match[0][0]);
            $around = $this->window($text, $offset, 80, $length + 60);
            $near = $this->window($text, $offset, 25, $length + 30);

            if (! preg_match('/راتب|مرتب(?![طةا])|الأجر|المقابل المادي/u', $around) || preg_match('/ميزانية|تمويل|إيرادات|مبيعات/u', $around) || preg_match('/بدل|مكافأة|مكافآت|تأمين|دورات|عمولة/u', $this->window($text, $offset, 50, $length + 12))) {
                continue;
            }

            $low = $this->amount($match['low'][0], '');
            $high = isset($match['high']) && $match['high'][1] !== -1 && $match['high'][0] !== '' ? $this->amount($match['high'][0], '') : $low;
            $period = match (true) {
                (bool) preg_match('/شهري|في الشهر|بالشهر/u', $near) => 'month',
                (bool) preg_match('/(?<!إجازة )سنوي|في السنة|بالسنة/u', $near) => 'year',
                (bool) preg_match('/الساعة|بالساعة/u', $near) => 'hour',
                default => 'month',
            };

            if ($low > 0 && $high / $low <= 4 && $this->plausible(max($low, $high), $period, self::ARABIC_CURRENCIES[preg_replace('/\s+/u', ' ', $match['cur'][0])])) {
                return [
                    'min' => min($low, $high),
                    'max' => max($low, $high),
                    'currency' => self::ARABIC_CURRENCIES[preg_replace('/\s+/u', ' ', $match['cur'][0])],
                    'period' => $period,
                    'quote' => trim($match[0][0]),
                ];
            }
        }

        return null;
    }

    private function parseLatin(string $text): ?array
    {
        $figure = '\d{1,3}(?:[,.\s]\d{3})+|\d+(?:\.\d+)?';
        $currency = '\$|€|£|\b(?:'.self::CODES.')\b';
        $dash = '\s*(?:-|–|—|to)\s*';
        $period = '(?:\s*(?:per|\/|a|an|each)\s*(?<per1>hour|hr|h|day|week|month|mo|year|yr|annum)\b|\s+(?<per2>hourly|daily|weekly|monthly|annually|yearly|annual))?';

        $pattern = "~(?<cur>{$currency})?\s*(?<low>{$figure})\s*(?<lowk>[kK])?(?:{$dash}(?:{$currency})?\s*(?<high>{$figure})\s*(?<highk>[kK])?)?(?:\s*(?<cur2>".self::CODES."))?{$period}~u";

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches as $match) {
            $parsed = $this->interpret($match, $text);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function interpret(array $match, string $text): ?array
    {
        $quote = $match[0][0];
        $offset = $match[0][1];

        $group = fn (string $name) => isset($match[$name]) && $match[$name][1] !== -1 ? $match[$name][0] : '';

        $code = $group('cur2') ?: (self::CURRENCY_SYMBOLS[$group('cur')] ?? $group('cur'));
        if ($code === '') {
            return null;
        }

        $window = $this->window($text, $offset, 70, strlen($quote) + 40);
        $close = $this->window($text, $offset, 35, strlen($quote) + 45);

        // A perk named right beside the figure vetoes it, whatever else the sentence says.
        if (preg_match(self::NOT_PAY, $close)) {
            return null;
        }

        $first = $this->amount($group('low'), $group('lowk'));
        $hasSecond = $group('high') !== '';
        // "$80-100k": the k on the upper bound applies to both.
        if ($hasSecond && $group('lowk') === '' && $group('highk') !== '' && $first < 1000) {
            $first *= 1000;
        }
        $second = $hasSecond ? $this->amount($group('high'), $group('highk')) : $first;

        $periodWord = strtolower($group('per1') ?: $group('per2'));
        $period = $this->period($periodWord);

        if ($period === null) {
            // Without a stated period, the words around the figure must say it is pay.
            if (! preg_match(self::CONTEXT, $window)) {
                return null;
            }
            $before = $this->window($text, $offset, 40, 0);
            $period = match (true) {
                (bool) preg_match('/monthly|per month|a month|شهري|في الشهر/iu', $before.' '.mb_strcut($text, $offset + strlen($quote), 30)) => 'month',
                (bool) preg_match('/annual|yearly|per year|a year/i', $before) => 'year',
                (bool) preg_match('/hourly|per hour|an hour/i', $before) => 'hour',
                in_array($code, self::MONTHLY_BY_DEFAULT, true) => 'month',
                $first >= 15000 => 'year',
                $first >= 500 => 'month',
                default => 'hour',
            };
        } elseif (! preg_match(self::CONTEXT, $window) && $this->looksTooSmallForAWage($first, $second, $period)) {
            return null;
        }

        [$min, $max] = [min($first, $second), max($first, $second)];

        if ($min <= 0 || $max / $min > 4 || ! $this->plausible($max, $period, $code)) {
            return null;
        }

        return ['min' => $min, 'max' => $max, 'currency' => $code, 'period' => $period, 'quote' => trim($quote)];
    }

    /** Byte offsets from preg, cut on character boundaries so /u patterns keep working. */
    private function window(string $text, int $offset, int $before, int $after): string
    {
        $start = max(0, $offset - $before);

        return mb_strcut($text, $start, $offset - $start + $after);
    }

    private function looksTooSmallForAWage(float $first, float $second, string $period): bool
    {
        $high = max($first, $second);

        return ($period === 'month' && $high < 1000) || ($period === 'year' && $high < 12000);
    }

    private function amount(string $digits, string $thousands): float
    {
        // Separators followed by exactly three digits are thousands, in any locale.
        $value = preg_match('/[,.\s]\d{3}(?:\D|$)/', $digits)
            ? (float) preg_replace('/\D/', '', $digits)
            : (float) $digits;

        return $thousands !== '' ? $value * 1000 : $value;
    }

    private function period(string $word): ?string
    {
        return match ($word) {
            'hour', 'hr', 'h', 'hourly' => 'hour',
            'day', 'daily' => 'day',
            'week', 'weekly' => 'week',
            'month', 'mo', 'monthly' => 'month',
            'year', 'yr', 'annum', 'annually', 'yearly', 'annual' => 'year',
            default => null,
        };
    }

    private function plausible(float $amount, string $period, string $currency): bool
    {
        [$low, $high] = match ($period) {
            'hour' => [3, 500],
            'day' => [30, 4000],
            'week' => [100, 20000],
            'month' => [100, 60000],
            'year' => [3000, 900000],
        };

        // The ceilings assume dollar-sized figures; pay in EGP is quoted in far larger numbers.
        return $amount >= $low && $amount <= ($currency === 'EGP' ? $high * 50 : $high);
    }
}

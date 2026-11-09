<?php

namespace Tests\Unit;

use App\Services\Normalization\SalaryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SalaryParserTest extends TestCase
{
    #[DataProvider('statedSalaries')]
    public function test_reads_stated_salaries(string $text, float $min, float $max, string $currency, string $period): void
    {
        $parsed = (new SalaryParser)->parse($text);

        $this->assertNotNull($parsed, $text);
        $this->assertSame([$min, $max, $currency, $period], [(float) $parsed['min'], (float) $parsed['max'], $parsed['currency'], $parsed['period']]);
    }

    public static function statedSalaries(): array
    {
        return [
            ['Salary: $80,000 - $100,000 per year', 80000, 100000, 'USD', 'year'],
            ['Compensation $80k-$100k', 80000, 100000, 'USD', 'year'],
            ['Pay range $80-100k annually', 80000, 100000, 'USD', 'year'],
            ['We pay USD 2,500/month', 2500, 2500, 'USD', 'month'],
            ['Rate: €45 per hour', 45, 45, 'EUR', 'hour'],
            ['Salary 5.000 EUR monthly', 5000, 5000, 'EUR', 'month'],
            ['Salary: £60k', 60000, 60000, 'GBP', 'year'],
            ['Stable Monthly Fixed Salary: $150 – $200 USD', 150, 200, 'USD', 'month'],
            ['Salary: AED 20,000', 20000, 20000, 'AED', 'month'],
        ];
    }

    #[DataProvider('notSalaries')]
    public function test_ignores_figures_that_are_not_pay(string $text): void
    {
        $this->assertNull((new SalaryParser)->parse($text), $text);
    }

    public static function notSalaries(): array
    {
        return [
            ['We raised $40 million in funding last year.'],
            ['Our customers processed $5,000 in transactions.'],
            ['We manage a budget of $90,000 for marketing.'],
            ['A $500 home office allowance on joining.'],
            ['Attend workshops or conferences to improve your personal development for up to €5000 per year'],
            ['You will get $100 per month to support projects you care about.'],
            ['Competitive salary plus $300/month wellness allowance'],
            ['We add a $150 monthly internet stipend.'],
            ['$200 per month for lunches.'],
            ['Great team, 2000 customers, no figures here.'],
        ];
    }
}

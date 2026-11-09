<?php

namespace App\Services\Evaluation;

final class RuleAnalysis
{
    public function __construct(
        public readonly int $technical,
        public readonly string $technicalNote,
        public readonly bool $titleConflict,
        public readonly int $seniority,
        public readonly int $responsibilities,
        public readonly int $growth,
        public readonly array $skillsMatched,
        public readonly array $skillsPartial,
        public readonly array $skillsMissing,
        public readonly array $skillsOptionalMissing,
        public readonly array $responsibilityHits,
        public readonly array $suspiciousFlags,
        public readonly array $concerns,
        public readonly ?string $companyInfo,
    ) {}
}

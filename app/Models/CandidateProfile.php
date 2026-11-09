<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateProfile extends Model
{
    public const ARABIC_TITLE_PATTERNS = [
        'مطور\s*(ال)?(ويب|مواقع|واجهات?|برمجيات|full|php|لارافيل|ووردبريس|laravel|wordpress|تطبيقات\s*الويب)',
        'مبرمج\s*(ال)?(ويب|مواقع|php|لارافيل|laravel)',
        'فو?ل\s*ستاك',
        'واجه(ات|ة)\s*(ال)?(أمامية|امامية)',
        'مهندس\s*(برمجيات\s*)?(ال)?(ويب|واجهات?)',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tracks' => 'array',
            'compensation' => 'array',
            'scoring_weights' => 'array',
            'thresholds' => 'array',
            'exclusions' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], self::defaults());
    }

    public function track(string $key): array
    {
        return $this->tracks[$key];
    }

    public function searchQueries(string $group = 'queries'): array
    {
        return collect($this->tracks)->flatMap(fn (array $track) => $track[$group] ?? [])->unique()->values()->all();
    }

    /** A starting point for a web developer. Everything is editable under Settings. */
    public static function defaults(): array
    {
        return [
            'locale' => config('app.locale'),
            'full_name' => 'Your Name',
            'headline' => 'Full-stack web developer',
            'country' => config('hunter.country'),
            'years_experience' => 5,
            'tracks' => [
                'web' => [
                    'label' => 'Web development',
                    'experience_years' => 5,
                    'anchor_skills' => ['Laravel', 'PHP', 'Livewire', 'Vue.js', 'WordPress', 'JavaScript', 'HTML', 'CSS'],
                    'skills' => [
                        'primary' => ['Laravel', 'PHP', 'Livewire', 'Blade', 'JavaScript', 'Alpine.js', 'HTML', 'CSS', 'Sass', 'Bootstrap', 'WordPress', 'MySQL', 'REST APIs', 'Figma'],
                        'secondary' => ['Vue.js', 'TypeScript', 'Tailwind CSS', 'Git', 'Inertia'],
                        'light' => ['React', 'Next.js', 'Nuxt', 'PostgreSQL', 'Redis', 'Docker'],
                    ],
                    'title_patterns' => [
                        'laravel', '\bphp\b', 'livewire', 'full[\s-]?stack', 'front[\s-]?end', '\bui (developer|engineer)\b',
                        'web (application |app |platform )?(developer|engineer)', 'cms developer', 'wordpress', 'vue(\.?js)? (developer|engineer)',
                        'technical lead', 'tech lead', 'web platform',
                        ...self::ARABIC_TITLE_PATTERNS,
                    ],
                    'generic_title_patterns' => ['software (engineer|developer)', 'back[\s-]?end', 'product engineer', 'application developer', 'programmer'],
                    'freelance_patterns' => [
                        'laravel', '\bphp\b', 'livewire', 'wordpress', '\bcms\b', 'dashboard', 'admin panel', 'api integration', 'front[\s-]?end', 'full[\s-]?stack',
                        'لارافيل', 'لارافل', 'ووردبريس', 'وردبريس', 'لوحة تحكم', 'لوحة التحكم', 'داشبورد', 'واجهة', 'واجهات', 'موقع إلكتروني', 'موقع الكتروني', 'تطوير موقع', 'نظام إدارة',
                    ],
                    'queries' => [
                        'laravel', 'php', 'livewire', 'full stack php', 'full stack', 'frontend', 'ui developer', 'wordpress', 'cms developer', 'web developer', 'vue', 'technical lead php',
                    ],
                    'project_queries' => [
                        'laravel', 'php maintenance', 'legacy php migration', 'custom cms', 'php performance optimization', 'admin dashboard php', 'third party api integration php', 'wordpress plugin integration', 'livewire',
                    ],
                    'responsibilities' => [
                        'production web application' => 'production|web applications?|web platform|تطبيقات\s*الويب|منصة',
                        'dashboards and admin panels' => 'dashboards?|admin panels?|back[\s-]?office|لوحات?\s*(ال)?تحكم|داشبورد',
                        'CMS work' => '\bcms\b|content management|publishing platform|إدارة\s*المحتوى',
                        'news and media platforms' => '\bnews\b|\bmedia\b|editorial|إخباري|أخبار|إعلام',
                        'API integrations' => 'api integrations?|third[\s-]party|integrat(e|ing) (with )?(external|apis?)|ربط|تكامل',
                        'maintaining existing systems' => 'maintain|legacy|existing (application|codebase|system)|refactor|صيانة|تطوير\s*النظام\s*الحالي',
                        'performance work' => 'performance|core web vitals|optimi[sz]|تحسين\s*(ال)?أداء|سرعة\s*الموقع',
                        'UI implementation' => 'pixel[\s-]perfect|implement(ing)? (designs?|ui)|from figma|design system|responsive|تحويل\s*التصاميم|متجاوب',
                        'technical coordination' => 'mentor|code reviews?|lead(ing)? (a |the )?team|technical leadership|قيادة\s*(ال)?فريق',
                    ],
                ],
                'security' => [
                    'label' => 'Cybersecurity / SOC',
                    'experience_years' => 0,
                    'anchor_skills' => ['SIEM', 'Linux', 'Network Security', 'Incident Response'],
                    'skills' => [
                        'primary' => [],
                        'secondary' => [],
                        'light' => ['SIEM', 'Linux', 'Network Security', 'Incident Response'],
                    ],
                    'title_patterns' => ['\bsoc\b', 'security operations', 'security analyst', 'cyber ?security analyst', 'incident response', 'cyber defen[sc]e', 'blue team'],
                    'generic_title_patterns' => ['security engineer'],
                    'freelance_patterns' => [],
                    'queries' => [],
                    'project_queries' => [],
                    'responsibilities' => [
                        'alert triage' => 'triage|alerts?|monitoring',
                        'SIEM work' => '\bsiem\b|splunk|sentinel|qradar',
                        'incident response' => 'incident',
                    ],
                ],
            ],
            'compensation' => [
                'full_time_floor' => 2500,
                'preferred_min' => 3500,
                'preferred_max' => 6000,
                'onsite_home_exceptional' => 6000,
                'undisclosed_score' => 60,
                'freelance_hard_min_hourly' => 15,
                'freelance_min_hourly' => 25,
                'freelance_target_hourly' => 50,
                'freelance_unknown_hours_score' => 55,
            ],
            'scoring_weights' => [
                'technical' => 30,
                'seniority' => 25,
                'compensation' => 20,
                'eligibility' => 10,
                'responsibilities' => 10,
                'growth' => 5,
            ],
            'thresholds' => [
                'immediate' => 85,
                'digest' => 75,
                'exceptional' => 90,
                'strong' => 80,
                'review' => 70,
                'low' => 60,
                'learning_max_points' => 8,
            ],
            'exclusions' => [
                'Gambling or betting' => ['pattern' => '\bgambling\b|\bbetting\b|\bcasino\b|sportsbook|\bigaming\b', 'scope' => 'lead'],
                'Crypto or web3' => ['pattern' => '\bcrypto\b|cryptocurrenc|blockchain|\bweb3\b|\bdefi\b|\bnfts?\b', 'scope' => 'lead'],
            ],
        ];
    }
}

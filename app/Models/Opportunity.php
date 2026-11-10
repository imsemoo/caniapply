<?php

namespace App\Models;

use App\Enums\Bucket;
use App\Enums\CountryEligibility;
use App\Enums\EmploymentType;
use App\Enums\Lifecycle;
use App\Enums\RemoteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Opportunity extends Model
{
    private const INLINE_REFRESH_LIMIT = 300;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'remote_scope' => RemoteScope::class,
            'country_eligibility' => CountryEligibility::class,
            'bucket' => Bucket::class,
            'lifecycle' => Lifecycle::class,
            'requires_relocation' => 'boolean',
            'needs_evaluation' => 'boolean',
            'client_signals' => 'array',
            'factors' => 'array',
            'analysis' => 'array',
            'salary_min' => 'float',
            'salary_max' => 'float',
            'monthly_usd_min' => 'float',
            'monthly_usd_max' => 'float',
            'budget_usd_min' => 'float',
            'budget_usd_max' => 'float',
            'estimated_hours' => 'float',
            'effective_hourly_usd' => 'float',
            'posted_at' => 'datetime',
            'expires_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_rechecked_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'lifecycle_changed_at' => 'datetime',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(OpportunityListing::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(OpportunityChange::class)->latest('created_at');
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }

    public function primarySource(): BelongsTo
    {
        return $this->belongsTo(JobSource::class, 'primary_source_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('lifecycle', [Lifecycle::Expired->value, Lifecycle::Closed->value]);
    }

    public function scopeWithoutDemo(Builder $query): void
    {
        $query->whereDoesntHave('primarySource', fn (Builder $source) => $source->where('key', 'demo'));
    }

    /** Beyond this many open jobs, rewriting them all would outlast a web request. */
    public static function fitsInOneRequest(): bool
    {
        return self::query()->active()->count() <= self::INLINE_REFRESH_LIMIT;
    }

    public function isFreelance(): bool
    {
        return $this->employment_type === EmploymentType::Freelance;
    }

    public function freshnessAt(): Carbon
    {
        return $this->posted_at ?? $this->first_seen_at;
    }

    public function publicationLabel(): string
    {
        return $this->posted_at ? $this->posted_at->diffForHumans() : __('Publication date not given');
    }

    public function compensationLabel(): string
    {
        if ($this->isFreelance()) {
            return $this->freelanceLabel();
        }

        if ($this->salary_status !== 'disclosed') {
            return __('Undisclosed');
        }

        $range = $this->formatRange($this->salary_min, $this->salary_max);
        $amount = $this->isolated(trim(($this->salary_currency ?? '').' '.$range));
        $label = $amount.' / '.__($this->salary_period ?? 'period not stated');

        if ($this->monthly_usd_max !== null) {
            $label .= ' ('.__('≈ :amount/month', ['amount' => $this->isolated('$'.$this->formatRange($this->monthly_usd_min, $this->monthly_usd_max))]).')';
        }

        return $label;
    }

    private function freelanceLabel(): string
    {
        if ($this->budget_usd_max === null) {
            return __('Budget not stated');
        }

        $amount = $this->isolated('$'.$this->formatRange($this->budget_usd_min, $this->budget_usd_max));
        $label = $this->budget_type === 'hourly' ? __(':amount/hour', ['amount' => $amount]) : __(':amount fixed', ['amount' => $amount]);

        if ($this->effective_hourly_usd !== null) {
            $label .= ' ('.__('≈ :amount/hour effective', ['amount' => $this->isolated('$'.number_format($this->effective_hourly_usd, 0))]).')';
        }

        return $label;
    }

    /** Keeps a Latin amount in one piece inside right-to-left text. */
    private function isolated(string $text): string
    {
        return app()->getLocale() === 'ar' ? "\u{2066}{$text}\u{2069}" : $text;
    }

    /** Evidence is stored in English so that re-scoring never has to rewrite it. */
    public function evidenceLabel(): ?string
    {
        if ($this->eligibility_evidence === null) {
            return null;
        }

        foreach (['The source lists your country, but the post says:', 'Post says:', 'Listing location:', 'Listing gives a place and does not say remote:'] as $prefix) {
            if (str_starts_with($this->eligibility_evidence, $prefix)) {
                return __($prefix).substr($this->eligibility_evidence, strlen($prefix));
            }
        }

        return __($this->eligibility_evidence);
    }

    public function timezoneLabel(): ?string
    {
        if ($this->timezone_note === null) {
            return null;
        }

        return preg_match('/^(.*?) \((.*)\)$/', $this->timezone_note, $parts)
            ? __($parts[1]).' ('.$parts[2].')'
            : __($this->timezone_note);
    }

    private function formatRange(?float $min, ?float $max): string
    {
        if ($min !== null && $max !== null && round($min) !== round($max)) {
            return number_format($min).'–'.number_format($max);
        }

        return number_format($max ?? $min ?? 0);
    }

    public function safeUrl(): ?string
    {
        return self::httpUrlOrNull($this->primary_url);
    }

    /** Feed content is untrusted: only ever link to http(s) targets. */
    public static function httpUrlOrNull(?string $url): ?string
    {
        return $url !== null && strlen($url) <= 2000 && preg_match('#^https?://[^\s<>"]+$#i', $url) ? $url : null;
    }
}

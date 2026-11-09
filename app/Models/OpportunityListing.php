<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityListing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(JobSource::class, 'job_source_id');
    }

    /** Tracking parameters and fragments differ between sites for the same page. */
    public static function urlKey(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower(preg_replace('/^www\./i', '', $parts['host'] ?? ''));
        $path = rtrim($parts['path'] ?? '', '/');

        parse_str($parts['query'] ?? '', $query);
        $query = array_filter($query, fn (string $key) => ! preg_match('/^(utm_|ref$|source$|gh_src|lever-|src$)/i', $key), ARRAY_FILTER_USE_KEY);
        ksort($query);

        return hash('sha256', strtolower($host.$path).'?'.http_build_query($query));
    }
}

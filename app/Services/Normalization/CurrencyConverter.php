<?php

namespace App\Services\Normalization;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class CurrencyConverter
{
    private const CACHE_KEY = 'fx:rates';

    public function toUsd(float $amount, ?string $currency): ?float
    {
        $rate = $this->rates()[strtoupper((string) $currency)] ?? null;

        return $rate ? $amount / $rate : null;
    }

    /** Daily job. On failure the previous rates stay in place. */
    public function refresh(): bool
    {
        try {
            $body = Http::timeout(20)->retry(2, 2000, throw: false)->get('https://open.er-api.com/v6/latest/USD')->json();
        } catch (Throwable) {
            return false;
        }

        if (($body['result'] ?? null) !== 'success' || empty($body['rates']['EUR'])) {
            return false;
        }

        Cache::forever(self::CACHE_KEY, $body['rates']);

        return true;
    }

    private function rates(): array
    {
        return Cache::get(self::CACHE_KEY) ?? config('hunter.fx_fallback');
    }
}

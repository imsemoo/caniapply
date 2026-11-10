<?php

namespace App\Sources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** The only door connectors use to reach the network: one identity, retries, pacing. */
class SourceHttp
{
    public function json(string $url, array $query = []): array
    {
        $decoded = $this->get($url, $query)->json();

        if (! is_array($decoded)) {
            throw new SourceFetchFailed("Response from {$this->hostOf($url)} was not JSON");
        }

        return $decoded;
    }

    public function text(string $url, array $query = []): string
    {
        return $this->get($url, $query)->body();
    }

    public function get(string $url, array $query = []): Response
    {
        $this->pause();

        try {
            $response = $this->client()->get($url, $query);
        } catch (ConnectionException $e) {
            throw new SourceFetchFailed("Could not reach {$this->hostOf($url)}: connection failed", previous: $e);
        }

        if (! $response->successful()) {
            throw new SourceFetchFailed("{$this->hostOf($url)} answered HTTP {$response->status()}");
        }

        return $response;
    }

    /** Status only, for rechecking whether a listing still exists. Null when unreachable. */
    public function statusOf(string $url): ?int
    {
        if (! $this->isPublicHost($url)) {
            return null;
        }

        $this->pause();

        try {
            // No redirects: each hop would need its own host check, and only 404 or 410 matter here.
            return Http::withUserAgent(config('hunter.user_agent'))->withoutRedirecting()->withOptions(['stream' => true])->timeout(20)->get($url)->status();
        } catch (Throwable) {
            return null;
        }
    }

    /** Listing URLs come from strangers; never let one aim a request at this server's own network. */
    public function isPublicHost(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** Minimal robots.txt check for the sources we read as HTML. */
    public function robotsAllow(string $url): bool
    {
        $parts = parse_url($url);
        $origin = $parts['scheme'].'://'.$parts['host'];
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $rules = Cache::remember('robots:'.$parts['host'], now()->addDay(), function () use ($origin) {
            try {
                return self::parseRobots($this->text($origin.'/robots.txt'));
            } catch (SourceFetchFailed) {
                return null;
            }
        });

        if ($rules === null) {
            return false;
        }

        foreach ($rules as $rule) {
            $pattern = '#^'.str_replace('\*', '.*', preg_quote($rule, '#')).'#';
            if (preg_match($pattern, $path)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] the Disallow rules that apply to every user agent */
    public static function parseRobots(string $body): array
    {
        $rules = [];
        $applies = false;
        $inAgentBlock = false;

        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                $applies = $inAgentBlock ? ($applies || $value === '*') : $value === '*';
                $inAgentBlock = true;

                continue;
            }

            $inAgentBlock = false;
            if ($applies && $field === 'disallow' && $value !== '') {
                $rules[] = $value;
            }
        }

        return $rules;
    }

    private function client(): PendingRequest
    {
        return Http::withUserAgent(config('hunter.user_agent'))
            ->timeout(45)
            ->connectTimeout(15)
            ->retry(3, function (int $attempt, Throwable $e) {
                // A dropped connection has no response to read.
                $retryAfter = $e instanceof RequestException ? (int) $e->response->header('Retry-After') : 0;

                return min(max($retryAfter * 1000, 1500 * $attempt), 30000);
            }, function (Throwable $e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true));
            }, throw: false);
    }

    private function pause(): void
    {
        $ms = (int) config('hunter.http_pause_ms');
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function hostOf(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: 'source';
    }
}

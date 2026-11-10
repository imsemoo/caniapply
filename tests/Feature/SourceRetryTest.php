<?php

namespace Tests\Feature;

use App\Sources\SourceHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SourceRetryTest extends TestCase
{
    public function test_a_dropped_connection_is_retried(): void
    {
        Sleep::fake();
        config(['hunter.http_pause_ms' => 0]);
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            if (str_contains($request->url(), 'robots.txt')) {
                return Http::response('', 404);
            }

            if (++$calls === 1) {
                throw new ConnectionException('Connection reset');
            }

            return Http::response(['ok' => true]);
        });

        $this->assertSame(['ok' => true], app(SourceHttp::class)->json('https://example.com/feed'));
        $this->assertSame(2, $calls);
    }

    public function test_a_server_error_is_still_retried(): void
    {
        Sleep::fake();
        config(['hunter.http_pause_ms' => 0]);
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            if (str_contains($request->url(), 'robots.txt')) {
                return Http::response('', 404);
            }

            return ++$calls === 1 ? Http::response('', 503, ['Retry-After' => '2']) : Http::response(['ok' => true]);
        });

        $this->assertSame(['ok' => true], app(SourceHttp::class)->json('https://example.com/feed'));
        $this->assertSame(2, $calls);
    }
}

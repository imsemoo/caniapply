<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements LlmProvider
{
    public function complete(string $system, string $user, ?array $jsonSchema): array
    {
        $config = config('hunter.llm');
        $outputConfig = ['effort' => 'low'];

        if ($jsonSchema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $jsonSchema];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $config['api_key'],
                'anthropic-version' => '2023-06-01',
            ])->timeout($config['timeout'])
                ->retry(3, fn (int $attempt) => 2000 * $attempt, fn ($e) => $e instanceof ConnectionException || in_array($e->response?->status(), [429, 500, 502, 503, 529], true), throw: false)
                ->post(rtrim($config['base_url'] ?: 'https://api.anthropic.com', '/').'/v1/messages', [
                    'model' => $config['model'],
                    'max_tokens' => $config['max_output_tokens'],
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                    'output_config' => $outputConfig,
                ]);
        } catch (ConnectionException) {
            throw new LlmFailed('Anthropic API unreachable');
        }

        if (! $response->successful()) {
            // Auth errors can echo part of the key, so their text is never kept.
            throw new LlmFailed(in_array($response->status(), [401, 403], true)
                ? 'Anthropic API rejected the key (HTTP '.$response->status().')'
                : 'Anthropic API answered HTTP '.$response->status().': '.mb_substr((string) $response->json('error.message'), 0, 300));
        }

        $stopReason = $response->json('stop_reason');
        if ($stopReason === 'refusal' || $stopReason === 'max_tokens') {
            throw new LlmFailed("Anthropic response stopped early: {$stopReason}");
        }

        $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? null;
        if ($text === null) {
            throw new LlmFailed('Anthropic response had no text block');
        }

        return [
            'content' => $text,
            'input_tokens' => (int) $response->json('usage.input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
    }
}

<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI, and any service exposing the same chat-completions format. */
class OpenAiCompatibleProvider implements LlmProvider
{
    public function complete(string $system, string $user, ?array $jsonSchema): array
    {
        $config = config('hunter.llm');

        $body = [
            'model' => $config['model'],
            $config['token_param'] => $config['max_output_tokens'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if ($jsonSchema !== null) {
            $body['response_format'] = $config['json_mode'] === 'object'
                ? ['type' => 'json_object']
                : ['type' => 'json_schema', 'json_schema' => ['name' => 'job_analysis', 'strict' => true, 'schema' => $jsonSchema]];
        }

        try {
            $response = Http::withToken((string) $config['api_key'])
                ->timeout($config['timeout'])
                ->retry(3, fn (int $attempt) => 2000 * $attempt, fn ($e) => $e instanceof ConnectionException || in_array($e->response?->status(), [429, 500, 502, 503], true), throw: false)
                ->post(rtrim($config['base_url'] ?: 'https://api.openai.com/v1', '/').'/chat/completions', $body);
        } catch (ConnectionException) {
            throw new LlmFailed('LLM API unreachable');
        }

        if (! $response->successful()) {
            // Auth errors can echo part of the key, so their text is never kept.
            throw new LlmFailed(in_array($response->status(), [401, 403], true)
                ? 'LLM API rejected the key (HTTP '.$response->status().')'
                : 'LLM API answered HTTP '.$response->status().': '.mb_substr((string) $response->json('error.message'), 0, 300));
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new LlmFailed('LLM response had no content');
        }

        return [
            'content' => $content,
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];
    }
}

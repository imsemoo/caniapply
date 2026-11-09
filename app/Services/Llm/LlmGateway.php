<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\DB;

/** Picks the configured provider and holds the daily spending cap. */
class LlmGateway
{
    public function isAvailable(): bool
    {
        return $this->provider() !== null && $this->callsToday() < config('hunter.llm.daily_limit');
    }

    public function engineLabel(): string
    {
        return config('hunter.llm.provider').':'.config('hunter.llm.model');
    }

    public function json(string $system, string $user, array $schema): array
    {
        $result = $this->call($system, $user, $schema);

        $decoded = json_decode($this->stripFence($result['content']), true);
        if (! is_array($decoded)) {
            throw new LlmFailed('LLM did not return valid JSON');
        }

        return $decoded;
    }

    public function text(string $system, string $user): string
    {
        return $this->call($system, $user, null)['content'];
    }

    public function callsToday(): int
    {
        return (int) DB::table('llm_usage')->where('day', today()->toDateString())->value('calls');
    }

    private function call(string $system, string $user, ?array $schema): array
    {
        $provider = $this->provider();
        if ($provider === null) {
            throw new LlmFailed('No LLM provider configured');
        }

        $result = $provider->complete($system, $user, $schema);
        $this->record($result);

        return $result;
    }

    private function record(array $result): void
    {
        $day = today()->toDateString();

        DB::table('llm_usage')->insertOrIgnore(['day' => $day]);
        DB::table('llm_usage')->where('day', $day)->update([
            'calls' => DB::raw('calls + 1'),
            'input_tokens' => DB::raw('input_tokens + '.(int) $result['input_tokens']),
            'output_tokens' => DB::raw('output_tokens + '.(int) $result['output_tokens']),
        ]);
    }

    private function provider(): ?LlmProvider
    {
        if (empty(config('hunter.llm.api_key'))) {
            return null;
        }

        return match (config('hunter.llm.provider')) {
            'anthropic' => new AnthropicProvider,
            'openai' => new OpenAiCompatibleProvider,
            default => null,
        };
    }

    private function stripFence(string $content): string
    {
        return trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($content)));
    }
}

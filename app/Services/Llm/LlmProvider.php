<?php

namespace App\Services\Llm;

interface LlmProvider
{
    /**
     * @return array{content: string, input_tokens: int, output_tokens: int}
     *
     * @throws LlmFailed
     */
    public function complete(string $system, string $user, ?array $jsonSchema): array;
}

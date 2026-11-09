<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBot
{
    private const MESSAGE_LIMIT = 4000;

    public function isConfigured(): bool
    {
        return filled(config('hunter.telegram.token')) && filled(config('hunter.telegram.chat_id'));
    }

    /** @return bool true only when Telegram accepted every part */
    public function send(string $html, ?array $keyboard = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        foreach ($this->split($html) as $index => $part) {
            $payload = [
                'chat_id' => config('hunter.telegram.chat_id'),
                'text' => $part,
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
            ];

            if ($index === 0 && $keyboard !== null && $keyboard !== []) {
                $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
            }

            if (! $this->post('sendMessage', $payload)) {
                return false;
            }
        }

        return true;
    }

    /** Finds the chat id of whoever last wrote to the bot. Used once during setup. */
    public function latestChatId(): ?string
    {
        try {
            $response = Http::timeout(20)->get($this->endpoint('getUpdates'));
        } catch (ConnectionException) {
            return null;
        }

        foreach (array_reverse($response->json('result', [])) as $update) {
            if (($update['message']['chat']['type'] ?? null) === 'private') {
                return (string) $update['message']['chat']['id'];
            }
        }

        return null;
    }

    /** Finds the private chat that sent this exact text, so a stranger messaging the bot cannot claim it. */
    public function chatThatSent(string $code): ?array
    {
        try {
            $response = Http::timeout(20)->get($this->endpoint('getUpdates'), ['allowed_updates' => json_encode(['message', 'callback_query'])]);
        } catch (ConnectionException) {
            return null;
        }

        foreach (array_reverse($response->json('result', [])) as $update) {
            $message = $update['message'] ?? [];

            if (($message['chat']['type'] ?? null) === 'private' && ($message['text'] ?? null) === $code) {
                return ['id' => (string) $message['chat']['id'], 'name' => trim(($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? ''))];
            }
        }

        return null;
    }

    /** @return array<int, array> updates after the given offset */
    public function updates(int $offset): array
    {
        try {
            return Http::timeout(20)->get($this->endpoint('getUpdates'), ['offset' => $offset, 'allowed_updates' => json_encode(['callback_query'])])->json('result', []);
        } catch (ConnectionException) {
            return [];
        }
    }

    public function answerPress(string $pressId, ?string $text = null): void
    {
        $this->post('answerCallbackQuery', array_filter(['callback_query_id' => $pressId, 'text' => $text]));
    }

    public function replaceKeyboard(string $chatId, int $messageId, array $keyboard): void
    {
        $this->post('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => $keyboard]]);
    }

    /** The bot's @name, or null when Telegram refuses the token. */
    public function username(): ?string
    {
        try {
            return Http::timeout(20)->get($this->endpoint('getMe'))->json('result.username');
        } catch (ConnectionException) {
            return null;
        }
    }

    private function post(string $method, array $payload, int $attempt = 1): bool
    {
        try {
            $response = Http::timeout(20)->post($this->endpoint($method), $payload);
        } catch (ConnectionException) {
            // The exception text holds the full URL, and the URL holds the bot token.
            Log::warning('Telegram unreachable');

            return false;
        }

        if ($response->status() === 429 && $attempt <= 3) {
            sleep(min((int) $response->json('parameters.retry_after', 5), 30));

            return $this->post($method, $payload, $attempt + 1);
        }

        if (! $response->successful()) {
            Log::warning('Telegram rejected a message', ['status' => $response->status(), 'description' => $response->json('description')]);

            return false;
        }

        return true;
    }

    private function endpoint(string $method): string
    {
        return rtrim(config('hunter.telegram.base_url'), '/').'/bot'.config('hunter.telegram.token').'/'.$method;
    }

    /** Splits on line breaks so a tag is never cut in half. */
    private function split(string $html): array
    {
        if (mb_strlen($html) <= self::MESSAGE_LIMIT) {
            return [$html];
        }

        $parts = [];
        $current = '';

        foreach (explode("\n", $html) as $line) {
            if (mb_strlen($current) + mb_strlen($line) + 1 > self::MESSAGE_LIMIT && $current !== '') {
                $parts[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n").$line;
        }

        $parts[] = $current;

        return $parts;
    }
}

<?php

namespace App\Services\Notifications;

use App\Enums\ApplicationStatus;
use App\Models\Opportunity;
use App\Services\Applications\ApplicationTracker;
use Illuminate\Support\Facades\Cache;

/** Applies the buttons pressed on alerts. Only records your own decisions. */
class TelegramActions
{
    private const OFFSET_KEY = 'telegram:update-offset';

    public function __construct(private TelegramBot $bot, private ApplicationTracker $tracker) {}

    /** @return int how many button presses were handled */
    public function poll(): int
    {
        if (! $this->bot->isConfigured()) {
            return 0;
        }

        $handled = 0;

        foreach ($this->bot->updates((int) Cache::get(self::OFFSET_KEY, 0)) as $update) {
            // Stored first, so one malformed update can never block the queue forever.
            Cache::forever(self::OFFSET_KEY, $update['update_id'] + 1);

            if (isset($update['callback_query'])) {
                $this->handlePress($update['callback_query']);
                $handled++;
            }
        }

        return $handled;
    }

    private function handlePress(array $press): void
    {
        $chatId = (string) ($press['message']['chat']['id'] ?? '');

        if ($chatId !== (string) config('hunter.telegram.chat_id') || ($press['message']['chat']['type'] ?? null) !== 'private') {
            $this->bot->answerPress($press['id'], __('Not allowed'));

            return;
        }

        if (! preg_match('/^job:(\d+):(saved|interested|applied|rejected)$/', (string) ($press['data'] ?? ''), $match)) {
            $this->bot->answerPress($press['id']);

            return;
        }

        $opportunity = Opportunity::query()->find((int) $match[1]);
        if ($opportunity === null) {
            $this->bot->answerPress($press['id']);

            return;
        }

        $status = ApplicationStatus::from($match[2]);
        $this->tracker->setStatus($opportunity, $status);

        $confirmation = __('Recorded: :status', ['status' => $status->label()]);
        $this->bot->answerPress($press['id'], $confirmation);

        $keyboard = array_values(array_filter($press['message']['reply_markup']['inline_keyboard'] ?? [], fn (array $row) => isset($row[0]['url'])));
        $keyboard[] = [['text' => $confirmation, 'callback_data' => 'noop']];
        $this->bot->replaceKeyboard($chatId, (int) $press['message']['message_id'], $keyboard);
    }
}

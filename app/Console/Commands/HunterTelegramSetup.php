<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

class HunterTelegramSetup extends Command
{
    private const WAIT_SECONDS = 180;

    protected $signature = 'hunter:telegram {--setup : Connect a new bot from its token} {--chat-id : Print the chat id of whoever last messaged the bot}';

    protected $description = 'Connect your Telegram bot, find your chat id, or send a test message';

    public function handle(TelegramBot $bot): int
    {
        if ($this->option('setup')) {
            return $this->setup($bot);
        }

        if (blank(config('hunter.telegram.token'))) {
            $this->error('No bot connected yet. Run: php artisan hunter:telegram --setup');

            return self::FAILURE;
        }

        if ($this->option('chat-id')) {
            $id = $bot->latestChatId();
            $id ? $this->info("TELEGRAM_CHAT_ID={$id}") : $this->error('No messages found. Send your bot any message, then run this again.');

            return $id ? self::SUCCESS : self::FAILURE;
        }

        return $this->sendTest($bot);
    }

    private function setup(TelegramBot $bot): int
    {
        $token = trim((string) $this->secret('Paste the token from BotFather (it stays hidden)'));

        if (! preg_match('/^\d+:[\w-]{20,}$/', $token)) {
            $this->error('That does not look like a bot token. Copy the whole line BotFather sent.');

            return self::FAILURE;
        }

        config(['hunter.telegram.token' => $token]);

        $username = $bot->username();
        if ($username === null) {
            $this->error('Telegram did not accept that token. Nothing was saved.');

            return self::FAILURE;
        }

        $code = Str::random(8);

        $this->info("Bot found: @{$username}");
        $this->line("Open Telegram and send this bot exactly this text: {$code}");
        $this->line('Waiting...');

        $chat = $this->waitForChat($bot, $code);
        if ($chat === null) {
            $this->error('No matching message arrived in time. Nothing was saved. Run this again.');

            return self::FAILURE;
        }

        if (! $this->confirm("Connect alerts to {$chat['name']}?", true)) {
            $this->error('Nothing was saved.');

            return self::FAILURE;
        }

        $this->saveToEnv(['TELEGRAM_BOT_TOKEN' => $token, 'TELEGRAM_CHAT_ID' => $chat['id']]);
        config(['hunter.telegram.chat_id' => $chat['id']]);

        Artisan::call('config:clear');
        Artisan::call('queue:restart');

        return $this->sendTest($bot);
    }

    private function waitForChat(TelegramBot $bot, string $code): ?array
    {
        $deadline = time() + self::WAIT_SECONDS;

        do {
            $chat = $bot->chatThatSent($code);
            if ($chat !== null) {
                return $chat;
            }
            sleep(3);
        } while (time() < $deadline);

        return null;
    }

    private function saveToEnv(array $values): void
    {
        $path = app()->environmentFilePath();
        $content = file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = "{$key}={$value}";
            $content = preg_replace_callback("/^{$key}=.*$/m", fn () => $line, $content, 1, $replaced);

            if ($replaced === 0) {
                $content = rtrim($content)."\n{$line}\n";
            }
        }

        // Written aside and swapped in, so a crash or a reader mid-write can never lose APP_KEY.
        $temporary = $path.'.tmp';

        if (file_put_contents($temporary, $content) !== strlen($content) || ! rename($temporary, $path)) {
            throw new RuntimeException('Could not update the .env file.');
        }
    }

    private function sendTest(TelegramBot $bot): int
    {
        if ($bot->send("<b>CanIApply is connected.</b>\nAlerts will arrive in this chat.")) {
            $this->info('Connected. Check Telegram for the test message.');

            return self::SUCCESS;
        }

        $this->error('Telegram did not accept the message. Check the token, the chat id and the log.');

        return self::FAILURE;
    }
}

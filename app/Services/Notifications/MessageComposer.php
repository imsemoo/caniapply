<?php

namespace App\Services\Notifications;

use App\Models\Opportunity;
use App\Models\OpportunityChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Every value from a feed is escaped here, since parse_mode is HTML. */
class MessageComposer
{
    public function immediate(Opportunity $job): string
    {
        $analysis = $job->analysis ?? [];
        $heading = $job->isFreelance() ? __('NEW FREELANCE PROJECT') : __('NEW HIGH-VALUE OPPORTUNITY');

        $lines = [
            "<b>{$heading}</b>",
            '',
            '<b>'.$this->e($this->linkFree(Str::squish($job->title))).'</b>',
        ];

        if (! $job->isFreelance()) {
            $lines[] = __('Company:').' '.$this->e($this->linkFree($job->company ?? __('Not named')));
        }

        array_push(
            $lines,
            __('Location:').' '.$this->e($job->remote_scope->label()).' ('.$this->e($job->country_eligibility->label()).')',
            __('Employment:').' '.$this->e($job->employment_type->label()),
            ($job->isFreelance() ? __('Budget:') : __('Salary:')).' '.$this->e($job->compensationLabel()),
            __('Match:')." <b>{$job->score}/100</b> (".__((string) $job->tier).')',
        );

        $lines = array_merge($lines, $this->bullets(__('Why it matches'), $this->withoutLinks(array_slice($analysis['why_apply'] ?? [], 0, 4))));
        $lines = array_merge($lines, $this->bullets(__('Potential concerns'), $this->withoutLinks(array_slice($analysis['concerns'] ?? [], 0, 4))));

        array_push(
            $lines,
            '',
            __('Publication:').' '.$this->e($job->publicationLabel()),
            __('Source:').' '.$this->e($job->listings->map(fn ($l) => $l->source->label)->unique()->implode(', ')),
            '',
            '<b>'.__('Recommendation').'</b>',
            $this->e($this->withoutLinks([$analysis['recommendation'] ?? ''])[0]),
            $job->evaluated_by === 'rules' ? '<i>'.__('Scored by the rule engine, no AI model').'</i>' : '',
        );

        return trim(implode("\n", $lines));
    }

    /** The first row is always the link, which TelegramActions keeps after a press. */
    public function immediateKeyboard(Opportunity $job): array
    {
        $keyboard = $this->linkRow($job);

        $keyboard[] = array_map(
            fn (string $status, string $label) => ['text' => __($label), 'callback_data' => "job:{$job->id}:{$status}"],
            ['saved', 'interested', 'applied', 'rejected'],
            ['Save', 'Interested', 'Applied', 'Reject'],
        );

        return $keyboard;
    }

    public function linkRow(Opportunity $job): array
    {
        return $job->safeUrl() !== null ? [[['text' => __('Open listing'), 'url' => $job->safeUrl()]]] : [];
    }

    public function change(Opportunity $job, Collection $changes): string
    {
        $lines = ['<b>'.__('LISTING CHANGED').'</b>', '', '<b>'.$this->e($this->linkFree(Str::squish($job->title))).'</b>', __('Company:').' '.$this->e($this->linkFree($job->company ?? __('Not named'))), ''];

        foreach ($changes as $change) {
            /** @var OpportunityChange $change */
            $lines[] = $change->field === 'salary'
                ? __('Salary:').' '.$this->e($change->old_value).' ← <b>'.$this->e($change->new_value).'</b>'
                : __('The requirements were rewritten. Read the listing again.');
        }

        $lines[] = '';
        $lines[] = __('Match now:')." <b>{$job->score}/100</b>";

        return implode("\n", $lines);
    }

    public function digest(Collection $top, Collection $undisclosed, Collection $freelance, int $flagged, array $stats): string
    {
        $lines = [
            '<b>'.__('DAILY DIGEST').'</b>',
            __(':new new today · :qualified qualified · :flagged flagged for review', ['new' => $stats['new_today'], 'qualified' => $stats['qualified_today'], 'flagged' => $flagged]),
        ];

        if ($top->isEmpty() && $undisclosed->isEmpty() && $freelance->isEmpty()) {
            $lines[] = '';
            $lines[] = __('Nothing new reached your digest threshold.');

            return implode("\n", $lines);
        }

        $lines = array_merge($lines, $this->section(__('Salary meets your minimum'), $top));
        $lines = array_merge($lines, $this->section(__('Salary undisclosed, your call'), $undisclosed));
        $lines = array_merge($lines, $this->section(__('Freelance projects'), $freelance));

        return implode("\n", $lines);
    }

    public function weekly(array $figures): string
    {
        $lines = [
            '<b>'.__('WEEKLY MARKET REPORT').'</b>',
            $this->e($figures['period']),
            '',
            __('Discovered: :discovered · Qualified: :qualified · Rejected: :rejected', ['discovered' => $figures['discovered'], 'qualified' => $figures['qualified'], 'rejected' => $figures['rejected']]),
            __('Salary disclosed on :percent% of listings', ['percent' => $figures['disclosed_percent']]),
        ];

        if ($figures['median_monthly_usd'] !== null) {
            $lines[] = __('Median stated pay: about $:amount/month', ['amount' => number_format($figures['median_monthly_usd'])]);
        }

        $lines = array_merge($lines, $this->ranked(__('Most requested skills'), $figures['top_skills']));
        $lines = array_merge($lines, $this->ranked(__('Best sources (qualified jobs)'), $figures['top_sources']));
        $lines = array_merge($lines, $this->ranked(__('Top rejection reasons'), $figures['top_rejections']));

        if ($figures['failing_sources'] !== []) {
            $lines[] = '';
            $lines[] = __('Sources needing attention:').' '.$this->e(implode(', ', $figures['failing_sources']));
        }

        return implode("\n", $lines);
    }

    private function section(string $title, Collection $jobs): array
    {
        if ($jobs->isEmpty()) {
            return [];
        }

        $lines = ['', "<b>{$this->e($title)}</b>"];

        foreach ($jobs as $job) {
            $name = $this->e($this->linkFree(Str::squish($job->title)));
            $url = $job->safeUrl();
            $link = $url !== null ? '<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">'.$name.'</a>' : $name;
            $company = $job->company ? ' · '.$this->e($this->linkFree(Str::squish($job->company))) : '';
            $lines[] = "{$job->score} · {$link}{$company}";
            $lines[] = '   '.$this->e($job->compensationLabel()).' · '.$this->e($job->remote_scope->label()).' · '.$this->e($job->publicationLabel());
        }

        return $lines;
    }

    private function bullets(string $title, array $items): array
    {
        if ($items === []) {
            return [];
        }

        return ['', "<b>{$title}</b>", ...array_map(fn (string $item) => '• '.$this->e($item), $items)];
    }

    private function ranked(string $title, array $counts): array
    {
        if ($counts === []) {
            return [];
        }

        $lines = ['', "<b>{$title}</b>"];
        foreach ($counts as $name => $count) {
            $lines[] = '• '.$this->e($name).': '.$count;
        }

        return $lines;
    }

    private function linkFree(string $text): string
    {
        return preg_replace('~\b(?:https?://|www\.|t\.me/)\S+|\b(?<!asp\.)[\w-]+(?:\.[\w-]+)*\.(?:com|net|org|io|co|me|ly|app|dev|xyz)\b(?:/\S*)?~i', '[link removed]', $text);
    }

    /** Telegram turns bare URLs into links, so model-written text must not carry any. */
    private function withoutLinks(array $items): array
    {
        return array_map(fn (string $item) => $this->linkFree($item), $items);
    }

    private function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8');
    }
}

<div>
    <header class="page-head">
        <a class="back" href="{{ route('jobs') }}" wire:navigate>{{ __('Jobs') }}</a>
        <h1 dir="auto">{{ $job->title }}</h1>
        <p class="sub" dir="auto">{{ $job->company ?: ($job->isFreelance() ? __('Freelance project') : __('Employer not named')) }} · {{ $job->employment_type->label() }} · {{ __('Published: :when', ['when' => $job->publicationLabel()]) }} · {{ $job->lifecycle->label() }}</p>
    </header>

    <div class="verdict">
        <div class="verdict-score">
            <span @class(['score', 'big', 'high' => $job->score >= 80, 'muted' => $job->score< 70])>{{ $job->score ?? '–' }}</span>
            <span class="sub">{{ $job->tier ? __($job->tier) : __('Not scored yet') }}</span>
        </div>
        <div class="verdict-text">
            <p>{{ $analysis['recommendation'] ?? __('Waiting for evaluation.') }}</p>
            @if ($job->reject_reason)<p class="reject">{{ __('Rejected: :reason', ['reason' => $job->reject_reason]) }}</p>@endif
            <p class="sub">{{ $job->bucket->label() }} · {{ $job->evaluated_by === 'rules' ? __('Scored by the rule engine, no AI model') : __('Scored by :engine', ['engine' => $job->evaluated_by ?? '–']) }}</p>
        </div>
        <div class="verdict-actions">
            @if ($job->safeUrl())
                <a class="btn primary" href="{{ $job->safeUrl() }}" target="_blank" rel="noopener noreferrer nofollow">{{ __('Open original listing') }}</a>
            @endif
            <div class="btn-row">
                @foreach (['saved' => 'Save', 'interested' => 'Interested', 'applied' => 'Applied', 'rejected' => 'Reject'] as $value => $label)
                    <button type="button" @class(['btn', 'small', 'selected' => $job->application?->status->value === $value]) wire:click="mark('{{ $value }}')">{{ __($label) }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="detail">
        <div class="detail-main">
            <section class="block">
                <h2>{{ __('Job description') }}</h2>
                <div class="prose" dir="auto">{{ $job->description }}</div>
            </section>
        </div>

        <div class="detail-rail">
            <section class="block">
                <h2>{{ __('Compensation and location') }}</h2>
                <dl class="facts">
                    <div><dt>{{ $job->isFreelance() ? __('Budget') : __('Salary') }}</dt><dd>{{ $job->compensationLabel() }}@if ($job->salary_origin)<span class="sub">{{ __('From :origin', ['origin' => __($job->salary_origin)]) }}</span>@endif</dd></div>
                    <div><dt>{{ __('Remote scope') }}</dt><dd>{{ $job->remote_scope->label() }}</dd></div>
                    <div><dt>{{ __('From your country') }}</dt><dd class="elig-{{ $job->country_eligibility->value }}">{{ $job->country_eligibility->label() }}@if ($job->eligibility_evidence)<span class="sub" dir="auto">{{ $job->evidenceLabel() }}</span>@endif</dd></div>
                    @if ($job->location_text)<div><dt>{{ __('Listed as') }}</dt><dd dir="auto">{{ $job->location_text }}</dd></div>@endif
                    @if ($job->timezone_note)<div><dt>{{ __('Hours') }}</dt><dd dir="auto">{{ $job->timezoneLabel() }}</dd></div>@endif
                    <div><dt>{{ __('Seniority') }}</dt><dd>{{ __(ucfirst($job->seniority ?? 'Not stated')) }}@if ($job->required_years) · {{ __(':years+ years asked', ['years' => $job->required_years]) }} @endif</dd></div>
                    @if ($job->estimated_hours)<div><dt>{{ __('Estimated hours') }}</dt><dd>{{ number_format($job->estimated_hours) }}@if ($analysis['hours_basis'] ?? null)<span class="sub">{{ $analysis['hours_basis'] }}</span>@endif</dd></div>@endif
                    @foreach ($job->client_signals ?? [] as $signal => $value)
                        <div wire:key="signal-{{ $loop->index }}"><dt>{{ __(ucfirst($signal)) }}</dt><dd dir="auto">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="block">
                <h2>{{ __('Match breakdown') }}</h2>
                <table class="factors">
                    <tbody>
                    @foreach ($job->factors ?? [] as $name => $factor)
                        <tr wire:key="factor-{{ $name }}">
                            <th scope="row">{{ __(ucfirst($name)) }}<span class="sub">{{ __(':weight% of score', ['weight' => $factor['weight'] ?? '']) }}</span></th>
                            <td><span class="bar"><i style="inline-size: {{ $factor['score'] }}%"></i></span></td>
                            <td class="num">{{ $factor['score'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if ($analysis['preference']['points'] ?? 0)
                    <p class="sub preference">{{ __(':points points from your past decisions', ['points' => sprintf('%+d', $analysis['preference']['points'])]) }}@foreach ($analysis['preference']['reasons'] as $reason)<span class="sub" dir="auto">{{ $reason }}</span>@endforeach</p>
                @endif
            </section>

            <x-point-list :title="__('Why apply')" :items="$analysis['why_apply'] ?? []" />
            <x-point-list :title="__('Potential concerns')" :items="$analysis['concerns'] ?? []" />
            <x-point-list :title="__('Red flags')" :items="$analysis['red_flags'] ?? []" />

            <section class="block">
                <h2>{{ __('Skills') }}</h2>
                <dl class="facts">
                    <div><dt>{{ __('You have') }}</dt><dd dir="auto">{{ implode(', ', $analysis['skills_matched'] ?? []) ?: __('None recognised') }}</dd></div>
                    @if ($analysis['skills_partial'] ?? [])<div><dt>{{ __('Some experience') }}</dt><dd>{{ implode(', ', $analysis['skills_partial']) }}</dd></div>@endif
                    <div><dt>{{ __('Missing') }}</dt><dd dir="auto">{{ implode(', ', $analysis['skills_missing'] ?? []) ?: __('Nothing listed') }}</dd></div>
                    @if ($analysis['skills_optional_missing'] ?? [])<div><dt>{{ __('Nice to have') }}</dt><dd>{{ implode(', ', $analysis['skills_optional_missing']) }}</dd></div>@endif
                </dl>
            </section>

            <section class="block">
                <h2>{{ __('Company') }}</h2>
                <p dir="auto">{{ $analysis['company_info'] ?? __('The listing says nothing about the company.') }}</p>
            </section>

            <section class="block">
                <h2>{{ __('Found on') }}</h2>
                <ul class="plain-list">
                    @foreach ($job->listings as $listing)
                        <li wire:key="listing-{{ $listing->id }}">
                            @php($href = \App\Models\Opportunity::httpUrlOrNull($listing->url))
                            @if ($href)<a href="{{ $href }}" target="_blank" rel="noopener noreferrer nofollow">{{ $listing->source->label }}</a>@else{{ $listing->source->label }}@endif
                            <span class="sub">{{ __('First seen :when', ['when' => $listing->first_seen_at->diffForHumans()]) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            @if ($job->changes->isNotEmpty())
                <section class="block">
                    <h2>{{ __('Changes') }}</h2>
                    <ul class="plain-list">
                        @foreach ($job->changes as $change)
                            <li wire:key="change-{{ $change->id }}">{{ $change->field === 'salary' ? __('Salary changed') : __('Requirements changed') }}
                                @if ($change->field === 'salary')<span class="sub" dir="auto">{{ __(':old to :new', ['old' => $change->old_value, 'new' => $change->new_value]) }}</span>@endif
                                <span class="sub">{{ $change->created_at->diffForHumans() }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>

    <section class="block tracker">
        <div class="block-head">
            <h2>{{ __('Application') }}</h2>
            <button type="button" class="btn" wire:click="generateStrategy" wire:loading.attr="disabled" wire:target="generateStrategy">
                <span wire:loading.remove wire:target="generateStrategy">{{ $job->application?->strategy ? __('Regenerate strategy') : __('Generate application strategy') }}</span>
                <span wire:loading wire:target="generateStrategy">{{ __('Working…') }}</span>
            </button>
        </div>

        <p class="sub">{{ __('This only records what you do. Nothing is ever sent to an employer from here.') }}</p>

        @if ($job->application?->strategy)
            <div class="strategy">
                <h3>{{ __('Strategy') }}</h3>
                <div class="prose" dir="auto">{{ $job->application->strategy }}</div>
                <p class="sub">{{ $job->application->strategy_engine === 'rules' ? __('Written by the rule engine, no AI model') : __('Written by :engine', ['engine' => $job->application->strategy_engine]) }}</p>
            </div>
        @endif

        <div class="letter" x-data="{ copied: false }">
            <div class="block-head">
                <h3>{{ __('Application letter') }}</h3>
                <button type="button" class="btn" wire:click="writeLetter" wire:loading.attr="disabled" wire:target="writeLetter" @disabled(! $canWriteLetter)>
                    <span wire:loading.remove wire:target="writeLetter">{{ $cover_letter ? __('Rewrite the draft') : __('Write a draft') }}</span>
                    <span wire:loading wire:target="writeLetter">{{ __('Working…') }}</span>
                </button>
            </div>
            @if (! $canWriteLetter)<p class="sub">{{ __('Needs an AI provider. Set LLM_PROVIDER and LLM_API_KEY in .env.') }}</p>@endif
            @if ($letterError)<p class="error" role="alert">{{ $letterError }}</p>@endif
            @if ($cover_letter !== null && $cover_letter !== '')
                <div class="field"><label class="sr" for="a-letter">{{ __('Application letter') }}</label><textarea id="a-letter" rows="12" dir="auto" x-ref="letter" wire:model="cover_letter"></textarea>@error('cover_letter')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="form-foot">
                    <button type="button" class="btn" wire:click="saveLetter">{{ __('Save letter') }}</button>
                    <button type="button" class="btn" x-on:click="navigator.clipboard.writeText($refs.letter.value); copied = true; setTimeout(() => copied = false, 1500)"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span></button>
                    @if ($letterSaved)<span class="ok" role="status">{{ __('Saved letter') }}</span>@endif
                    <span class="sub">{{ __('You send it yourself. Nothing leaves this system.') }}</span>
                </div>
            @endif
        </div>

        <form wire:submit="saveApplication" class="form-grid">
            <div class="field"><label for="a-status">{{ __('Status') }}</label>
                <select id="a-status" wire:model="status">@foreach ($statuses as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach</select>
                @error('status')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="a-date">{{ __('Application date') }}</label><input id="a-date" type="date" wire:model="applied_on">@error('applied_on')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="a-follow">{{ __('Follow-up date') }}</label><input id="a-follow" type="date" wire:model="follow_up_on">@error('follow_up_on')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="a-salary">{{ __('Salary offered') }}</label><input id="a-salary" type="text" wire:model="salary_offered">@error('salary_offered')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="a-contact">{{ __('Contact name') }}</label><input id="a-contact" type="text" wire:model="contact_name">@error('contact_name')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="a-details">{{ __('Contact details') }}</label><input id="a-details" type="text" wire:model="contact_details">@error('contact_details')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field span"><label for="a-interview">{{ __('Interview notes') }}</label><textarea id="a-interview" rows="4" dir="auto" wire:model="interview_notes"></textarea>@error('interview_notes')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="field span"><label for="a-notes">{{ __('Personal notes') }}</label><textarea id="a-notes" rows="4" dir="auto" wire:model="notes"></textarea>@error('notes')<p class="error">{{ $message }}</p>@enderror</div>
            <div class="span form-foot">
                <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="saveApplication">{{ __('Save application') }}</button>
                @if ($saved)<span class="ok" role="status">{{ __('Saved') }}</span>@endif
            </div>
        </form>
    </section>
</div>

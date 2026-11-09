<div>
    <header class="page-head"><h1>{{ __('Settings') }}</h1></header>

    @if (session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif

    <form wire:submit="save">
        <section class="block">
            <h2>{{ __('Profile') }}</h2>
            <div class="form-grid">
                <div class="field"><label for="s-name">{{ __('Name') }}</label><input id="s-name" type="text" wire:model="full_name">@error('full_name')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="s-headline">{{ __('Job title for letters') }}</label><input id="s-headline" type="text" wire:model="headline">@error('headline')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="s-country">{{ __('Country') }}</label><select id="s-country" wire:model="country" aria-describedby="s-country-note">@foreach ($countries as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select><p class="sub" id="s-country-note">{{ __('Changing the country re-checks every open job for the new country.') }}</p>@error('country')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="s-years">{{ __('Years in web development') }}</label><input id="s-years" type="number" min="0" wire:model="years_experience">@error('years_experience')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="s-sec">{{ __('Years in security work') }}</label><input id="s-sec" type="number" min="0" wire:model="securityYears">@error('securityYears')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="field span"><label for="s-primary">{{ __('Skills you use daily') }}</label><textarea id="s-primary" rows="2" wire:model="webPrimary"></textarea></div>
                <div class="field span"><label for="s-secondary">{{ __('Skills you have used on real projects') }}</label><textarea id="s-secondary" rows="2" wire:model="webSecondary"></textarea></div>
                <div class="field span"><label for="s-light">{{ __('Limited experience') }}</label><textarea id="s-light" rows="2" wire:model="webLight"></textarea></div>
                <div class="field span half"><label for="s-q">{{ __('Job searches, one per line') }}</label><textarea id="s-q" rows="7" wire:model="webQueries"></textarea>@error('webQueries')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="field span half"><label for="s-pq">{{ __('Freelance project searches, one per line') }}</label><textarea id="s-pq" rows="7" wire:model="projectQueries"></textarea>@error('projectQueries')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
        </section>

        <section class="block">
            <h2>{{ __('Money, in USD') }}</h2>
            <div class="form-grid">
                @foreach ([
                    'full_time_floor' => 'Full-time floor, per month',
                    'preferred_min' => 'Preferred from, per month',
                    'preferred_max' => 'Preferred to, per month',
                    'onsite_home_exceptional' => 'On-site in your country needs, per month',
                    'freelance_hard_min_hourly' => 'Freelance: reject below, per hour',
                    'freelance_min_hourly' => 'Freelance: acceptable from, per hour',
                    'freelance_target_hourly' => 'Freelance: target, per hour',
                    'undisclosed_score' => 'Score given to an undisclosed salary',
                    'freelance_unknown_hours_score' => 'Score when project hours are unknown',
                ] as $key => $label)
                    <div class="field" wire:key="c-{{ $key }}"><label for="c-{{ $key }}">{{ __($label) }}</label><input id="c-{{ $key }}" type="number" min="0" step="1" wire:model="compensation.{{ $key }}">@error("compensation.$key")<p class="error">{{ $message }}</p>@enderror</div>
                @endforeach
            </div>
        </section>

        <section class="block">
            <h2>{{ __('Scoring weights') }}</h2>
            <p class="sub">{{ __('Must add up to 100.') }}</p>
            <div class="form-grid">
                @foreach ($weights as $key => $value)
                    <div class="field" wire:key="w-{{ $key }}"><label for="w-{{ $key }}">{{ __($this->labelFor($key)) }}</label><input id="w-{{ $key }}" type="number" min="0" max="100" wire:model="weights.{{ $key }}">@error("weights.$key")<p class="error">{{ $message }}</p>@enderror</div>
                @endforeach
            </div>
            @error('weights')<p class="error">{{ $message }}</p>@enderror
        </section>

        <section class="block">
            <h2>{{ __('Thresholds') }}</h2>
            <div class="form-grid">
                @foreach (['immediate' => 'Telegram right away from', 'digest' => 'Daily digest from', 'exceptional' => 'Exceptional from', 'strong' => 'Strong from', 'review' => 'Worth reviewing from', 'low' => 'Reject below', 'learning_max_points' => 'Most points your decisions can add or remove (0 turns it off)'] as $key => $label)
                    <div class="field" wire:key="t-{{ $key }}"><label for="t-{{ $key }}">{{ __($label) }}</label><input id="t-{{ $key }}" type="number" min="0" max="100" wire:model="thresholds.{{ $key }}">@error("thresholds.$key")<p class="error">{{ $message }}</p>@enderror</div>
                @endforeach
            </div>
        </section>

        <div class="form-foot">
            <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save settings') }}</button>
            <button type="button" class="btn" wire:click="rescoreAll">{{ __('Re-score every open job') }}</button>
            @if ($saved)<span class="ok" role="status">{{ __('Saved. New jobs use these settings.') }}</span>@endif
        </div>
    </form>
</div>

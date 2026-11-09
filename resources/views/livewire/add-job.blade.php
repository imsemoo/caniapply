<div>
    <header class="page-head">
        <a class="back" href="{{ route('jobs') }}" wire:navigate>{{ __('Jobs') }}</a>
        <h1>{{ __('Add a job') }}</h1>
        <p class="sub">{{ __('For listings on LinkedIn, Indeed and other sites this system cannot read by itself. Paste it and it gets the same scoring.') }}</p>
    </header>

    <form wire:submit="save" class="form-grid narrow">
        <div class="field span half"><label for="j-title">{{ __('Job title') }}</label><input id="j-title" type="text" dir="auto" wire:model="title">@error('title')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="field span half"><label for="j-company">{{ __('Company') }}</label><input id="j-company" type="text" dir="auto" wire:model="company">@error('company')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="field span"><label for="j-url">{{ __('Link to the listing') }}</label><input id="j-url" type="url" dir="ltr" wire:model="url">@error('url')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="field span half"><label for="j-loc">{{ __('Location as written in the listing') }}</label><input id="j-loc" type="text" dir="auto" wire:model="location">@error('location')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="j-emp">{{ __('Employment') }}</label><select id="j-emp" wire:model="employment"><option value="">{{ __('Not stated') }}</option><option value="full_time">{{ __('Full-time') }}</option><option value="part_time">{{ __('Part-time') }}</option><option value="contract">{{ __('Contract') }}</option></select></div>
        <div class="field"><label for="j-track">{{ __('Career track') }}</label><select id="j-track" wire:model="track"><option value="web">{{ __('Web development') }}</option><option value="security">{{ __('Cybersecurity / SOC') }}</option></select></div>
        <label class="check span"><input type="checkbox" wire:model="remote"> {{ __('The listing says remote') }}</label>
        <label class="check span"><input type="checkbox" wire:model="freelance"> {{ __('This is a freelance project') }}</label>
        <div class="field span"><label for="j-desc">{{ __('Full job description') }}</label><textarea id="j-desc" rows="14" dir="auto" wire:model="description"></textarea>@error('description')<p class="error">{{ $message }}</p>@enderror</div>
        <div class="span form-foot"><button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="save">{{ __('Score this job') }}</button></div>
    </form>
</div>

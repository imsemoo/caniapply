<?php

namespace App\Sources;

use App\Models\JobSource as JobSourceRecord;

class SourceRegistry
{
    public function __construct(private SourceHttp $http) {}

    public function connector(string $key): JobSource
    {
        $definition = config("hunter.sources.{$key}");
        abort_if($definition === null, 500, "Unknown source {$key}");

        return new ($definition['class'])($this->http, $definition['options'] ?? []);
    }

    /** Adding a connector to config is all it takes for it to appear here. */
    public function sync(): void
    {
        foreach (config('hunter.sources') as $key => $definition) {
            JobSourceRecord::query()->updateOrCreate(
                ['key' => $key],
                ['label' => $definition['label'], 'type' => $definition['type']],
            );
        }

        JobSourceRecord::query()->updateOrCreate(['key' => 'manual'], ['label' => 'Added by hand', 'type' => 'manual', 'health' => 'ok']);
    }
}

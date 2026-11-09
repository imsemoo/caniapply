<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class HunterBackup extends Command
{
    protected $signature = 'hunter:backup';

    protected $description = 'Write a consistent SQLite snapshot and prune old ones';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->warn('Only SQLite is backed up here. For MySQL use mysqldump (see docs/DEPLOYMENT.md).');

            return self::SUCCESS;
        }

        $directory = config('hunter.backup.path');
        File::ensureDirectoryExists($directory);

        $target = $directory.'/hunter-'.now()->format('Ymd-His').'.sqlite';

        // VACUUM INTO gives a consistent copy even while the worker is writing.
        DB::statement('VACUUM INTO ?', [$target]);

        $files = collect(File::glob($directory.'/hunter-*.sqlite'))->sort()->values();
        $files->slice(0, max(0, $files->count() - config('hunter.backup.keep')))->each(fn (string $old) => File::delete($old));

        $this->info('Backup written: '.basename($target));

        return self::SUCCESS;
    }
}

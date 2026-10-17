<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class EnsureCampuses extends Command
{
    protected $signature = 'app:ensure-campuses';

    protected $description = 'Add any missing ASCOT campuses and schools (safe to run on every deploy)';

    public function handle(): int
    {
        // Registration needs these lists before any account exists.
        CreateAdmin::ensureCampuses();
        $this->info('Campuses and schools are ready.');

        return self::SUCCESS;
    }
}

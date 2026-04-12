<?php

namespace GhostCompiler\UploadsManager\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'uploads-manager:install';

    protected $description = 'Publish the Uploads Manager config and migration files';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'uploads-manager-config',
            '--force' => false,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'uploads-manager-migrations',
            '--force' => false,
        ]);

        $this->components->info('Uploads Manager assets published successfully.');

        return self::SUCCESS;
    }
}

<?php

namespace GhostCompiler\LaravelUploads\Commands;

use GhostCompiler\LaravelUploads\Models\UploadLink;
use Illuminate\Console\Command;

class CleanupExpiredLinksCommand extends Command
{
    protected $signature = 'ghost:laravel-uploads-clean {--dry-run : Show how many expired links would be removed without deleting them}';

    protected $description = 'Delete expired Laravel Uploads links';

    public function handle(): int
    {
        $query = UploadLink::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        $count = (clone $query)->count();

        if ((bool) $this->option('dry-run')) {
            $this->components->info("{$count} expired Laravel Uploads links would be removed.");

            return self::SUCCESS;
        }

        $query->delete();

        $this->components->info("Removed {$count} expired Laravel Uploads links.");

        return self::SUCCESS;
    }
}

<?php

namespace GhostCompiler\UploadsManager\Commands;

use GhostCompiler\UploadsManager\Models\UploadLink;
use Illuminate\Console\Command;

class CleanupExpiredLinksCommand extends Command
{
    protected $signature = 'ghost:UploadManager-clean {--dry-run : Show how many expired links would be removed without deleting them}';

    protected $description = 'Delete expired Uploads Manager links';

    public function handle(): int
    {
        $query = UploadLink::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        $count = (clone $query)->count();

        if ((bool) $this->option('dry-run')) {
            $this->components->info("{$count} expired Uploads Manager links would be removed.");

            return self::SUCCESS;
        }

        $query->delete();

        $this->components->info("Removed {$count} expired Uploads Manager links.");

        return self::SUCCESS;
    }
}

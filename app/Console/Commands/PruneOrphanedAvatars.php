<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneOrphanedAvatars extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'avatars:prune {--dry-run : List the orphaned files and their size without deleting anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete files in avatars/ that no user references';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $disk = Storage::disk('public');

        $referencedPaths = User::query()
            ->whereNotNull('avatar')
            ->pluck('avatar')
            ->map(fn (string $avatar) => User::storedAvatarPath($avatar))
            ->filter()
            ->flip();

        $orphanedFiles = collect($disk->allFiles('avatars'))
            ->reject(fn (string $path) => str_starts_with(basename($path), '.') || $referencedPaths->has($path))
            ->values();

        if ($orphanedFiles->isEmpty()) {
            $this->info('No orphaned avatars found.');

            return self::SUCCESS;
        }

        $sizes = $orphanedFiles->mapWithKeys(fn (string $path) => [$path => $disk->size($path)]);

        $this->table(
            ['File', 'Size'],
            $sizes->map(fn (int $bytes, string $path) => [$path, $this->formatSize($bytes)])->values()->all()
        );

        $summary = "{$orphanedFiles->count()} file(s), {$this->formatSize($sizes->sum())}";

        if ($this->option('dry-run')) {
            $this->info("Dry run: would delete {$summary}.");

            return self::SUCCESS;
        }

        $disk->delete($orphanedFiles->all());

        $this->info("Deleted {$summary}.");

        return self::SUCCESS;
    }

    private function formatSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 1).' KB';
    }
}

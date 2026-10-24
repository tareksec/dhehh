<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class BackupFiles
{
    /**
     * A backup is written to `<name>.part` and renamed when complete. A process
     * killed in the middle (a restart) leaves the `.part` behind; the longest
     * backup stops well within this many hours, so older ones are leftovers.
     */
    private const STALE_PARTIAL_HOURS = 6;

    public static function deleteStalePartials(string $directory): void
    {
        $disk = Storage::disk('local');
        $limit = now()->subHours(self::STALE_PARTIAL_HOURS)->timestamp;

        foreach ($disk->files($directory) as $path) {
            if (str_ends_with($path, '.part') && $disk->lastModified($path) < $limit) {
                $disk->delete($path);
            }
        }
    }
}

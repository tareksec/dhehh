<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One attempt to make a backup: when it started, whether it worked, the file
 * it produced and its checksum, or the reason it failed.
 *
 * Deliberately does NOT use BelongsToCompany/CompanyScope, and is excluded
 * from MultiCompanyIsolationTest's contract on purpose -- see
 * App\Models\DeploymentError for the identical precedent. A backup copies the
 * whole database for every company at once, so there is no per-company data
 * here; the Backups page that reads it is limited to `backups.manage`.
 */
class BackupRun extends Model
{
    public const SCOPE_DATABASE = 'database';

    public const SCOPE_APP = 'app';

    /** Made on a schedule every hour / every day, before a deploy, or by a person pressing the button. */
    public const KIND_HOURLY = 'hourly';

    public const KIND_DAILY = 'daily';

    public const KIND_PREDEPLOY = 'predeploy';

    public const KIND_MANUAL = 'manual';

    public const KINDS = [self::KIND_HOURLY, self::KIND_DAILY, self::KIND_PREDEPLOY, self::KIND_MANUAL];

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'scope',
        'kind',
        'status',
        'filename',
        'size_bytes',
        'sha256',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * The kind in a backup file's name (the last part before the extension).
     * A file from before kinds existed counts as manual.
     */
    public static function kindOfFile(string $filename): string
    {
        $base = preg_replace('/\.(sqlite|sql\.gz|sql|zip)$/', '', basename($filename));
        $last = substr((string) strrchr('-'.$base, '-'), 1);

        return in_array($last, self::KINDS, true) ? $last : self::KIND_MANUAL;
    }
}

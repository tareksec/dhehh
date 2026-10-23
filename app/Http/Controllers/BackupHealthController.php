<?php

namespace App\Http\Controllers;

use App\Services\BackupHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health/backups for an outside uptime monitor: 200 while backups are fresh, 503
 * when one is overdue or nothing has been recorded. It is the only check that still
 * works when the scheduler has stopped, because it is judged when asked, not by a job.
 * Off (404) until BACKUP_HEALTH_TOKEN is set; the token is the only thing it protects.
 */
class BackupHealthController extends Controller
{
    public function __invoke(Request $request, BackupHealth $health): JsonResponse
    {
        $token = (string) config('backup.health.token');
        $given = (string) ($request->bearerToken() ?: $request->query('token', ''));

        // The same answer for "off" and "wrong token", so the address does not confirm itself.
        abort_if($token === '' || ! hash_equals($token, $given), 404);

        $overall = $health->overall();

        return response()
            ->json([
                'status' => $overall,
                'checks' => collect($health->checks())->mapWithKeys(fn (array $check): array => [$check['key'] => $check['state']]),
            ], in_array($overall, [BackupHealth::OK, BackupHealth::WARNING], true) ? 200 : 503)
            ->withHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']);
    }
}

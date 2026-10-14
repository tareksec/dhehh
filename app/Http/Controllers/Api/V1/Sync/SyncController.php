<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Services\CompanyContext;
use App\Services\Sync\ImsSyncFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The read-only data feed for IMS (docs/api/ims-sync-api.md). Reachable only
 * with a "Data sync" API key, and only by GET: it can read the company's
 * business data but change nothing.
 */
class SyncController extends Controller
{
    public function __construct(protected ImsSyncFeed $feed, protected CompanyContext $context) {}

    /** Health check and the facts a client needs to read the numbers correctly. */
    public function ping(): JsonResponse
    {
        $company = $this->context->company();

        return response()->json([
            'ok' => true,
            'company' => ['id' => $company->id, 'name' => $company->name],
            // One base currency per company; amounts are in it. There is no exchange-rate table.
            'currency' => $company->currency ?: 'BDT',
            'timezone' => $company->timezone,
            'server_time' => now()->utc()->toIso8601String(),
            'resources' => $this->feed->resourceNames(),
        ]);
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        abort_unless($this->feed->has($resource), 404, 'Unknown resource.');

        $data = $request->validate([
            'updated_since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'ids_only' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->feed->page($resource, $data + ['ids_only' => $request->boolean('ids_only')]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => 'invalid_cursor', 'hint' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}

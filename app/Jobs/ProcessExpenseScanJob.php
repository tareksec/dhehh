<?php

namespace App\Jobs;

use App\Models\ExpenseScan;
use App\Services\CompanyContext;
use App\Services\ExpenseScan\ExpenseScanReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Reads one ExpenseScan's photos with the company's AI model off the web
 * request (a vision call on a full notebook page can take a minute) and
 * replaces the scan's draft lines with what the model read. Nothing is
 * published here — the scan lands in "Ready for review".
 *
 * Sets CompanyContext explicitly and restores it after (CLAUDE.md rule for queued jobs) —
 * the category/account lists handed to the model and the draft rows' own
 * CompanyScope all depend on it.
 */
class ProcessExpenseScanJob implements ShouldQueue
{
    use Queueable;

    /** A failed read is shown to the user with its reason and a Retry button, not retried blindly. */
    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $expenseScanId) {}

    public function handle(CompanyContext $context, ExpenseScanReader $reader): void
    {
        $scan = ExpenseScan::withoutGlobalScopes()->with('company')->find($this->expenseScanId);

        if (! $scan || ! $scan->company || $scan->isPublished()) {
            return;
        }

        // Restored afterwards: under QUEUE_CONNECTION=sync this runs inside the
        // uploading web request, which still needs its own company context.
        $previous = clone $context;
        $context->set($scan->company);

        try {
            $scan->forceFill(['status' => ExpenseScan::STATUS_PROCESSING, 'error_message' => null])->save();

            $result = $reader->read($scan);

            DB::transaction(function () use ($scan, $result): void {
                $scan->items()->delete();

                foreach ($result['lines'] as $index => $line) {
                    $scan->items()->create([...$line, 'company_id' => $scan->company_id, 'sort_order' => $index]);
                }

                $scan->forceFill([
                    'status' => ExpenseScan::STATUS_READY,
                    'error_message' => $result['lines'] === [] ? 'The AI found no expenses in this photo. Add lines by hand or upload a clearer photo.' : null,
                    'api_format' => $result['api_format'],
                    'model' => $result['model'],
                    'ai_response' => $result['raw'],
                    'processed_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                Log::warning('Smart Scan failed.', ['expense_scan_id' => $scan->getKey(), 'error' => $exception->getMessage()]);
            }

            $scan->forceFill([
                'status' => ExpenseScan::STATUS_FAILED,
                'error_message' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The AI model could not be reached or returned an error: '.mb_substr($exception->getMessage(), 0, 300),
                'processed_at' => now(),
            ])->save();
        } finally {
            match (true) {
                $previous->hasCompany() => $context->set($previous->company()),
                $previous->isAllCompanies() => $context->all(),
                $previous->deniesCompanyAccess() => $context->none(),
                default => $context->clear(),
            };
        }
    }
}

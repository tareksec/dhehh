<?php

namespace App\Console\Commands;

use App\Models\Quotation;
use Illuminate\Console\Command;

class MarkExpiredQuotations extends Command
{
    protected $signature = 'quotations:mark-expired';

    protected $description = 'Mark sent quotations whose valid_until date has passed as expired.';

    public function handle(): int
    {
        $count = Quotation::query()
            ->withoutGlobalScopes()
            // A quotation in Trash is left exactly as it was deleted.
            ->withoutTrashed()
            ->where('status', 'sent')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', today())
            ->update(['status' => 'expired']);

        $this->info("Marked {$count} quotation(s) as expired.");

        return self::SUCCESS;
    }
}

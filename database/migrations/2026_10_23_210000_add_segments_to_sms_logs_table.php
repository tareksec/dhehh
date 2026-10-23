<?php

use App\Services\StorefrontNotificationService;
use App\Support\SmsSegments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many SMS parts a message used (a long or Bangla message is billed as
 * several), so the SMS dashboard can estimate the cost. The text of a code
 * message is never stored; only this count is. Also removes gateway URLs (which
 * carry the API key) from log details written by an earlier version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('segments')->nullable()->after('message');
        });

        // Rows written before this column existed still hold their text, so their parts can be counted now.
        DB::table('sms_logs')->whereNotNull('message')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('sms_logs')->where('id', $row->id)->update(['segments' => SmsSegments::count((string) $row->message)]);
            }
        });

        // An unreachable gateway used to be logged with the request's message, which names the gateway URL and so
        // the API key. Take the URL out of the rows that already hold one.
        DB::table('sms_logs')->where('detail', 'like', '%http%')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('sms_logs')->where('id', $row->id)->update(['detail' => StorefrontNotificationService::scrubUrls((string) $row->detail)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table): void {
            $table->dropColumn('segments');
        });
    }
};

<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationChannel;
use App\Models\Lead;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaGraphService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RefreshMessengerProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $conversationId) {}

    public function handle(MetaGraphService $meta): void
    {
        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        if (! $conversation || $conversation->provider !== 'messenger' || $conversation->profileDisplayName() !== 'নাম পাওয়া যায়নি') {
            return;
        }
        $channel = ConversationChannel::withoutGlobalScopes()->withoutTrashed()->where('company_id', $conversation->company_id)->find($conversation->channel_id);
        if (! $channel || ! $channel->is_active) {
            return;
        }
        $cacheKey = 'crm:profile:'.$conversation->id.':'.hash('sha256', (string) $channel->access_token);
        if (! Cache::add($cacheKey, true, now()->addMinutes(5))) {
            return;
        }
        try {
            $name = $meta->messengerProfileName($channel, (string) $conversation->external_contact_id);
            if (! $name) {
                throw new MetaGraphException('Meta returned no profile name. Check Messenger profile access for this app and customer.');
            }
            if ($name) {
                // Do not overwrite a name saved by staff while the request was running.
                Conversation::withoutGlobalScopes()->whereKey($conversation->id)->where('company_id', $conversation->company_id)
                    ->where('contact_name', $conversation->contact_name)->update(['contact_name' => $name]);
                $channel->clearDiagnosticError('profile');
                if ($conversation->lead_id) {
                    Lead::withoutGlobalScopes()->withoutTrashed()->whereKey($conversation->lead_id)->where('company_id', $conversation->company_id)
                        ->where('name', 'Chat contact '.$conversation->external_contact_id)->update(['name' => $name]);
                }
            }
        } catch (\Throwable $exception) {
            // Profile permission/network failures must never interrupt message ingestion or AI replies.
            $message = $exception instanceof MetaGraphException
                ? $exception->getMessage()
                : 'Profile lookup failed on the server. Check the application log and queue worker.';
            if ($exception instanceof MetaGraphException && in_array($exception->graphCode, [10, 200], true)) {
                $message = 'Messenger profile permission denied (code '.$exception->graphCode.'). Check the Page access token, pages_messaging permission and app profile access. '.$exception->getMessage();
            }
            $channel->refresh();
            if (! $channel->last_error || $channel->last_error_source === 'profile') {
                $channel->recordDiagnosticError('Profile lookup: '.$message, 'profile');
            }
            report($exception);
        }
    }
}

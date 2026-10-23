<?php

namespace App\Http\Controllers;

use App\Models\StorefrontSetting;
use App\Services\Ses\SesEventService;
use App\Services\Ses\SesTrackingService;
use App\Services\Ses\SnsMessageValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Receives what Amazon SES reports about the emails a site sent (delivered,
 * bounced, the recipient complained), through an Amazon SNS topic that
 * SesTrackingService subscribes to this address (owner request 2026-10-23).
 * The address carries the site and a token derived from the app key; the
 * message itself must carry a valid Amazon SNS signature and name the topic that
 * was set up for the site, so neither a guessed address nor a forged message gets
 * anything written to the Email Log.
 */
class SesWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        int $setting,
        string $token,
        SnsMessageValidator $validator,
        SesEventService $events,
        SesTrackingService $tracking,
    ): JsonResponse {
        $site = StorefrontSetting::withoutGlobalScopes()->find($setting);

        if ($site === null || ! hash_equals(SesTrackingService::webhookToken($site), $token)) {
            abort(404);
        }

        $message = json_decode($request->getContent(), true);

        if (! is_array($message)) {
            return response()->json(['ok' => false, 'error' => 'invalid_body'], 400);
        }

        try {
            $validator->validate($message);
        } catch (\RuntimeException $exception) {
            Log::warning('SES webhook rejected: '.$exception->getMessage(), ['setting' => $site->getKey()]);

            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 403);
        }

        $expectedTopic = (string) data_get($site->notification_credentials, 'ses_topic_arn');

        if ($expectedTopic === '' || ! hash_equals($expectedTopic, (string) ($message['TopicArn'] ?? ''))) {
            Log::warning('SES webhook rejected: the message is not from this site\'s topic.', ['setting' => $site->getKey()]);

            return response()->json(['ok' => false, 'error' => 'unknown_topic'], 403);
        }

        $type = (string) ($message['Type'] ?? '');

        if ($type === 'SubscriptionConfirmation') {
            $url = (string) ($message['SubscribeURL'] ?? '');

            if (! SnsMessageValidator::isSnsUrl($url)) {
                return response()->json(['ok' => false, 'error' => 'invalid_subscribe_url'], 400);
            }

            if (! Http::timeout(10)->get($url)->successful()) {
                return response()->json(['ok' => false, 'error' => 'confirmation_failed'], 502);
            }

            $tracking->markConfirmed($site);

            return response()->json(['ok' => true, 'confirmed' => true]);
        }

        if ($type !== 'Notification') {
            return response()->json(['ok' => true, 'ignored' => $type]);
        }

        $event = json_decode((string) ($message['Message'] ?? ''), true);

        if (! is_array($event)) {
            return response()->json(['ok' => false, 'error' => 'invalid_event'], 400);
        }

        try {
            $touched = $events->handle($site, $event);
        } catch (\Throwable $exception) {
            report($exception);

            // A non-2xx answer makes SNS try again later.
            return response()->json(['ok' => false, 'error' => 'could_not_record'], 500);
        }

        return response()->json(['ok' => true, 'recorded' => $touched]);
    }
}

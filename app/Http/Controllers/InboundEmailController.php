<?php

namespace App\Http\Controllers;

use App\Models\EmailMailbox;
use App\Services\Email\Inbox\InboundEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives one raw email (the body is the message itself, `message/rfc822`) for a mailbox from the Cloudflare
 * Email Routing Worker (docs/email-inbox-setup.md), at webhooks/email/{mailbox}/{token}. The address carries the
 * mailbox and its secret token (only a hash of it is kept); an unknown mailbox or a wrong token is the same 404,
 * so nothing tells a guesser which part was wrong. The answer says only whether the message was kept.
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, int $mailbox, string $token, InboundEmailService $inbound): JsonResponse
    {
        $box = EmailMailbox::withoutGlobalScopes()->find($mailbox);

        if ($box === null || ! $box->tokenMatches($token)) {
            abort(404);
        }

        if (! $box->receivesMail()) {
            return response()->json(['ok' => false, 'error' => 'mailbox_not_receiving'], 409);
        }

        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > InboundEmailService::MAX_BYTES) {
            return response()->json(['ok' => false, 'error' => 'message_too_large'], 413);
        }

        $raw = (string) $request->getContent();

        if (strlen($raw) > InboundEmailService::MAX_BYTES) {
            return response()->json(['ok' => false, 'error' => 'message_too_large'], 413);
        }

        try {
            $result = $inbound->receive($box, $raw, $this->envelopeSender($request));
        } catch (\InvalidArgumentException $exception) {
            Log::warning('Inbound email refused: '.$exception->getMessage(), ['mailbox' => $box->getKey()]);

            return response()->json(['ok' => false, 'error' => 'unreadable_message'], 422);
        }

        return response()->json(['ok' => true, 'duplicate' => $result->duplicate]);
    }

    /** The envelope sender the Worker passes, used only when the message itself has no From header. */
    protected function envelopeSender(Request $request): ?string
    {
        $from = trim((string) $request->headers->get('X-Envelope-From', ''));

        return $from === '' ? null : $from;
    }
}

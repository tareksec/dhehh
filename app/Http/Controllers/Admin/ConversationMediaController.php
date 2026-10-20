<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ConversationMediaController extends Controller
{
    public function __invoke(
        Request $request,
        int $message,
        CompanyContext $context,
        CompanyStorageService $storage,
    ): Response {
        $user = $request->user();
        $conversationMessage = ConversationMessage::query()->findOrFail($message);
        $conversation = $conversationMessage->conversation()
            ->withoutGlobalScopes()
            ->with('company')
            ->firstOrFail();
        $company = $context->company();

        if (! $company && $context->isAllCompanies() && $user?->isSuperAdmin()) {
            $company = $conversation->company;
        }

        abort_unless(
            $user?->is_active
                && $user->hasPermission('crm.view')
                && $company
                && (int) $conversation->company_id === (int) $company->getKey()
                && $user?->canAccessCompany($company->getKey()),
            404,
        );

        // From here on the visitor is an authorized staff member, so a failure
        // says why (the Inbox shows it instead of a silently broken picture).
        try {
            $location = $storage->locatePrivate($conversationMessage->media_path, $company);
        } catch (InvalidArgumentException) {
            return $this->failure(__('The stored file path is not valid for this company.'));
        }

        if ($location === null) {
            return $this->failure(__('The file is not in the server storage (the message points to a file that is missing).'));
        }

        $disk = Storage::disk($location['disk']);
        $path = $location['path'];

        try {
            $size = (int) $disk->size($path);
            $contentType = $this->contentType($disk, $path, $conversationMessage->media_mime);
        } catch (Throwable $e) {
            report($e);

            return $this->failure(__('The server could not read the file: :error.', ['error' => class_basename($e)]), 500);
        }

        $range = $this->range((string) $request->header('Range'), $size);

        if ($range === false) {
            return response('', 416, ['Content-Range' => 'bytes */'.$size]);
        }

        [$start, $end] = $range ?? [0, max($size - 1, 0)];
        $length = $size === 0 ? 0 : $end - $start + 1;

        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => (string) $length,
            'Content-Disposition' => 'inline; filename="'.addcslashes(basename($path), '"\\').'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($disk, $path, $start, $length): void {
            $source = $disk->readStream($path);

            if (! is_resource($source)) {
                return;
            }

            $output = fopen('php://output', 'wb');

            try {
                if ($length === 0) {
                    return;
                }

                stream_copy_to_stream($source, $output, $length, $start);
            } finally {
                fclose($source);
                fclose($output);
            }
        }, $range === null ? 200 : 206, $headers);
    }

    protected function failure(string $reason, int $status = 404): JsonResponse
    {
        return response()->json(['message' => $reason], $status);
    }

    /**
     * WhatsApp tells us exactly what an audio/video file is, so that wins over
     * a guess from the bytes (an Ogg voice note can be sniffed as
     * application/ogg, which some browsers will not play); for anything else
     * the detected type wins unless it is only a generic fallback.
     */
    protected function contentType(Filesystem $disk, string $path, ?string $declared): string
    {
        try {
            $detected = $disk->mimeType($path);
        } catch (Throwable) {
            $detected = false;
        }

        $detected = is_string($detected) ? strtolower(trim($detected)) : '';
        $declared = strtolower(trim(explode(';', (string) $declared)[0]));
        $generic = ['', 'application/octet-stream', 'binary/octet-stream', 'text/plain'];

        if (in_array($detected, $generic, true)) {
            return $declared !== '' ? $declared : 'application/octet-stream';
        }

        $declaredMajor = explode('/', $declared)[0];

        if (in_array($declaredMajor, ['audio', 'video'], true) && explode('/', $detected)[0] !== $declaredMajor) {
            return $declared;
        }

        return $detected;
    }

    /**
     * @return array{0: int, 1: int}|false|null null = no (usable) Range header, false = not satisfiable
     */
    protected function range(string $header, int $size): array|false|null
    {
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match) || ($match[1] === '' && $match[2] === '')) {
            return null;
        }

        if ($size === 0) {
            return false;
        }

        if ($match[1] === '') {
            $length = (int) $match[2];

            return $length === 0 ? false : [max($size - $length, 0), $size - 1];
        }

        $start = (int) $match[1];
        $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);

        return $start > $end || $start >= $size ? false : [$start, $end];
    }
}

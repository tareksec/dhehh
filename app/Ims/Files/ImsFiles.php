<?php

namespace App\Ims\Files;

use App\Models\Company;
use App\Services\CompanyStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files people add to IMS (KYC pictures, project and feed photos, capital
 * return proofs; IMS-V2/TRD.md §9): on the company's private disk, the type
 * checked from the file's own first bytes (JPEG, PNG, WEBP, PDF), and
 * handed out only through permission-checked routes with
 * "Cache-Control: private, no-store".
 */
class ImsFiles
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const IMAGES = ['jpg', 'png', 'webp'];

    public const IMAGES_AND_PDF = ['jpg', 'png', 'webp', 'pdf'];

    public function __construct(private CompanyStorageService $storage) {}

    /**
     * @param  list<string>  $allowed
     * @return array{path: string, mime: string}
     */
    public function store(Company $company, string $area, UploadedFile|string $file, array $allowed = self::IMAGES, string $field = 'file'): array
    {
        $contents = $file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : (string) file_get_contents($file);

        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => __('The file is empty or larger than 8 MB.')]);
        }

        $type = static::sniff($contents);

        if ($type === null || ! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages([$field => in_array('pdf', $allowed, true)
                ? __('This file type is not allowed. Use a photo (JPG, PNG, WEBP) or a PDF.')
                : __('This file type is not allowed. Use a photo (JPG, PNG, WEBP).')]);
        }

        $path = $this->storage->putPrivate($company, 'ims/'.$area, Str::random(40).'.'.$type, $contents);

        return ['path' => $path, 'mime' => static::mime($type)];
    }

    public function read(Company $company, ?string $path): ?string
    {
        return blank($path) ? null : $this->storage->readPrivate($path, $company);
    }

    public function response(Company $company, ?string $path, string $downloadName, string $disposition = 'inline'): StreamedResponse
    {
        try {
            $location = $this->storage->locatePrivate($path, $company);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_if($location === null, 404);

        return Storage::disk($location['disk'])->response(
            $location['path'],
            $downloadName,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            $disposition,
        );
    }

    /** jpg | png | webp | pdf from the first bytes, or null. */
    public static function sniff(string $contents): ?string
    {
        return match (true) {
            str_starts_with($contents, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($contents, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($contents, '%PDF-') => 'pdf',
            default => null,
        };
    }

    public static function mime(string $type): string
    {
        return match ($type) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}

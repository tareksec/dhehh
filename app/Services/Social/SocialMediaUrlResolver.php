<?php

namespace App\Services\Social;

use App\Models\SocialPostMedia;
use App\Services\CompanyStorageService;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns an attached picture into a URL Meta can fetch (19_SOCIAL_CONTENT_PLANNER_PLAN.md §4, §8).
 *
 * Meta downloads media from a public address, so the file must be reachable from the internet. Instagram takes
 * JPEG only, so a picture in another format is converted first and the converted copy is what gets linked.
 * The URL is checked with a real request before anything is sent to Meta (plan §10).
 */
class SocialMediaUrlResolver
{
    public function __construct(private readonly CompanyStorageService $storage) {}

    /**
     * @param  bool  $jpegOnly  Instagram: convert anything that is not already a JPEG
     *
     * @throws SocialMediaException with a safe message
     */
    public function resolve(SocialPostMedia $media, bool $jpegOnly = false): string
    {
        if ($media->kind !== SocialPostMedia::KIND_IMAGE) {
            throw new SocialMediaException(__('Only pictures can be posted for now.'));
        }

        $company = $media->company;

        try {
            $bytes = $this->storage->readPublic($media->path, $company);
        } catch (Throwable) {
            $bytes = null;
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new SocialMediaException(__('The attached picture could not be found. Attach it again.'));
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new SocialMediaException(__('The attached file is not a picture Meta accepts (use JPEG, PNG or WebP).'));
        }

        $path = $media->path;

        if ($jpegOnly && $mime !== 'image/jpeg') {
            $path = $this->storeJpegCopy($media, $bytes);
        }

        $url = $this->absolute((string) $this->storage->publicUrl($path, $company));

        $this->assertReachable($url);

        return $url;
    }

    /** @throws SocialMediaException */
    protected function storeJpegCopy(SocialPostMedia $media, string $bytes): string
    {
        try {
            $jpeg = (string) (new ImageManager(new Driver))->read($bytes)->toJpeg(90);
        } catch (Throwable) {
            throw new SocialMediaException(__('The picture could not be converted to JPEG for Instagram.'));
        }

        // Named after the content, so converting the same picture twice reuses one file.
        return $this->storage->putPublic($media->company, 'social-posts', hash('sha256', $jpeg).'.jpg', $jpeg);
    }

    /** @throws SocialMediaException */
    protected function absolute(string $url): string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $base = rtrim((string) config('app.url'), '/');
            $url = $base === '' ? '' : $base.$url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = trim(strtolower((string) parse_url($url, PHP_URL_HOST)), '[]');

        $private = $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);

        if ($private || ! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new SocialMediaException(__('The picture has no public web address, so Meta cannot fetch it. Check the site address (APP_URL) and the storage settings.'));
        }

        return $url;
    }

    /** @throws SocialMediaException */
    protected function assertReachable(string $url): void
    {
        try {
            $response = Http::timeout(10)->withHeaders(['Range' => 'bytes=0-1023'])->get($url);
        } catch (Throwable) {
            throw new SocialMediaException(__('The picture address did not answer, so Meta could not fetch it.'));
        }

        $type = strtolower((string) $response->header('Content-Type'));

        if (! in_array($response->status(), [200, 206], true) || ! str_starts_with($type, 'image/')) {
            throw new SocialMediaException(__('The picture address is not reachable from the internet, so Meta cannot fetch it.'));
        }
    }
}

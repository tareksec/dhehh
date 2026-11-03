<?php

namespace App\Services\Email\Inbox;

use App\Models\Company;
use App\Models\EmailAttachment;
use App\Services\CompanyStorageService;
use Illuminate\Support\Facades\Storage;

/** The files of the Email inbox on the company's private storage: read one, delete one or all the files of some messages. A missing file is never an error. */
class EmailFiles
{
    public function __construct(protected CompanyStorageService $storage) {}

    public function read(EmailAttachment $file, Company $company): ?string
    {
        return $this->storage->readPrivate($file->path, $company);
    }

    public function delete(?string $path, Company $company): void
    {
        try {
            $location = $this->storage->locatePrivate($path, $company);

            if ($location !== null) {
                Storage::disk($location['disk'])->delete($location['path']);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** @param  iterable<EmailAttachment>  $files */
    public function deleteAll(iterable $files, Company $company): void
    {
        foreach ($files as $file) {
            $this->delete($file->path, $company);
        }
    }
}

<?php

namespace App\Services\Investment;

use App\Models\Company;
use App\Models\InvestmentDocument;
use App\Models\Investor;
use App\Services\CompanyStorageService;
use App\Support\InvestorIdentity;
use Illuminate\Support\Str;

/**
 * Files many investors' paper copies at once. Each file is named with the
 * investor's NID / passport number plus a word saying what it is, e.g.
 * "1479939603_nid.jpg", "1479939603_nominee.jpg", "1479939603 contract.pdf".
 * The number picks the investor (owner's rule: NID is the investor ID); the
 * word picks the category (config('investor_import.document_keywords')),
 * defaulting to the investor's own NID copy.
 */
class InvestorDocumentUploadService
{
    public function __construct(private CompanyStorageService $storage) {}

    /**
     * @param  list<array{path: string, name: string}>  $files  local temp files with their original names
     * @return array{attached: int, unmatched: list<string>}
     */
    public function attach(array $files, Company $company): array
    {
        $attached = 0;
        $unmatched = [];

        foreach ($files as $file) {
            $investor = $this->investorFor($file['name'], $company);

            if (! $investor) {
                $unmatched[] = $file['name'];

                continue;
            }

            $extension = Str::lower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $category = $this->categoryFor($file['name']);
            $path = $this->storage->putPrivate(
                $company,
                'investment-documents',
                Str::uuid().($extension !== '' ? '.'.$extension : ''),
                (string) file_get_contents($file['path']),
            );

            InvestmentDocument::query()->create([
                'company_id' => $company->getKey(),
                'documentable_type' => $investor->getMorphClass(),
                'documentable_id' => $investor->getKey(),
                'file_path' => $path,
                'file_type' => $extension ?: null,
                'category' => $category,
                'label' => Str::limit($file['name'], 120, ''),
            ]);

            $attached++;
        }

        return ['attached' => $attached, 'unmatched' => $unmatched];
    }

    /** The investor whose NID / passport number appears in the file name. */
    public function investorFor(string $fileName, Company $company): ?Investor
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $bangla = array_map(fn (int $digit): string => mb_chr(0x09E6 + $digit, 'UTF-8'), range(0, 9));
        $base = str_replace($bangla, array_map('strval', range(0, 9)), $base);

        // Candidate numbers: runs of letters/digits that contain at least 6 digits.
        preg_match_all('/[A-Za-z0-9]+/', $base, $matches);

        foreach ($matches[0] as $token) {
            if (preg_match_all('/\d/', $token) < 6) {
                continue;
            }

            $investor = Investor::query()
                ->where('company_id', $company->getKey())
                ->where('nid_hash', InvestorIdentity::hash($token))
                ->first();

            if ($investor) {
                return $investor;
            }
        }

        return null;
    }

    public function categoryFor(string $fileName): string
    {
        $name = mb_strtolower(pathinfo($fileName, PATHINFO_FILENAME));

        foreach (config('investor_import.document_keywords', []) as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($name, mb_strtolower($word))) {
                    return $category;
                }
            }
        }

        return InvestmentDocument::CATEGORY_INVESTOR_NID;
    }
}

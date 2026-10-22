<?php

namespace App\Ims\Kyc;

use App\Ims\Files\ImsFiles;
use App\Ims\Notifications\ImsNotifier;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorKycDocument;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\InvestorIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * KYC (IMS-V2/PRD.md FR-10–14, TRD §7): the NID card's two sides and a
 * selfie, read on our own server, checked against a list, and approved or
 * turned down by staff with `ims.kyc.review`.
 *
 * The checklist: NID format (10/13/17 digits), age 18 or more, all three
 * pictures, the NID not on another investor, and what OCR read. When the
 * investor sends it and the card can be read, the NID and date of birth on
 * the card must match theirs; staff sending it are never stopped, and
 * neither is anyone when OCR is not installed ("OCR could not read").
 */
class KycService
{
    public const OCR_MATCHED = 'matched';

    public const OCR_MISMATCH = 'mismatch';

    public const OCR_UNREADABLE = 'unreadable';

    public const OCR_NOT_AVAILABLE = 'not_available';

    public function __construct(
        private ImsFiles $files,
        private NidOcr $ocr,
        private AuditLogService $audit,
        private ImsNotifier $notifier,
    ) {}

    /**
     * @param  array<string, UploadedFile|string>  $pictures  type => file (nid_front, nid_back, selfie)
     * @param  array{nid_number?: string|null, date_of_birth?: string|null}  $details  filled only where the investor has none yet
     */
    public function submit(Investor $investor, array $pictures, string $byType, ?int $byId = null, array $details = []): Investor
    {
        if ($investor->isKycVerified()) {
            throw ValidationException::withMessages(['kyc' => __('This KYC is already verified.')]);
        }

        $limiter = 'ims-kyc:'.$byType.':'.($byId ?? $investor->getKey());

        if (RateLimiter::tooManyAttempts($limiter, $byType === 'staff' ? 100 : 20)) {
            throw ValidationException::withMessages(['kyc' => __('Too many tries. Please wait an hour and send again.')]);
        }

        RateLimiter::hit($limiter, 3600);

        $company = Company::query()->findOrFail($investor->company_id);
        $isInvestor = $byType === 'investor';

        foreach (['nid_number', 'date_of_birth'] as $field) {
            if (blank($investor->{$field}) && filled($details[$field] ?? null)) {
                $investor->{$field} = $details[$field];
            }
        }

        $stored = [];

        foreach (InvestorKycDocument::REQUIRED as $type) {
            if (isset($pictures[$type])) {
                $stored[$type] = $this->files->store($company, 'kyc', $pictures[$type], ImsFiles::IMAGES, $type);
            }
        }

        $have = array_unique([...array_keys($stored), ...$investor->kycDocuments()->pluck('type')->all()]);

        if (array_diff(InvestorKycDocument::REQUIRED, $have) !== []) {
            throw ValidationException::withMessages(['kyc' => __('Send all three pictures: the front of the NID card, its back, and a selfie.')]);
        }

        // OCR: the two sides of the card.
        $read = ['readable' => false, 'nid' => null, 'date_of_birth' => null, 'name' => null];
        $ocrState = self::OCR_NOT_AVAILABLE;

        if ($this->ocr->available()) {
            foreach (['nid_front', 'nid_back'] as $side) {
                if (! isset($stored[$side])) {
                    continue;
                }

                $result = $this->ocr->read((string) $this->files->read($company, $stored[$side]['path']));
                $read['nid'] ??= $result['nid'];
                $read['date_of_birth'] ??= $result['date_of_birth'];
                $read['name'] ??= $result['name'];
                $read['readable'] = $read['readable'] || $result['readable'];
            }

            $ocrState = ! $read['readable'] ? self::OCR_UNREADABLE : ($this->ocrMatches($investor, $read) ? self::OCR_MATCHED : self::OCR_MISMATCH);

            if ($isInvestor && $ocrState === self::OCR_UNREADABLE) {
                throw ValidationException::withMessages(['nid_front' => __('The card could not be read. Take the picture again in good light, flat and without glare.')]);
            }

            if ($isInvestor && $ocrState === self::OCR_MISMATCH) {
                throw ValidationException::withMessages(['nid_front' => __('The NID number or date of birth on the card is not the same as in your profile. Check them and send again.')]);
            }
        }

        return DB::transaction(function () use ($investor, $stored, $byType, $byId, $read, $ocrState): Investor {
            foreach ($stored as $type => $file) {
                InvestorKycDocument::query()->create([
                    'company_id' => $investor->company_id,
                    'investor_id' => $investor->getKey(),
                    'type' => $type,
                    'path' => $file['path'],
                    'mime' => $file['mime'],
                    'ocr_result' => in_array($type, ['nid_front', 'nid_back'], true) ? $read : null,
                    'uploaded_by_type' => $byType,
                    'uploaded_by_id' => $byId,
                ]);
            }

            $investor->forceFill([
                'kyc_status' => Investor::KYC_PENDING,
                'kyc_submitted_at' => now(),
                'kyc_note' => null,
            ])->save();

            $investor->forceFill(['kyc_check' => $this->checklist($investor->refresh(), $ocrState)])->save();

            // Only pass / fail goes in the log, never the number or name read.
            $this->audit->record('ims.kyc_submitted', $investor, null, ['by' => $byType, 'ocr' => $ocrState, 'passed' => $this->passes($investor->kyc_check)]);

            return $investor;
        });
    }

    /**
     * @return array<string, array{ok: bool|null, label: string}>
     */
    public function checklist(Investor $investor, ?string $ocrState = null): array
    {
        $nid = InvestorIdentity::normalize($investor->nid_number);
        $digits = $nid !== null ? preg_replace('/\D/', '', $nid) : '';
        $dob = $investor->date_of_birth ? CarbonImmutable::parse($investor->date_of_birth) : null;
        $types = $investor->kycDocuments()->pluck('type')->all();
        $duplicate = $investor->nid_hash !== null && Investor::query()
            ->where('company_id', $investor->company_id)->where('nid_hash', $investor->nid_hash)->whereKeyNot($investor->getKey())->exists();
        $ocrState ??= $investor->kyc_check['ocr']['state'] ?? self::OCR_NOT_AVAILABLE;

        return [
            'nid_format' => ['ok' => in_array(strlen((string) $digits), [10, 13, 17], true), 'label' => 'NID number has 10, 13 or 17 digits'],
            'age' => ['ok' => $dob !== null && $dob->addYears(18)->lte(CarbonImmutable::today()), 'label' => 'Age is 18 or more'],
            'pictures' => ['ok' => array_diff(InvestorKycDocument::REQUIRED, $types) === [], 'label' => 'NID front, NID back and selfie are all there'],
            'unique' => ['ok' => ! $duplicate, 'label' => 'NID number is not on another investor'],
            'ocr' => [
                'ok' => match ($ocrState) {
                    self::OCR_MATCHED => true,
                    self::OCR_MISMATCH => false,
                    default => null,
                },
                'state' => $ocrState,
                'label' => match ($ocrState) {
                    self::OCR_MATCHED => 'The card matches the NID number and date of birth',
                    self::OCR_MISMATCH => 'The card does not match the NID number or date of birth',
                    self::OCR_UNREADABLE => 'OCR could not read the card — check it by eye',
                    default => 'OCR is not set up on the server — check the card by eye',
                },
            ],
        ];
    }

    /** All the must-pass checks pass (OCR is a help, not a must). @param  array<string, mixed>|null  $check */
    public function passes(?array $check): bool
    {
        foreach (['nid_format', 'age', 'pictures', 'unique'] as $key) {
            if (($check[$key]['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    public function approve(Investor $investor, User $by): Investor
    {
        $this->authorize($by);

        if ($investor->kyc_status !== Investor::KYC_PENDING) {
            throw ValidationException::withMessages(['kyc' => __('Only a KYC waiting for review can be approved.')]);
        }

        $check = $this->checklist($investor);

        if (! $this->passes($check)) {
            throw ValidationException::withMessages(['kyc' => __('A must-pass check is not green. Fix the NID number, date of birth or pictures first, or turn the KYC down with a reason.')]);
        }

        $investor->forceFill([
            'kyc_status' => Investor::KYC_VERIFIED,
            'kyc_reviewed_at' => now(),
            'kyc_reviewed_by' => $by->getKey(),
            'kyc_note' => null,
            'kyc_check' => $check,
        ])->save();

        $this->audit->record('ims.kyc_approved', $investor, ['kyc_status' => Investor::KYC_PENDING], ['kyc_status' => Investor::KYC_VERIFIED, 'by' => $by->getKey()]);
        $this->notifier->send('kyc_decided', $investor, ['title' => __('Your KYC is verified. You can now invest.')]);

        return $investor;
    }

    public function reject(Investor $investor, User $by, string $reason): Investor
    {
        $this->authorize($by);

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => __('Write a reason of at least 5 letters. The investor will see it.')]);
        }

        if ($investor->kyc_status !== Investor::KYC_PENDING) {
            throw ValidationException::withMessages(['kyc' => __('Only a KYC waiting for review can be turned down.')]);
        }

        $investor->forceFill([
            'kyc_status' => Investor::KYC_REJECTED,
            'kyc_reviewed_at' => now(),
            'kyc_reviewed_by' => $by->getKey(),
            'kyc_note' => trim($reason),
        ])->save();

        $this->audit->record('ims.kyc_rejected', $investor, ['kyc_status' => Investor::KYC_PENDING], ['kyc_status' => Investor::KYC_REJECTED, 'by' => $by->getKey()]);
        $this->notifier->send('kyc_decided', $investor, ['title' => __('Your KYC was not accepted: :reason Please send it again.', ['reason' => trim($reason)])]);

        return $investor;
    }

    /** Whether new money from this investor may become active (PRD FR-14). Only while IMS is live. */
    public static function blocksNewMoney(Investor $investor): bool
    {
        $company = Company::query()->find($investor->company_id);

        return $company?->imsIsLive() === true && ! $investor->isKycVerified();
    }

    /** @param  array{nid: string|null, date_of_birth: string|null}  $read */
    private function ocrMatches(Investor $investor, array $read): bool
    {
        $nid = preg_replace('/\D/', '', (string) InvestorIdentity::normalize($investor->nid_number));
        $dob = $investor->date_of_birth ? CarbonImmutable::parse($investor->date_of_birth)->toDateString() : null;

        $nidOk = $read['nid'] === null || $nid === '' || $read['nid'] === $nid || str_ends_with($read['nid'], $nid) || str_ends_with($nid, $read['nid']);
        $dobOk = $read['date_of_birth'] === null || $dob === null || $read['date_of_birth'] === $dob;

        return $nidOk && $dobOk && ($read['nid'] !== null || $read['date_of_birth'] !== null);
    }

    private function authorize(User $by): void
    {
        if (! $by->hasPermission('ims.kyc.review')) {
            throw ValidationException::withMessages(['kyc' => __('You do not have permission to review KYC.')]);
        }
    }
}

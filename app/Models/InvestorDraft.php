<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An investor read from an uploaded Excel/CSV file or pasted text, waiting
 * for review. Confirming it (InvestorImportService::confirm) creates the
 * real Investor and deletes the draft.
 */
class InvestorDraft extends Model
{
    use BelongsToCompany;

    public const SOURCE_EXCEL = 'excel';

    public const SOURCE_CSV = 'csv';

    public const SOURCE_TEXT = 'text';

    /** Columns copied onto the Investor when the draft is confirmed. */
    public const INVESTOR_FIELDS = ['name', 'display_name', 'guardian_name', 'date_of_birth', 'phone', 'email', 'address', 'nid_number', 'stamp_number', 'cheque_number', 'channel_partner_id', 'is_channel_partner', 'nominee_name', 'nominee_nid_or_passport', 'nominee_phone', 'nominee_relation', 'nominee_address'];

    protected $fillable = ['company_id', 'batch_id', 'source', 'source_name', 'source_row', 'name', 'display_name', 'guardian_name', 'date_of_birth', 'phone', 'email', 'address', 'nid_number', 'stamp_number', 'cheque_number', 'channel_partner_id', 'is_channel_partner', 'nominee_name', 'nominee_nid_or_passport', 'nominee_phone', 'nominee_relation', 'nominee_address', 'existing_investor_id', 'project_id', 'investment_amount', 'payment_method', 'invested_at', 'import_notes', 'created_by'];

    protected $casts = ['date_of_birth' => 'date', 'is_channel_partner' => 'boolean', 'nid_number' => 'encrypted', 'nominee_nid_or_passport' => 'encrypted', 'import_notes' => 'array', 'investment_amount' => 'decimal:2', 'invested_at' => 'date'];

    /** Project statuses that still accept new investments. */
    public const OPEN_PROJECT_STATUSES = ['open', 'running'];

    public function channelPartner()
    {
        return $this->belongsTo(Investor::class, 'channel_partner_id');
    }

    /** The investor this draft adds a new investment to, instead of creating a new investor. */
    public function existingInvestor()
    {
        return $this->belongsTo(Investor::class, 'existing_investor_id');
    }

    public function project()
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    /** Whether confirming this draft also records an investment. */
    public function willCreateInvestment(): bool
    {
        return filled($this->project_id) && (float) $this->investment_amount > 0;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Problems that block confirming this draft right now, as translated
     * messages. Checked live (not stored) so they clear once fixed.
     *
     * @return list<string>
     */
    public function blockingIssues(): array
    {
        $issues = [];

        if ($this->existing_investor_id) {
            $existing = Investor::query()->where('company_id', $this->company_id)->find($this->existing_investor_id);

            if (! $existing) {
                $issues[] = __('The linked existing investor was not found.');
            } elseif (! $this->willCreateInvestment()) {
                $issues[] = __(':name is already an investor. Pick a project and amount to add a new investment for them, or discard this draft.', ['name' => $existing->name]);
            }
        } else {
            $issues = [...$issues, ...$this->newInvestorIssues()];
        }

        return [...$issues, ...$this->investmentIssues()];
    }

    /** @return list<string> */
    private function newInvestorIssues(): array
    {
        $issues = [];

        if (blank($this->name)) {
            $issues[] = __('Name is missing.');
        }

        if (blank($this->phone)) {
            $issues[] = __('Phone is missing.');
        } else {
            $existing = Investor::query()
                ->where('company_id', $this->company_id)
                ->where('phone', $this->phone)
                ->first();

            if ($existing) {
                $issues[] = __('Phone :phone already belongs to investor :name. Link this draft to that investor to add a new investment for them.', ['phone' => $this->phone, 'name' => $existing->name]);
            }

            $twin = self::query()
                ->where('company_id', $this->company_id)
                ->where('phone', $this->phone)
                ->whereNull('existing_investor_id')
                ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
                ->exists();

            if ($twin) {
                $issues[] = __('Another draft has the same phone :phone. Keep only one of them.', ['phone' => $this->phone]);
            }
        }

        if (filled($this->email) && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $issues[] = __('Email :email is not valid.', ['email' => $this->email]);
        }

        return $issues;
    }

    /** @return list<string> */
    private function investmentIssues(): array
    {
        $hasAmount = (float) $this->investment_amount > 0;

        if (! $hasAmount && blank($this->project_id)) {
            return [];
        }

        $issues = [];

        if (! $hasAmount) {
            $issues[] = __('Enter the investment amount.');
        }

        if (blank($this->project_id)) {
            $issues[] = __('Pick a project for the investment amount, or clear the amount.');

            return $issues;
        }

        $project = InvestmentProject::query()->where('company_id', $this->company_id)->find($this->project_id);

        if (! $project) {
            $issues[] = __('The chosen project was not found.');
        } elseif (! in_array($project->status, self::OPEN_PROJECT_STATUSES, true)) {
            $issues[] = __('Project :project is :status and no longer takes investments.', ['project' => $project->project_code ?: $project->name, 'status' => __(InvestmentProject::STATUSES[$project->status] ?? $project->status)]);
        }

        if (blank($this->payment_method)) {
            $issues[] = __('Pick the payment method.');
        }

        if (blank($this->invested_at)) {
            $issues[] = __('Enter the investment date.');
        }

        return $issues;
    }

    /**
     * Notes written while reading the file/text (a date that could not be
     * read, an unknown channel partner…), translated for display.
     *
     * @return list<string>
     */
    public function importNoteMessages(): array
    {
        return collect($this->import_notes ?? [])
            ->map(fn ($note): string => is_array($note)
                ? __((string) ($note['message'] ?? ''), (array) ($note['params'] ?? []))
                : (string) $note)
            ->filter()
            ->values()
            ->all();
    }

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            self::SOURCE_EXCEL => __('Excel'),
            self::SOURCE_CSV => __('CSV'),
            self::SOURCE_TEXT => __('Pasted text'),
            default => (string) $source,
        };
    }
}

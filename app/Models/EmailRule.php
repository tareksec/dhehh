<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An automatic action on mail that arrives in a mailbox (Settings → Email inbox → Rules): when the sender and / or the
 * subject contains some text, put a label on the conversation, give it to a person, archive it. Company-owned.
 */
class EmailRule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'mailbox_id', 'name', 'is_active', 'position', 'from_contains', 'subject_contains', 'label_id', 'assign_to', 'archive',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'archive' => 'boolean', 'position' => 'integer'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(EmailMailbox::class, 'mailbox_id');
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(EmailLabel::class, 'label_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    /** Whether the sender and subject of an arriving message fit this rule (case does not matter; a rule with no condition fits nothing). */
    public function matches(string $fromAddress, ?string $fromName, string $subject): bool
    {
        $from = mb_strtolower(trim((string) $this->from_contains));
        $about = mb_strtolower(trim((string) $this->subject_contains));

        if ($from === '' && $about === '') {
            return false;
        }

        if ($from !== '' && ! str_contains(mb_strtolower($fromAddress.' '.(string) $fromName), $from)) {
            return false;
        }

        return $about === '' || str_contains(mb_strtolower($subject), $about);
    }
}

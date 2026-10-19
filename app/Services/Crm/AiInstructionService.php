<?php

namespace App\Services\Crm;

use App\Models\AiInstructionVersion;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Versions of the owner's guidance for the sales agent
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.5).
 *
 * The live text stays in the company's AI settings (`about`, `voice` and
 * `guidelines` are stored as business_about, brand_voice and
 * sales_guidelines), so the agent's prompt does not change. Publishing writes
 * the settings and records a version; saving the same fields on Integrations
 * records a version too, so the history is complete and any version can be
 * made live again.
 */
class AiInstructionService
{
    public function __construct(protected AiSettingsService $settings) {}

    /** The text the agent uses right now, read from the settings. */
    public function live(Company $company): array
    {
        return $this->settings->instructionBlocks($company);
    }

    /** The live version row; the first time, the current settings become version one. */
    public function published(Company $company): AiInstructionVersion
    {
        $published = AiInstructionVersion::query()->where('status', AiInstructionVersion::STATUS_PUBLISHED)->latest('id')->first();

        return $published ?? $this->record($company, $this->live($company), 'settings', null, null, null);
    }

    public function draft(Company $company): ?AiInstructionVersion
    {
        return AiInstructionVersion::query()->where('status', AiInstructionVersion::STATUS_DRAFT)->latest('id')->first();
    }

    /** Blocks the agent would use when trying the draft: the draft, else the live text. */
    public function draftOrLive(Company $company): array
    {
        return $this->draft($company)?->normalizedBlocks() ?? $this->live($company);
    }

    public function saveDraft(Company $company, array $blocks, ?User $user = null): AiInstructionVersion
    {
        $blocks = AiInstructionVersion::normalize($blocks);
        $draft = $this->draft($company);
        if ($draft) {
            $draft->forceFill(['blocks' => $blocks])->save();

            return $draft;
        }

        return AiInstructionVersion::query()->create([
            'company_id' => $company->getKey(),
            'blocks' => $blocks,
            'status' => AiInstructionVersion::STATUS_DRAFT,
            'created_by' => $user?->getKey(),
        ]);
    }

    public function discardDraft(Company $company): void
    {
        AiInstructionVersion::query()->where('status', AiInstructionVersion::STATUS_DRAFT)->delete();
    }

    /** Make the draft live. */
    public function publishDraft(Company $company, ?User $user, ?string $note): AiInstructionVersion
    {
        $draft = $this->draft($company);
        abort_if($draft === null, 422, 'There is no draft to publish.');

        return DB::transaction(function () use ($company, $draft, $user, $note): AiInstructionVersion {
            $this->published($company); // keep what is live now as the previous version
            $this->settings->setInstructions($company, $draft->normalizedBlocks());
            $this->supersedeCurrent();
            $draft->forceFill([
                'status' => AiInstructionVersion::STATUS_PUBLISHED,
                'source' => 'agent_desk',
                'change_note' => $this->note($note),
                'published_at' => now(),
                'published_by' => $user?->getKey(),
            ])->save();

            return $draft;
        });
    }

    /** Make an older version live again (a new version that copies it). */
    public function restore(Company $company, AiInstructionVersion $version, ?User $user): AiInstructionVersion
    {
        abort_if($version->status === AiInstructionVersion::STATUS_DRAFT, 422, 'A draft cannot be made live this way.');

        return DB::transaction(function () use ($company, $version, $user): AiInstructionVersion {
            $this->published($company);
            $blocks = $version->normalizedBlocks();
            $this->settings->setInstructions($company, $blocks);
            $this->supersedeCurrent();

            return $this->record($company, $blocks, 'restore', $user, __('Version :number made live again', ['number' => $version->getKey()]), $user);
        });
    }

    /**
     * Called when the same fields are saved on Integrations: keep what was
     * live as a version, then record the new text as the live version.
     */
    public function recordSettingsChange(Company $company, array $before, array $after, ?User $user = null): void
    {
        $before = AiInstructionVersion::normalize($before);
        $after = AiInstructionVersion::normalize($after);
        if ($before === $after) {
            return;
        }

        DB::transaction(function () use ($company, $before, $after, $user): void {
            $published = AiInstructionVersion::query()->where('status', AiInstructionVersion::STATUS_PUBLISHED)->latest('id')->first();
            if (! $published) {
                $this->record($company, $before, 'settings', null, null, null);
            }
            $this->supersedeCurrent();
            $this->record($company, $after, 'settings', $user, null, $user);
        });
    }

    protected function supersedeCurrent(): void
    {
        AiInstructionVersion::query()->where('status', AiInstructionVersion::STATUS_PUBLISHED)->update(['status' => AiInstructionVersion::STATUS_SUPERSEDED]);
    }

    protected function record(Company $company, array $blocks, string $source, ?User $user, ?string $note, ?User $creator): AiInstructionVersion
    {
        return AiInstructionVersion::query()->create([
            'company_id' => $company->getKey(),
            'blocks' => AiInstructionVersion::normalize($blocks),
            'status' => AiInstructionVersion::STATUS_PUBLISHED,
            'source' => $source,
            'change_note' => $this->note($note),
            'published_at' => now(),
            'published_by' => $user?->getKey(),
            'created_by' => $creator?->getKey(),
        ]);
    }

    protected function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : Str::limit($note, 250, '');
    }
}

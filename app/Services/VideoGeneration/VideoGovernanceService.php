<?php

namespace App\Services\VideoGeneration;

use App\Models\Company;
use App\Models\GeneratedVideo;
use App\Models\User;
use App\Services\AuditLogService;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §7.4 / Phase 5 — the Video
 * Generation governance layer, stored at
 * `companies.settings->ai_tools->video_governance`:
 *
 *   {
 *     "monthly_caps":  { "sales_staff": 10, "manager": 0, ... },  // 0 = unlimited
 *     "review_roles":  ["sales_staff"]
 *   }
 *
 * Mirrors ImageGovernanceService on purpose but shares no code with it — each
 * metered tool owns its governance (plan §2). The cap unit is **videos per
 * calendar month** (a row count; open question §14(d) resolved this way,
 * since Stability returns one fixed-length clip and every request is exactly
 * one video). The storage shape is unit-agnostic, so a later switch to
 * seconds only changes videosUsedThisMonth().
 *
 * All mechanism, no policy: caps default to 0 (unlimited) and the review list
 * to empty, so nothing changes until a super admin sets real values on the
 * Video Governance page.
 */
class VideoGovernanceService
{
    public const SETTINGS_PATH = 'ai_tools.video_governance';

    /**
     * Roles a cap / review rule can target. `super_admin` is deliberately
     * omitted — it always bypasses both.
     *
     * @return array<string, string>
     */
    public static function governableRoles(): array
    {
        return collect(User::ROLES)
            ->reject(fn (string $label, string $key): bool => $key === 'super_admin')
            ->all();
    }

    /**
     * @return array{monthly_caps: array<string, int>, review_roles: array<int, string>}
     */
    public function all(Company $company): array
    {
        $stored = (array) data_get($company->settings, self::SETTINGS_PATH, []);
        $roles = array_keys(self::governableRoles());

        $caps = [];
        foreach ($roles as $role) {
            $caps[$role] = max(0, (int) data_get($stored, "monthly_caps.{$role}", 0));
        }

        $reviewRoles = collect((array) ($stored['review_roles'] ?? []))
            ->filter(fn ($role): bool => in_array($role, $roles, true))
            ->values()
            ->all();

        return ['monthly_caps' => $caps, 'review_roles' => $reviewRoles];
    }

    /**
     * @param  array{monthly_caps?: array<string, mixed>, review_roles?: array<int, string>}  $data
     */
    public function save(Company $company, array $data): void
    {
        $roles = array_keys(self::governableRoles());

        $caps = [];
        foreach ($roles as $role) {
            $value = max(0, (int) data_get($data, "monthly_caps.{$role}", 0));
            if ($value > 0) {
                $caps[$role] = $value;
            }
        }

        $reviewRoles = collect((array) ($data['review_roles'] ?? []))
            ->filter(fn ($role): bool => in_array($role, $roles, true))
            ->unique()
            ->values()
            ->all();

        $settings = (array) $company->settings;

        if ($caps === [] && $reviewRoles === []) {
            data_forget($settings, self::SETTINGS_PATH);
        } else {
            data_set($settings, self::SETTINGS_PATH, [
                'monthly_caps' => $caps,
                'review_roles' => $reviewRoles,
            ]);
        }

        $company->forceFill(['settings' => $settings])->save();
    }

    /** Monthly video cap for a role. 0 means unlimited. Super admin is always unlimited. */
    public function monthlyCapForRole(Company $company, ?string $role): int
    {
        if ($role === null || $role === 'super_admin') {
            return 0;
        }

        return (int) ($this->all($company)['monthly_caps'][$role] ?? 0);
    }

    public function roleRequiresReview(Company $company, ?string $role): bool
    {
        if ($role === null || $role === 'super_admin') {
            return false;
        }

        return in_array($role, $this->all($company)['review_roles'], true);
    }

    /**
     * Videos a user has requested this calendar month in this company —
     * failed generations do not count against the cap.
     */
    public function videosUsedThisMonth(Company $company, User $user): int
    {
        return GeneratedVideo::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('tool', GeneratedVideo::TOOL_VIDEO_GENERATION)
            ->where('status', '!=', GeneratedVideo::STATUS_FAILED)
            ->thisMonth()
            ->count();
    }

    /** Videos the user may still generate this month, or null when unlimited. */
    public function remainingThisMonth(Company $company, User $user): ?int
    {
        $cap = $this->monthlyCapForRole($company, $user->effectiveRole());

        if ($cap === 0) {
            return null;
        }

        return max(0, $cap - $this->videosUsedThisMonth($company, $user));
    }

    public function wouldExceedCap(Company $company, User $user, int $requested = 1): bool
    {
        $remaining = $this->remainingThisMonth($company, $user);

        return $remaining !== null && $requested > $remaining;
    }

    /**
     * A reviewer clears a pending video for attaching, or blocks it. No-op on
     * a video that was never pending. Writes an audit-trail entry either way.
     */
    public function review(GeneratedVideo $video, User $reviewer, bool $approved, ?string $note = null): void
    {
        if ($video->review_status !== GeneratedVideo::REVIEW_PENDING) {
            return;
        }

        $note = trim((string) $note) ?: null;

        $video->forceFill([
            'review_status' => $approved ? GeneratedVideo::REVIEW_APPROVED : GeneratedVideo::REVIEW_REJECTED,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        app(AuditLogService::class)->record(
            $approved ? 'ai_video.review_approved' : 'ai_video.review_rejected',
            $video,
            null,
            array_filter([
                'reviewer' => $reviewer->name,
                'note' => $note,
            ]),
        );
    }

    /**
     * This-month usage totals for the governance dashboard (failed rows
     * excluded, the same rule as the cap).
     *
     * @return array{
     *     videos: int, cost: float,
     *     by_user: array<int, array{name: string, videos: int, cost: float}>,
     *     by_provider: array<int, array{label: string, videos: int, cost: float}>
     * }
     */
    public function usageSummary(Company $company): array
    {
        $rows = GeneratedVideo::query()
            ->where('company_id', $company->getKey())
            ->where('tool', GeneratedVideo::TOOL_VIDEO_GENERATION)
            ->where('status', '!=', GeneratedVideo::STATUS_FAILED)
            ->thisMonth()
            ->with('user:id,name')
            ->get(['id', 'user_id', 'provider_label', 'api_format', 'estimated_cost']);

        $byUser = $rows
            ->groupBy('user_id')
            ->map(fn ($group) => [
                'name' => $group->first()->user?->name ?? __('Unknown'),
                'videos' => $group->count(),
                'cost' => round((float) $group->sum('estimated_cost'), 2),
            ])
            ->sortByDesc('cost')
            ->values()
            ->all();

        $byProvider = $rows
            ->groupBy(fn (GeneratedVideo $row): string => $row->provider_label ?: ($row->api_format ?: __('Unknown')))
            ->map(fn ($group, string $label) => [
                'label' => $label,
                'videos' => $group->count(),
                'cost' => round((float) $group->sum('estimated_cost'), 2),
            ])
            ->sortByDesc('cost')
            ->values()
            ->all();

        return [
            'videos' => $rows->count(),
            'cost' => round((float) $rows->sum('estimated_cost'), 2),
            'by_user' => $byUser,
            'by_provider' => $byProvider,
        ];
    }
}

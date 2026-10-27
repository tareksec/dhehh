# 19 — Social Content Planner (Module Plan)

Status: **Plan only, nothing built yet.** Owner decisions confirmed 2026-10-27 (§2). Written after reviewing the open-source repo [HarunRRayhan/content-machine](https://github.com/HarunRRayhan/content-machine) (MIT, Laravel 13 + Inertia/React, a personal content pipeline that publishes through PostSyncer) at the owner's request ("এটা ইমপ্লিমেন্ট করা যায় কিনা?"). Intended as the pre-execution reference for the build, the same way documents 11, 12 and 14 were used.

The ERP has no tool for **organic** social posts: Meta Ads (doc 14 and the Ads cluster) only buys ads, and the Inbox only answers messages. This module adds: write a post, get it approved, schedule it or publish it now to Facebook, Instagram, TikTok and YouTube, and see what happened.

## 1. What Content Machine is, and what we take from it

Content Machine captures ideas (Telegram, API, voice notes), turns them into posts and videos, and publishes them through **PostSyncer**, a paid third-party service that holds the connections to Facebook, Instagram, TikTok, YouTube and others. Content Machine only orchestrates. Roughly 44,000 lines of PHP, 20,000 of TypeScript, 191 test files.

| Part | Decision |
|---|---|
| PostSyncer client (workspaces, accounts, media upload, create post, get post) | **Take, in reduced form** (`PostsyncerClient`). |
| "Uncertain outcome" handling: checkpoint before every external call, never replay a create whose answer was lost, reconcile by hand | **Take the design.** PostSyncer has no idempotency key; this is why the original `PublishPostAction` is 4,000 lines. We port the rule, not the size (§8). |
| Approval gate before anything goes live | **Take.** Same idea as `MetaAdProposal` in this repo. |
| Scratch Pad, Telegram capture, voice transcription, Google Drive, video deck player, MCP endpoint, series, the author's own post templates | **Leave out of v1.** |
| React/Inertia UI, `Workspace`/`Team` tenancy, PostgreSQL specifics, Railway deploy | **Do not copy.** Filament pages, `BelongsToCompany`, MySQL 8.4, Coolify. |

## 2. Owner decisions (confirmed 2026-10-27)

| # | Decision | Choice |
|---|---|---|
| 1 | How posts reach the platforms | **PostSyncer first, Meta Graph API later.** The code talks to a `SocialPublisher` interface so a Meta driver can be added without touching the rest (§9, Phase 4). |
| 2 | Which companies | **Every company**, by the same company-ownership rule as the rest of the app (`BelongsToCompany`). Each company keeps its own PostSyncer API key and account map. |
| 3 | Platforms | **Facebook Page, Instagram, TikTok, YouTube.** TikTok and YouTube are video, so video publishing is in scope (§9, Phase 2). |
| 4 | Approval | A user with the approve permission **may approve their own post**. |
| 5 | Queue worker | **Running on Coolify** (owner, 2026-10-27). That worker serves the default queue only; see §4 for why publishing still needs a second one. |

### Not yet decided (do not invent, ask the owner before building the part that needs it)

- Which roles get the social permissions by default (§6).
- Language: v1 assumes **one language per post** (Bangla or English). Per-platform captions and bilingual posts are not decided.
- Rules for what a post may say about price or stock (§8: the AI never writes numbers; whether a human may type a price into a caption is open).
- The PostSyncer plan, its limits, and who pays.
- Video limits we enforce (file size, length), and the per-platform fields TikTok and YouTube need (title, privacy, category). These come from PostSyncer's API documentation at the start of Phase 2, not from guesses.

## 3. Scope

### v1 (Phases 1 to 3)
- Social posts: text, images, video; draft → ready → scheduled → posted; approve; schedule or publish now; per-platform result and error shown on the post.
- Per-company PostSyncer connection (encrypted key, account map, "test connection").
- Calendar view of scheduled and posted items.
- AI-drafted caption from a Product or an Offer, always saved as a draft waiting for approval.
- Audit log entries for approve, publish, retry and reconcile.

### Out of scope for v1
- Telegram and voice capture, an ideas inbox, a Scratch Pad.
- Replying to comments, inbox for comments.
- Analytics (Phase 5, later).
- Posting to platforms beyond the four above.

## 4. How this fits the existing architecture

- **Tenancy**: `SocialPost` and its children use `BelongsToCompany` + `CompanyScope` and **must** be added to `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract`. New jobs set and clear `CompanyContext` themselves; the scheduled sync loops over companies (`CLAUDE.md`).
- **Credentials**: the PostSyncer key is an admin-entered, encrypted setting per company, kept in `companies.settings` the way `AiSettingsService` keeps AI keys (`Crypt`, never hardcoded, never logged, never returned to the browser).
- **AI**: a new tool `social_post` ("Social Post Writer") in `AiSettingsService::TOOLS`, called through the existing `AiLlmClient`, like `OfferLandingPageAiGenerator`. The model writes words only; any number comes from the record.
- **Images**: a post may use the product's own pictures, an upload, or a `GeneratedImage` whose review is approved (`ImageGovernanceService`). A not-yet-approved generated image cannot be attached, the same rule as for products.
- **Time**: `app.timezone` is UTC and each company has its own `timezone`. People type the schedule time in the **company's** timezone; storage is UTC.
- **Scheduler**: `bootstrap/app.php` already schedules commands with `->everyFiveMinutes()->withoutOverlapping()->onOneServer()`; the new sync command follows that line. It relies on the same `schedule:run` that the existing commands rely on.
- **Public media URLs**: PostSyncer fetches media from a URL, so every attached file needs a URL reachable from the internet (`CompanyMedia::publicDirectory`, `StorageUrl`). Phase 1 starts by checking this on the live storage (§10).
- **Queue (important)**: `config/queue.php` gives every connection `retry_after = 90` seconds, and the documented worker (`queue:work --sleep=1 --tries=3 --max-time=3600`) has a 60-second default timeout. A publish can take minutes, and a video upload up to 15 minutes. Under those settings the queue would hand the same job to a second worker while the first is still running, and the post could go out twice. So publishing gets **its own queue connection** (`social`, `retry_after` above the job timeout) and **its own worker process** (`php artisan queue:work social --queue=social --timeout=900 --tries=1`). Content Machine solves it the same way. The owner has to add that second worker in Coolify; this is a deploy step, listed in §9 Phase 1.

## 5. Filament structure

| Type | Path | Purpose |
|---|---|---|
| Cluster | `app/Filament/Clusters/Social.php` | New navigation group "Social", next to Ads. |
| Resource | `app/Filament/Resources/SocialPosts/SocialPostResource.php` | List with status tabs (Draft, Waiting approval, Scheduled, Posted, Blocked, Archived); form; row actions Approve, Schedule, Publish now, Retry, Reconcile. |
| Page | `app/Filament/Pages/SocialSettings.php` | PostSyncer key, platform account map, "Test connection", video limits. |
| Page | `app/Filament/Pages/SocialCalendar.php` | Month and week view of scheduled and posted items (Phase 1b). |
| Actions elsewhere | `ProductResource`, `OfferResource` | "Draft a social post" button (Phase 3). |

A sidebar badge uses `HasGuardedNavigationBadge`; any widget uses `GuardsWidgetRendering`; anything drawn on every page goes through `ModuleGuard::run()` (`CLAUDE.md`, error isolation).

## 6. Permissions

| Key | Gates |
|---|---|
| `social.view` | See posts and the calendar |
| `social.create` | Write and edit drafts |
| `social.update` | Edit scheduled and ready posts |
| `social.delete` | Archive and delete |
| `social.approve` | Approve a post (own posts included, owner decision §2.4) |
| `social.publish` | Schedule and publish now |
| `social.settings` | Open Social Settings, change the PostSyncer key |

Added to the permission list and labels in `app/Models/User.php` and to `docs/roles-and-permissions.md`. Default role mapping is **open** (§2): the build ships with these keys granted to no role but super admin, until the owner says who gets what.

## 7. Data model

### 7.1 `social_posts` (company-owned)

| Column | Notes |
|---|---|
| `id`, `company_id` | `BelongsToCompany` |
| `number`, `human_id` | per-company running number, shown as `S-12` |
| `title`, `body`, `language` | `bn` \| `en` |
| `platforms` | json list: `facebook`, `instagram`, `tiktok`, `youtube` |
| `platform_options` | json, per-platform fields (YouTube title and privacy, TikTok privacy, etc. — fixed in Phase 2 from PostSyncer's docs) |
| `scheduled_at` | UTC; entered in the company's timezone |
| `status` | `draft`, `ready`, `scheduled`, `posted`, `blocked`, `archived` |
| `approval_state`, `approved_by_user_id`, `approved_at` | `pending` \| `approved` |
| `publish_state` | `idle`, `queued`, `running`, `succeeded`, `failed` |
| `publish_error` | text, safe to show |
| `publish_progress` | json, **private**, never returned to the browser or logs: the checkpoint (plan hash, per-group key, media ids, create key) |
| `postsyncer` | json, public result: one entry per group with PostSyncer post id, platforms, status |
| `source_type`, `source_id` | nullable morph: the `Product` or `Offer` it was drafted from |
| `is_ai_drafted` | bool |
| `created_by_user_id`, timestamps | |

### 7.2 `social_post_media`

| Column | Notes |
|---|---|
| `company_id`, `social_post_id`, `sort_order` | |
| `kind` | `image` \| `video` |
| `path` | company storage path |
| `generated_image_id` / `generated_video_id` | nullable; set when the file came from an approved generation |
| `mime`, `bytes`, `sha256` | the hash makes re-attaching the same file idempotent |

Indexes get explicit names under 64 characters (`MigrationIdentifierLengthTest`, MySQL 8.4).

### 7.3 Settings (no table)

`companies.settings.social.postsyncer`: `api_key` (encrypted), `workspace_id`, `accounts` (platform → PostSyncer account id), `enabled`.

## 8. New classes

```
app/Services/Social/
  Contracts/SocialPublisher.php        // plan(), publish(), status(), reconcile...()
  Postsyncer/PostsyncerClient.php      // ported, reduced; Http::fake-able
  Postsyncer/PostsyncerPublisher.php   // implements SocialPublisher
  Postsyncer/PostsyncerException.php   // carries "response received?" and "safe to retry?"
  SocialPostPlanner.php                // post -> publish groups (per platform rules)
  SocialMediaUrlResolver.php           // media -> public URL, checks reachability
  SocialPostAiGenerator.php            // Product/Offer -> draft caption (Phase 3)
  SocialPostApprovalService.php        // approve / un-approve; audit log
app/Jobs/PublishSocialPostJob.php      // ShouldBeUnique, lease, queue connection "social"
app/Console/Commands/SyncSocialPosts.php   // every 5 minutes, per company
```

**The publish rules (carried over from Content Machine, and the reason it is safe):**
1. Nothing goes out unless `approval_state = approved` and the post is not blocked.
2. Before each call to PostSyncer the job writes a checkpoint into `publish_progress`. The job is unique per post and holds a lease; a stale retry cannot overwrite a newer run.
3. If the answer to a media upload or a create is lost (timeout, dropped connection), the group becomes `uncertain`. It is **never** replayed automatically. A person checks PostSyncer and uses **Reconcile** (give the PostSyncer id, or confirm it is absent), then retries.
4. A post that is queued, running or uncertain cannot be edited.
5. Partial success (one platform failed) is shown per platform; a retry only creates the platforms that did not go out.
6. Video: PostSyncer's URL import does not store videos (Content Machine's finding, 2026-10-26), so a video is downloaded and sent to PostSyncer's file-upload endpoint, one request per file, with a 500 MB cap per file.

Reconcile in v1 covers the two cases that actually happen: an uncertain create and an uncertain media upload. The 4,000-line original also handles rescheduling a half-published plan, account remapping and plan drift; those are **not** ported until a real case needs them, and the post is `blocked` with a clear reason instead.

## 9. Phased rollout

- **Phase 0 — Prep.**
  - Plan doc (this file). Branch: the session's designated branch.
  - Confirm on the live server that `schedule:run` runs every minute (it carries the existing CRM commands), and add the **second Coolify worker** for publishing: same image, start command `php artisan queue:work social --queue=social --timeout=900 --tries=1 --sleep=1 --max-time=3600`.
  - The owner opens a PostSyncer account, creates an API key, and connects the Facebook Page, Instagram, TikTok and YouTube accounts there. PostSyncer's API documentation is read at the start of Phase 1 and Phase 2 for the exact fields; none are assumed here.
- **Phase 1 — Foundation: text and image posts to Facebook and Instagram.**
  Migrations, `SocialPost` and `SocialPostMedia`, isolation-test registration, permissions, `SocialSettings` (key, account map, test connection), `PostsyncerClient`, `PostsyncerPublisher`, `SocialPostPlanner`, `PublishSocialPostJob`, the `social` queue connection, `SyncSocialPosts` (scheduled), the resource with Approve / Schedule / Publish now / Retry, the two Reconcile actions, audit-log entries, bilingual keys, tests, codebase-map file `docs/codebase-map/social-content.md` plus the index line in `CODEBASE_MAP.md`.
  *Phase 1b:* the calendar page.
- **Phase 2 — Video: TikTok, YouTube, and Reels.**
  Video upload on the post (file in company storage, or an approved `GeneratedVideo`), the file-upload path in `PostsyncerClient`, per-platform required fields, size and length checks, longer job timeout and progress shown on the post. Starts by reading PostSyncer's TikTok and YouTube requirements.
- **Phase 3 — AI drafts from the catalog.**
  `social_post` tool in `AiSettingsService`, `SocialPostAiGenerator`, "Draft a social post" on Product and Offer, product picture or approved generated image attached. Always saved as a draft with approval pending. Prices, stock and delivery figures come only from the record.
- **Phase 4 — Meta Graph driver (optional).** `MetaPagePublisher` behind `SocialPublisher`, using the Meta app connection the ERP already has. Needs Meta app review for page publishing and Instagram content publishing; the owner decides when.
- **Phase 5 — Results (optional).** Reach and clicks per post from PostSyncer's analytics.

## 10. Risks and open engineering questions

- **No idempotency in PostSyncer.** A lost answer cannot be replayed safely. Mitigation is §8 (checkpoint, uncertain state, manual reconcile). This is the part to review most carefully.
- **Duplicate publish from the queue settings** (§4). Mitigated by the dedicated connection and worker. If the second worker is not running, publishing must not be switched on; Social Settings shows a warning until a heartbeat from that worker is seen.
- **Public media URLs.** If company storage files are not reachable from the internet (private disk, R2 without a public URL), PostSyncer cannot fetch them. Checked at the start of Phase 1; the planner refuses a post whose media URL does not answer.
- **Wrong content going live.** The AI only drafts; nothing is posted without an approve, and approving your own post is allowed by the owner's decision, so the audit log records who approved what and when.
- **Platform rules** (TikTok and YouTube privacy, titles, categories, length limits) are not known here and are read from PostSyncer's documentation in Phase 2.
- **Cost and dependency.** PostSyncer is a paid third-party service; if it stops, publishing stops. The `SocialPublisher` interface and the Phase 4 Meta driver are the way out.
- **Bangla text.** Captions are plain text sent as is; any text drawn onto an image goes through the existing Imagick-based ad-creative renderer (doc 14), not through this module.

## 11. Testing and verification

Per `CLAUDE.md`: tests create their own records under `RefreshDatabase`, run as plain `php artisan test` (no `--env`), and never touch demo data. PostSyncer is always faked with `Http::fake`. Tests to write with Phase 1:

- an unapproved post cannot be queued or published;
- one company never sees or publishes another company's post or uses its key;
- the job running twice creates one PostSyncer post;
- a lost answer leaves the group `uncertain` and a plain retry refuses;
- Reconcile with an id and Reconcile with "confirmed absent" each move the group to the right state;
- a post with one failed platform retries only that platform;
- the PostSyncer key is stored encrypted and never appears in a response or log;
- `TranslationParityTest`, `ModuleGuardTest`, `MigrationIdentifierLengthTest`, `MultiCompanyIsolationTest`.

**Where tests can run:** the project needs PHP 8.4 (its lock file pulls Symfony 8). The cloud computer used for planning has PHP 8.3, so `composer install` and `php artisan test` cannot run there. Code written there is checked with `php -l` only, and CI on the branch is the real test run before anything reaches `main`.

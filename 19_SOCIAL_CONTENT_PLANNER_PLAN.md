# 19 — Social Content Planner (Module Plan)

Status: **Phase 1 built 2026-10-30** (text and one picture to a Facebook Page and Instagram; see §12). Phases 0 (the owner's real Meta/YouTube/TikTok tests and the second Coolify worker) and 2 to 5 are not done. Owner decisions confirmed 2026-10-27 (§2). The publish path was revised the same day (Meta Graph API first, PostSyncer later) after the owner found PostSyncer expensive. Written after reviewing the open-source repo [HarunRRayhan/content-machine](https://github.com/HarunRRayhan/content-machine) (MIT, Laravel 13 + Inertia/React, a personal content pipeline that publishes through PostSyncer) at the owner's request ("এটা ইমপ্লিমেন্ট করা যায় কিনা?"). Intended as the pre-execution reference for the build, the same way documents 11, 12 and 14 were used.

The ERP has no tool for **organic** social posts: Meta Ads (doc 14 and the Ads cluster) only buys ads, and the Inbox only answers messages. This module adds: write a post, get it approved, schedule it or publish it now to Facebook, Instagram, TikTok and YouTube, and see what happened.

## 1. What Content Machine is, and what we take from it

Content Machine captures ideas (Telegram, API, voice notes), turns them into posts and videos, and publishes them through **PostSyncer**, a paid third-party service that holds the connections to Facebook, Instagram, TikTok, YouTube and others. Content Machine only orchestrates. Roughly 44,000 lines of PHP, 20,000 of TypeScript, 191 test files.

| Part | Decision |
|---|---|
| PostSyncer client (workspaces, accounts, media upload, create post, get post) | **Take, in reduced form, later** (Phase 4, only for the platforms whose own approval stalls). |
| "Uncertain outcome" handling: checkpoint before every external call, never replay a create whose answer was lost, reconcile by hand | **Take the design.** A lost answer to a create call cannot be told apart from a failed call. PostSyncer has no idempotency key (its own guide says so) and we do not assume Meta offers one. This is why the original `PublishPostAction` is 4,000 lines. We port the rule, not the size (§8). |
| Approval gate before anything goes live | **Take.** Same idea as `MetaAdProposal` in this repo. |
| Scratch Pad, Telegram capture, voice transcription, Google Drive, video deck player, MCP endpoint, series, the author's own post templates | **Leave out of v1.** |
| React/Inertia UI, `Workspace`/`Team` tenancy, PostgreSQL specifics, Railway deploy | **Do not copy.** Filament pages, `BelongsToCompany`, MySQL 8.4, Coolify. |

## 2. Owner decisions (confirmed 2026-10-27)

| # | Decision | Choice |
|---|---|---|
| 1 | How posts reach the platforms | **Meta Graph API first (Facebook Page, Instagram), PostSyncer later if needed.** Changed on 2026-10-27, after the owner saw PostSyncer's price (§2.1). The code talks to a `SocialPublisher` interface, so the PostSyncer driver can be added later without touching the rest (§9, Phase 4). |
| 2 | Which companies | **Every company**, by the same company-ownership rule as the rest of the app (`BelongsToCompany`). Each company keeps its own platform connection. |
| 3 | Platforms | **Facebook Page, Instagram, TikTok, YouTube.** Video is in scope (§9, Phase 2). TikTok and YouTube come last (§9, Phase 4) because each needs its own platform approval, or PostSyncer. |
| 4 | Approval | A user with the approve permission **may approve their own post**. |
| 5 | Queue worker | **Running on Coolify** (owner, 2026-10-27). That worker serves the default queue only; see §4 for why publishing still needs a second one. |

### 2.1 Why Meta first: cost and approval facts (checked 2026-10-27)

| Option | Cost | What stands in the way |
|---|---|---|
| **PostSyncer** ([pricing](https://postsyncer.com/pricing)) | Starter $29/month ($24 if paid yearly): 10 accounts, 1 workspace, 100 API posts a day. Pro $49 ($39): 15 accounts, 2 workspaces, 250 a day. Pro Plus $99: 30 accounts, 3 workspaces, 500 a day. Each extra workspace $19/month. 7-day free trial. | One workspace per company would multiply the price. Publishing stops if the service stops. |
| **Meta Graph API, direct** | No fee. | Standard Access is granted automatically to an app used only by people who have a role on it; App Review and Business Verification belong to Advanced Access ([access levels](https://developers.facebook.com/docs/graph-api/overview/access-levels/)). That page does not name `pages_manage_posts` or `instagram_content_publish`, so Phase 0 proves it with a real post. Instagram needs a professional account, media on a public URL, JPEG only for images, and allows 100 API-published posts per 24 hours; the carousel section of the same page says 50 ([Instagram publishing](https://developers.facebook.com/docs/instagram-platform/content-publishing/)). |
| **TikTok API, direct** | No fee. | Posts from an unaudited client are private-only; an audit lifts that ([Content Posting API](https://developers.tiktok.com/doc/content-posting-api-get-started)). How long the audit takes is not stated. |
| **YouTube Data API, direct** | No fee. | Default quota is 100 `videos.insert` calls a day ([quota](https://developers.google.com/youtube/v3/guides/quota_and_compliance_audits)), enough for us. The [revision history](https://developers.google.com/youtube/data/revision_history) says uploads from an unverified project created after 28 July 2020 are locked private until an audit, while the current [videos.insert](https://developers.google.com/youtube/v3/docs/videos/insert) page says they are not. The two disagree, so Phase 0 uploads one real video to see. |
| **Mixpost** ([pricing](https://mixpost.app/pricing)) | Lite free but only Facebook Pages, X and Mastodon. Pro $299 once (one domain, one year of updates): all platforms, API, workspaces, approvals. | Considered, not chosen: still needs our own TikTok and YouTube approvals, and adds a second system to run. |
| **Postiz** ([repo](https://github.com/gitroomhq/postiz-app)) | Free to self-host, AGPL-3.0. | Needs its own Docker stack (Postgres, Redis, 4 to 8 GB RAM), and the AGPL licence means it could only ever run as a separate service, never inside this codebase. Considered, not chosen. |

What the owner's decision means in practice: Facebook and Instagram cost nothing. TikTok and YouTube become free if their audits go through; if they stall or are refused, PostSyncer's Starter plan is the fallback for those two only.

### Not yet decided (do not invent; ask the owner before building the part that needs it)

- Which roles get the social permissions by default (§6).
- Language: v1 assumes **one language per post** (Bangla or English). Per-platform captions and bilingual posts are not decided.
- Rules for what a post may say about price or stock (§8: the AI never writes numbers; whether a human may type a price into a caption is open).
- Meta setup: use the Meta app the Inbox already uses, or a new app; who creates the Page access token and with which permissions (§4). Confirmed in Phase 0.
- Video limits we enforce (file size, length), and the fields TikTok and YouTube need (title, privacy, category). These come from each platform's current documentation at the start of Phase 2 and Phase 4, not from guesses.
- Whether PostSyncer is bought at all (only if a TikTok or YouTube audit stalls), and who pays.

## 3. Scope

### v1 (Phases 1 to 3)
- Social posts: text, images, video to a **Facebook Page and Instagram**; draft → ready → scheduled → posted; approve; schedule or publish now; per-platform result and error shown on the post.
- Per-company Meta connection (Page id, Instagram account id, Page access token, "test connection").
- Calendar view of scheduled and posted items.
- AI-drafted caption from a Product or an Offer, always saved as a draft waiting for approval.
- Audit log entries for approve, publish, retry and reconcile.

### Phase 4
- TikTok and YouTube, directly or through PostSyncer (§9).

### Out of scope
- Telegram and voice capture, an ideas inbox, a Scratch Pad.
- Replying to comments, inbox for comments.
- Analytics (Phase 5, later).
- Platforms beyond the four above.

## 4. How this fits the existing architecture

- **Tenancy**: `SocialPost` and its children use `BelongsToCompany` + `CompanyScope` and **must** be added to `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract`. New jobs set and clear `CompanyContext` themselves; scheduled commands loop over companies (`CLAUDE.md`).
- **Credentials**: Facebook Page id, Instagram business account id and a Page access token are admin-entered, encrypted settings per company in `companies.settings` (key `social`), the way `AiSettingsService` and `EmbeddedSignupSettingsService` keep theirs (`Crypt`, never hardcoded, never logged, never returned to the browser). A **separate** token is used for posting: the Page token the Inbox holds for Messenger (`ConversationChannel.access_token`) and the Ads token (`MetaAdAccount.credentials`) were issued for messaging and for ads, not for publishing, and are not reused (least privilege). A PostSyncer key, if Phase 4 needs it, goes in the same setting.
- **Meta publishing**: `app/Services/Meta/MetaGraphService.php` already wraps the Graph API (versioned URL, retries, friendly errors, `MetaGraphException`). Publishing calls are added there, not in a new HTTP client.
- **AI**: a new tool `social_post` ("Social Post Writer") in `AiSettingsService::TOOLS`, called through the existing `AiLlmClient`, like `OfferLandingPageAiGenerator`. The model writes words only; any number comes from the record.
- **Images**: a post may use the product's own pictures, an upload, or a `GeneratedImage` whose review is approved (`ImageGovernanceService`). A not-yet-approved generated image cannot be attached, the same rule as for products. Instagram takes JPEG only, so a picture in another format is converted to JPEG before it is sent (part of Phase 1).
- **Time**: `app.timezone` is UTC and each company has its own `timezone`. People type the schedule time in the **company's** timezone; storage is UTC.
- **Scheduling is done by the ERP, not by the platform**: a command queues every post that is due, running every minute with `->everyMinute()->withoutOverlapping()->onOneServer()` like `crm:revert-conversations` in `bootstrap/app.php`. Every platform then behaves the same, and a schedule can be changed until the moment it is queued. It relies on the same `schedule:run` the existing commands rely on.
- **Public media URLs**: Meta fetches media from a URL, so every attached file needs a URL reachable from the internet (`CompanyMedia::publicDirectory`, `StorageUrl`). Phase 0 checks this on the live storage (§10).
- **Queue (important)**: `config/queue.php` gives every connection `retry_after = 90` seconds, and the documented worker (`queue:work --sleep=1 --tries=3 --max-time=3600`) has a 60-second default timeout. A publish can take minutes, and a video far longer. Under those settings the queue would hand the same job to a second worker while the first is still running, and the post could go out twice. So publishing gets **its own queue connection** (`social`, `retry_after` above the job timeout) and **its own worker process** (`php artisan queue:work social --queue=social --timeout=900 --tries=1`). Content Machine solves it the same way. The owner has to add that second worker in Coolify; this is a deploy step, listed in §9 Phase 0.

## 5. Filament structure

| Type | Path | Purpose |
|---|---|---|
| Cluster | `app/Filament/Clusters/Social.php` | New navigation group "Social", next to Ads. |
| Resource | `app/Filament/Resources/SocialPosts/SocialPostResource.php` | List with status tabs (Draft, Waiting approval, Scheduled, Posted, Blocked, Archived); form; row actions Approve, Schedule, Publish now, Retry, Reconcile. |
| Page | `app/Filament/Pages/SocialSettings.php` | Meta connection (Page id, Instagram account id, Page access token), "Test connection", video limits. PostSyncer key from Phase 4. |
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
| `social.settings` | Open Social Settings, change the platform tokens |

Added to the permission list and labels in `app/Models/User.php` and to `docs/roles-and-permissions.md`. Default role mapping is **open** (§2): the build ships with these keys granted to no role but super admin, until the owner says who gets what.

## 7. Data model

### 7.1 `social_posts` (company-owned)

| Column | Notes |
|---|---|
| `id`, `company_id` | `BelongsToCompany` |
| `number`, `human_id` | per-company running number, shown as `S-12` |
| `title`, `body`, `language` | `bn` \| `en` |
| `platforms` | json list: `facebook`, `instagram`, `tiktok`, `youtube` |
| `platform_options` | json, per-platform fields (YouTube title and privacy, TikTok privacy, etc. — fixed in Phase 2 and 4 from each platform's documentation) |
| `scheduled_at` | UTC; entered in the company's timezone |
| `status` | `draft`, `ready`, `scheduled`, `posted`, `blocked`, `archived` |
| `approval_state`, `approved_by_user_id`, `approved_at` | `pending` \| `approved` |
| `publish_state` | `idle`, `queued`, `running`, `succeeded`, `failed` |
| `publish_error` | text, safe to show |
| `publish_progress` | json, **private**, never returned to the browser or logs: the checkpoint per platform (step reached: media checked, container created, publish called; the ids returned) |
| `publish_result` | json, public result: one entry per platform with the platform's own post id, link and status |
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

`companies.settings.social`: `enabled`; `meta` (`page_id`, `instagram_account_id`, `page_access_token` encrypted); from Phase 4 `postsyncer` (`api_key` encrypted, `workspace_id`, `accounts`: platform → PostSyncer account id).

## 8. New classes

```
app/Services/Social/
  Contracts/SocialPublisher.php        // plan(), publish(), status(), reconcile...()
  Meta/MetaPagePublisher.php           // Facebook Page + Instagram, uses MetaGraphService (Phase 1)
  Postsyncer/PostsyncerClient.php      // ported, reduced; Http::fake-able (Phase 4)
  Postsyncer/PostsyncerPublisher.php   // implements SocialPublisher (Phase 4)
  Postsyncer/PostsyncerException.php   // carries "response received?" and "safe to retry?"
  SocialPostPlanner.php                // post -> one publish step list per platform
  SocialMediaUrlResolver.php           // media -> public URL, JPEG conversion, checks reachability
  SocialPostAiGenerator.php            // Product/Offer -> draft caption (Phase 3)
  SocialPostApprovalService.php        // approve / un-approve; audit log
app/Jobs/PublishSocialPostJob.php      // ShouldBeUnique, lease, queue connection "social"
app/Console/Commands/PublishDueSocialPosts.php   // every minute, per company: queues due, approved posts
```

`MetaGraphService` gains the publishing calls (Page post and photo/video, Instagram container, container status, publish). `PostsyncerException` is only needed with the PostSyncer driver, but its "response received / safe to retry" flags are the model for `MetaGraphException` handling here too.

**The publish rules (carried over from Content Machine, and the reason it is safe):**
1. Nothing goes out unless `approval_state = approved` and the post is not blocked.
2. Before each external call the job writes a checkpoint into `publish_progress`. The job is unique per post and holds a lease; a stale retry cannot overwrite a newer run.
3. If the answer to a media step or a publish call is lost (timeout, dropped connection), that platform's step becomes `uncertain`. It is **never** replayed automatically. A person checks the platform and uses **Reconcile** (give the platform's post id, or confirm it is absent), then retries.
4. A post that is queued, running or uncertain cannot be edited.
5. Partial success (one platform failed) is shown per platform; a retry only publishes the platforms that did not go out.
6. Video: Instagram Reels take a `video_url` that Meta fetches and then processes on its side; the job waits for that to finish before it calls publish (Phase 2 reads Meta's current documentation for the exact status calls). For a PostSyncer run (Phase 4) a video is downloaded and sent as a file, because PostSyncer's URL import does not store videos (Content Machine's finding, 2026-10-26), with a 500 MB cap per file.

Reconcile in v1 covers the two cases that actually happen: an uncertain publish call and an uncertain media or container step. The 4,000-line original also handles rescheduling a half-published plan, account remapping and plan drift; those are **not** ported until a real case needs them, and the post is `blocked` with a clear reason instead.

## 9. Phased rollout

- **Phase 0 — Prep and two real tests (before any code).**
  - Plan doc (this file). Branch: the session's designated branch.
  - **Meta test post.** With the owner's Meta app (an admin, developer or tester of it) and a Page token that has the publishing permissions (`pages_manage_posts`, `pages_read_engagement`, `instagram_basic`, `instagram_content_publish` are the names to try), publish one test Page post and one Instagram photo by hand through the Graph API. This settles the open point in §2.1 and which app and token to use.
  - **YouTube test.** Upload one real video through a Google API project and check whether it is locked private (§2.1). If it is, apply for the YouTube API audit at once; if not, nothing is needed.
  - **TikTok.** Apply for the Content Posting API and its audit now, because the wait is not stated anywhere and the work is only needed in Phase 4.
  - Confirm on the live server that `schedule:run` runs every minute (it carries the existing CRM commands), and add the **second Coolify worker** for publishing: same image, start command `php artisan queue:work social --queue=social --timeout=900 --tries=1 --sleep=1 --max-time=3600`.
- **Phase 1 — Foundation: text and image posts to Facebook and Instagram.**
  Migrations, `SocialPost` and `SocialPostMedia`, isolation-test registration, permissions, `SocialSettings` (Meta connection, test connection), the publishing calls on `MetaGraphService`, `MetaPagePublisher`, `SocialPostPlanner`, `SocialMediaUrlResolver` (public URL and JPEG conversion), `PublishSocialPostJob`, the `social` queue connection, `PublishDueSocialPosts` (scheduled), the resource with Approve / Schedule / Publish now / Retry, the two Reconcile actions, audit-log entries, bilingual keys, tests, codebase-map file `docs/codebase-map/social-content.md` plus the index line in `CODEBASE_MAP.md`.
  *Phase 1b:* the calendar page.
- **Phase 2 — Video: Facebook video and Instagram Reels.**
  Video upload on the post (file in company storage, or an approved `GeneratedVideo`), container processing and status checks, size and length checks, longer job timeout and progress shown on the post. Starts by reading Meta's current Reels requirements.
- **Phase 3 — AI drafts from the catalog.**
  `social_post` tool in `AiSettingsService`, `SocialPostAiGenerator`, "Draft a social post" on Product and Offer, product picture or approved generated image attached. Always saved as a draft with approval pending. Prices, stock and delivery figures come only from the record.
- **Phase 4 — TikTok and YouTube.**
  Decided by what Phase 0 found. If the audits are granted: direct drivers behind `SocialPublisher` (per-company OAuth, token refresh). If they stall or are refused: the reduced `PostsyncerPublisher` for those two platforms only (Starter plan, 7-day free trial first). Either way the post screen and the approval rules do not change.
- **Phase 5 — Results (optional).** Reach and clicks per post, from each platform's own insight calls.

## 10. Risks and open engineering questions

- **Lost answers cannot be replayed safely.** Mitigation is §8 (checkpoint, uncertain state, manual reconcile). This is the part to review most carefully.
- **Duplicate publish from the queue settings** (§4). Mitigated by the dedicated connection and worker. If the second worker is not running, publishing must not be switched on; Social Settings shows a warning until a heartbeat from that worker is seen.
- **Meta tokens.** A Page access token can be revoked or expire (password change, role removal, permission change). The job treats an authentication error as `blocked` with a clear message and does not retry; Social Settings shows when the last successful call happened. How long a token lives depends on how it is made and is settled in Phase 0.
- **Meta access without App Review** is documented for apps used only by people with a role on them, but the two publishing permissions are not named on that page (§2.1). If Meta says otherwise in Phase 0, the plan returns to the owner before Phase 1 starts.
- **Instagram limits.** JPEG only, a professional account, a public media URL, 100 API posts per 24 hours (50 on the carousel line of the same page). The planner refuses a post that breaks one of these before anything is sent.
- **Public media URLs.** If company storage files are not reachable from the internet (private disk, R2 without a public URL), Meta cannot fetch them. Checked in Phase 0; the planner refuses a post whose media URL does not answer.
- **Wrong content going live.** The AI only drafts; nothing is posted without an approve, and approving your own post is allowed by the owner's decision, so the audit log records who approved what and when.
- **TikTok and YouTube approvals** may take long or be refused; that is why they are last (§2.1, Phase 4).
- **Bangla text.** Captions are plain text sent as is; any text drawn onto an image goes through the existing Imagick-based ad-creative renderer (doc 14), not through this module.

## 11. Testing and verification

Per `CLAUDE.md`: tests create their own records under `RefreshDatabase`, run as plain `php artisan test` (no `--env`), and never touch demo data. Meta and PostSyncer are always faked with `Http::fake`. Tests to write with Phase 1:

- an unapproved post cannot be queued or published;
- one company never sees or publishes another company's post or uses its token;
- the job running twice publishes once;
- a lost answer leaves the platform step `uncertain` and a plain retry refuses;
- Reconcile with an id and Reconcile with "confirmed absent" each move the step to the right state;
- a post with one failed platform retries only that platform;
- an authentication error blocks the post and is not retried;
- the Page access token is stored encrypted and never appears in a response or log;
- `TranslationParityTest`, `ModuleGuardTest`, `MigrationIdentifierLengthTest`, `MultiCompanyIsolationTest`.

**Where tests can run:** the project needs PHP 8.4 (its lock file pulls Symfony 8). The cloud computer used for planning has PHP 8.3, so `composer install` and `php artisan test` cannot run there. Code written there is checked with `php -l` only, and CI on the branch is the real test run before anything reaches `main`.

## 12. What Phase 1 built (2026-10-30)

Built to this plan, with these choices where the plan left room:

- **One picture per post.** Text posts and single-picture posts to a Facebook Page, and single-picture posts to Instagram. Several pictures (a carousel), video, TikTok and YouTube are later phases; the planner refuses a post that asks for them.
- **Approval is tied to the exact content.** `social_posts.approved_fingerprint` stores a hash of the text, language, platforms, options and attached files at approval. Any later change voids the approval (the edit page takes it back at once; the publish job and the scheduler refuse a stale one).
- **Lease columns** `lease_token` and `lease_expires_at` on `social_posts` implement "the job holds a lease". A run claims the post with an atomic `queued -> running` update; every checkpoint write checks the token. `social:publish-due` also recovers runs a stopped worker left behind: the platform that was mid-call becomes `uncertain`.
- **Uncertain outcomes.** A transport failure (no answer) or a 5xx from Meta on a create call leaves that platform `uncertain`; it is never replayed. **Retry refuses** while any platform is uncertain; **Reconcile** (give the platform's post id, or confirm it is absent) is the only way out. Instagram's container step follows the same rule.
- **Worker heartbeat.** `SocialQueueHeartbeatJob` runs on the `social` queue every five minutes; Social Settings will not switch publishing on until it has been seen (§10).
- **Permissions** `social.*` exist, but **no built-in role holds them** except Super Admin (`*`), until the owner says who gets what (§6).
- **Not built, on purpose:** the calendar page (Phase 1b), the sidebar badge, the Instagram 100-posts-per-24-hours counter (Meta's own error is shown instead), per-platform captions, bilingual posts.
- **Still open for the owner:** the Phase 0 tests (a real Facebook post and Instagram photo by hand to see which token and permissions work, one YouTube upload, the TikTok application) and adding the second Coolify worker. Nothing is switched on for any company until Social Settings has a token and the worker has been seen.
- Tests: `SocialPostPublishingTest` (31) and `SocialPostResourceTest` (16); Meta is always faked.

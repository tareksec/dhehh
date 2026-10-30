# Social Content Planner
> Last checked: 2026-10-30, branch `ccr-91b5e504-q154a8` (Phase 1 of `19_SOCIAL_CONTENT_PLANNER_PLAN.md`)

## What it does
Organic social posts for every company: write a post, get it approved, schedule it or publish it now to a Facebook Page and Instagram, and see the per-platform result. Phase 1 covers text and **one picture** per post. Video, a calendar page, AI drafts, TikTok and YouTube are later phases (plan §9). Nothing is posted without an approval that still matches the content (`SocialPost::isApproved()`), and a create call whose answer is lost is never replayed (`uncertain`, then a person reconciles).

## Where to start
- `19_SOCIAL_CONTENT_PLANNER_PLAN.md` — the plan, owner decisions and §12 "What Phase 1 built".
- `app/Services/Social/SocialPublishRunner.php` — runs one queued post: claim with a lease, skip succeeded and uncertain platforms, conclude.
- `app/Services/Social/Meta/MetaPagePublisher.php` — Facebook (one call) and Instagram (container, wait, publish) with checkpoints.
- `app/Services/Social/SocialPostPublishingService.php` — schedule, publish now, retry, reconcile, archive.
- `app/Filament/Resources/SocialPosts/SocialPostResource.php` — the screens; the workflow buttons call the services.

## Files

### Models and tables
| Path | What it does |
| --- | --- |
| `app/Models/SocialPost.php` | The post: status, approval (with `approved_fingerprint`), publish state, private `publish_progress`, public `publish_result`, lease. `BelongsToCompany`. |
| `app/Models/SocialPostMedia.php` | A file attached to a post; fills `mime`, `bytes`, `sha256` on save. `BelongsToCompany`. |
| `database/migrations/2026_10_30_100000_create_social_posts_tables.php` | Tables `social_posts` and `social_post_media` (explicit short index names). |

### Services (`app/Services/Social/`)
| Path | What it does |
| --- | --- |
| `Contracts/SocialPublisher.php` | The driver interface: `platforms()`, `problems()`, `publish()`. A PostSyncer driver would implement it (Phase 4). |
| `SocialPublisherRegistry.php` | Which driver serves which platform. |
| `Meta/MetaPagePublisher.php` | The direct Meta Graph driver for Facebook and Instagram. |
| `SocialPublishRunner.php` | Claims a queued post, runs the platforms, concludes; `recoverStale()` for stopped workers. |
| `SocialPublishProgress.php` | The lease-guarded per-platform checkpoint. |
| `SocialPostPlanner.php` | Refuses a post that breaks a platform rule before anything is sent. |
| `SocialPostApprovalService.php` | Submit, approve (own posts allowed), revoke; writes the audit log. |
| `SocialPostPublishingService.php` | Schedule, unschedule, publish now, retry, reconcile (with id / absent), archive. |
| `SocialMediaUrlResolver.php` | Picture to a public URL Meta can fetch; converts to JPEG for Instagram; checks it answers. |
| `SocialSettingsService.php` | Per-company settings in `companies.settings.social` (Page token encrypted); worker heartbeat. |
| `SocialPostException.php`, `SocialMediaException.php`, `SocialLeaseLostException.php` | Safe, translated refusals and the lease-lost stop. |
| `app/Services/Meta/MetaGraphService.php` | Gained the publishing calls (`createPagePost`, `createPagePhotoPost`, Instagram container calls). Create calls use `publishRequest()`: one attempt, no retry. |
| `app/Services/Meta/MetaGraphException.php` | Gained `responseReceived`, `blocksPosting()`, `outcomeIsUnknown()`. |

### Jobs, commands, schedule, queue
| Path | What it does |
| --- | --- |
| `app/Jobs/PublishSocialPostJob.php` | Unique per post, `tries = 1`, timeout 900, on connection and queue `social`. |
| `app/Jobs/SocialQueueHeartbeatJob.php` | Proves the `social` worker is alive (Social Settings reads it). |
| `app/Console/Commands/PublishDueSocialPosts.php` | `social:publish-due`, every minute: queues due approved posts per company, recovers stale runs. |
| `bootstrap/app.php` | The schedule entries (command every minute, heartbeat every five minutes). |
| `config/queue.php` | The `social` connection (database table, `retry_after` 1800). Needs its own worker, see `docs/deployment.md`. |

### Filament
| Path | What it does |
| --- | --- |
| `app/Filament/Clusters/Social.php` | The "Social" navigation group, after Ads. |
| `app/Filament/Resources/SocialPosts/` | `SocialPostResource` (form, table, shared workflow actions) and its List (status tabs), Create, Edit pages. |
| `app/Filament/Pages/SocialSettings.php` | Meta connection (Page id, Instagram account id, token), test connection, worker status. |
| `resources/views/filament/social/post-result.blade.php`, `resources/views/filament/pages/social-settings.blade.php` | The per-platform result block and the settings page. |

### Permissions
`social.view`, `social.create`, `social.update`, `social.delete`, `social.approve`, `social.publish`, `social.settings` in `User::CUSTOM_PERMISSION_OPTIONS`; `SocialPost` and `SocialPostMedia` map to the `social` module in `User::MODEL_MODULES`. No built-in role holds them except Super Admin.

### Tests
`tests/Feature/SocialPostPublishingTest.php` (publish rules, lost answers, reconcile, isolation, scheduling) and `tests/Feature/SocialPostResourceTest.php` (screens, permissions, settings); both models are in `MultiCompanyIsolationTest`.

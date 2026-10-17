# 14 — AI Tool Menu: Content Creation / Ad Creative Studio (Module Plan)

Status: **Phase 0 done (2026-10-03): Bangla rendering check, Imagick, bundled font. Phase 1 waits on the production check.** Owner decisions confirmed 2026-09-24. Written after reviewing https://adbreezy.ai/ at the owner's request ("Paste a link, get ads that sell") and mapping its flow onto this codebase's existing AI Tools infrastructure (`11_AI_TOOL_MENU_IMAGE_GENERATION_PLAN.md`, `12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md`). Intended as the pre-execution reference for Claude Code Plan Mode, the same way those two documents were used.

The owner chose to build this **inside the reserved Content Creation slot** of the Tool Menu rather than as a separate fourth tool (§2). Ad creatives are the first thing Content Creation does; AI copywriting joins it later (§9, Phase 4).

## 1. What AdBreezy does, and what's actually new here

AdBreezy's flow: (1) paste a product URL → it scrapes photos/price/description/reviews, (2) a "Brand DNA" scan captures logo/colors/fonts/tone, (3) pick a template → it composites a static ad image (headline, offer badge, testimonial, etc.) or renders a scripted UGC avatar video, (4) export or push to Meta.

Mapped onto this ERP:

- **Step 1 (scrape a product page)** — not needed. Products already live in `products` as structured rows (name, description, price, sale_price, image, gallery_images), and real reviews live in `product_reviews`. "Paste a link" becomes "pick a Product", which is faster and more reliable than scraping.
- **Step 2 (Brand DNA)** — **already exists.** [`StorefrontSetting`](app/Models/StorefrontSetting.php) is per-company (`BelongsToCompany`), managed in the Filament admin, and holds a full theme palette (`theme_color`, `theme_secondary_color`, `theme_accent_color`, background/surface/text/muted/border, plus dark variants), a font-pairing system (`FONT_OPTIONS`, e.g. "Bangla Optimized — Hind Siliguri & Noto Bengali"), and `logo` / `logo_dark`. This tool reads it. It doesn't copy it or ask the owner to enter it again.
- **Step 3 (composite a static ad from a template)** — **this is the gap.** Nothing in the app bakes text onto an image in a fixed layout. Image Generation only does prompt → AI pixels.
- **Step 4 (publish to Meta)** — **already exists for static images.** `MetaAdsCreationService` always takes its creative image from `Product::$image`, so a composited ad attached as the product's featured image is what Meta Ads uses, with no Meta code changes.

So the new work is **a template-based static-ad compositor that reads Products, ProductReviews and StorefrontSetting, and writes into the existing attach → Meta pipeline.**

## 2. Owner decisions (confirmed 2026-09-24)

| # | Decision | Choice |
|---|---|---|
| 1 | Where the tool lives | **Merged into the reserved Content Creation slot** (`ToolMenu::TOOLS['content_creation']`), not a new fourth card. The card's label stays "Content Creation"; its description widens to cover ad creatives. The already-reserved `ai_tools.content_creation` permission key gates it, so there's no new key. |
| 2 | Templates in the first release | **All four groups:** Headline + Offer badge, Testimonial / Review, Before/After + Us vs Them, Listicle — six layouts in total (§7.1). |
| 3 | AI copy drafting | **Manual first, AI later.** v1 has manual text entry only. AI-drafted headlines/copy come in Phase 4. |
| 4 | Ad text language | **Both Bangla and English.** Correct Bangla rendering (conjuncts, vowel signs) is a **Phase 1 blocker**, not a later polish item (§10). |
| 5 | Testimonial source | **Approved reviews + manual entry.** The user can pick an approved `ProductReview` for the product, or type a testimonial by hand. Each creative records where its testimonial came from (§7.2). |
| 6 | Name shown on a testimonial | **Full name**, as stored in `ProductReview::customer_name` (or as typed for a manual testimonial). |
| 7 | Approval before attach | **Same rule as Image Generation.** Roles listed under Image Governance → "review roles" also need approval before their ad creatives can be attached. There's one shared setting and no separate config page. |
| 8 | Monthly cap | **None.** v1 renders make no external API call, so there's no per-render cost to limit. Revisit when Phase 4 AI drafting adds an LLM call. |

## 3. Scope

### In scope for v1

- The **Content Creation** page: pick a Product (or Offer), pick a template, fill the template's text fields in Bangla or English, pick an aspect ratio (reuse `GeneratedImage::ASPECT_RATIOS`: 1:1, 4:5, 16:9, 9:16, 3:2), and render a finished static ad as WebP/PNG.
- Six hand-built templates (§7.1).
- **Brand DNA read automatically from `StorefrontSetting`** (palette, font pairing, logo) and applied to every render.
- **Testimonial picker**: approved `ProductReview` rows for the chosen product (comment, rating, full customer name), or manual entry.
- **Prices always come from the record**: any price or discount a template shows is read from `Product::$price` / `$sale_price` when it renders. There's no free-text price field, so a typo can't publish a wrong price. Free text is for words, not numbers the store is held to.
- **Attach** to Product (featured / gallery) or Offer (cover), using the same mechanism as `GeneratedImageAttacher`, and behind the same review gate as Image Generation (§2.7).
- A creatives gallery (recent renders, download, attach status, review status).

### Out of scope for v1

| Feature | Why |
|---|---|
| AI copy drafting (headlines, captions, product descriptions) | Owner decision §2.3. Planned for Phase 4. |
| Product-URL scraping / import | Not needed (§1). Importing a *new* product from an external URL would be a separate feature. |
| UGC / talking-avatar video | A different provider type (script + avatar + voice) from Video Generation's image-to-video motion. It needs its own plan document. |
| Competitor ad spy / image cloner | Needs the Meta Ad Library API or a paid ad-intelligence API: a separate integration with admin-configurable credentials. Not scoped. |
| Crawling a website for brand colors/fonts | Unnecessary: `StorefrontSetting` already has them. |
| Drag-and-drop template editor | v1 templates are code definitions (same convention as `GeneratedImage::ASPECT_RATIOS`). |

## 4. How this fits the existing architecture

- **AiTools cluster / ToolMenu hub**: the `content_creation` entry's `page` changes from `null` to the new page, and the "Coming soon" tile becomes a live card. `ContentCreation::canAccess()` checks `ai_tools.content_creation`, the same way `ImageGeneration::canAccess()` checks its own key.
- **`StorefrontSetting` is the Brand DNA source**, read-only. `normalizedThemeColor()` and the palette helpers give validated hex colors, `typography_heading_font` / `typography_body_font` pick the fonts, and `logo` / `logo_dark` supply the mark. The model already has safe defaults, so an unconfigured company still renders.
- **`ProductReview`** (company-scoped, `approved()` scope, linked to `order_id` for verified purchases) supplies testimonials. Only `approved` reviews appear in the picker.
- **`intervention/image` (`^3.9`, already used by `ImageOptimizerService`)** does the compositing: place the product photo, draw colored panels, badges and text, then export. No headless browser, no FFmpeg, and no new heavy dependency (same reasoning as doc 12 §1.1).
- **Review gate**: `ImageGovernanceService::roleRequiresReview()` stays the single source of truth for which roles need approval (§2.7). Ad creatives get the same `review_status` columns as `GeneratedImage` (§7.2).
- **Monthly caps don't apply** (§2.8). `ImageGovernanceService`'s cap counts only `generated_images` rows with `tool = image_generation`, so ad creatives in their own table are excluded automatically.
- **Attach pipeline**: a new `AdCreativeAttacher` mirrors `GeneratedImageAttacher` (`attachToProduct` / `attachToOffer`, same-company check, review check). It's a separate class because `GeneratedImageAttacher` is typed to `GeneratedImage`, and an ad creative's source data (template, text fields, brand snapshot, testimonial provenance) doesn't fit that model's "one AI call → array of outputs" shape. Doc 12 §3.1 gives the same reason for keeping `GeneratedVideo` separate. Once an ad is attached to `Product::$image`, `MetaAdsCreationService` uses it unchanged.
- **Font files (new asset, Phase 1 blocker)**: the `FONT_OPTIONS` fonts are loaded only as Google Fonts CSS in the browser (`googleFontsUrl()`). Server-side text rendering needs local font **files**, so each pairing's heading and body font must be bundled as TTF/OTF under `resources/fonts/`. Google Fonts are open-licensed; confirm each family's license (§10). The "System" pairing has no concrete font file, so it needs a bundled fallback: Inter for Latin and Noto Sans Bengali for Bangla.

## 5. Filament structure

| Type | Path | Purpose |
|---|---|---|
| Page | `app/Filament/Pages/ContentCreation.php` (view `filament.pages.content-creation`) | The tool: product/offer picker, template picker, per-template text fields, testimonial picker, read-only brand preview, aspect-ratio picker, render action, gallery, attach actions. Same shape as `ImageGeneration.php`. |
| `ToolMenu::TOOLS['content_creation']` | `app/Filament/Pages/ToolMenu.php` | `page => ContentCreation::class`; description updated (e.g. "Build on-brand static ad creatives from your products. Copy drafting coming soon."). The class docblock and `TOOLS` comment that say Content Creation is unbuilt get updated. |
| Review queue | inside the Content Creation page (reviewer-only section) | Approve/reject pending creatives, the same interaction Image Generation offers reviewers. |

No new settings page: Brand DNA lives on the existing storefront settings, and the review roles on the existing Image Governance page.

## 6. Permissions

| Key | Gates | Change |
|---|---|---|
| `ai_tools.content_creation` | Using the tool (build + attach) | Already exists. Relabel in `User.php` from "(reserved — not built yet)" to "AI Tools: Content Creation (ad creatives + attach)". Grant it to `manager` in `User::ROLE_PERMISSIONS`, matching how `manager` already holds `ai_tools.image_generation` and `ai_tools.video_generation`. |
| `ai_tools.image_generation.review` | Approving other users' pending generations | **Reused** for ad-creative review, since the owner chose one shared governance rule (§2.7). Relabel to "AI Tools: approve others' generated images & ad creatives". |

Update `docs/roles-and-permissions.md` to match.

## 7. Data model

### 7.1 Template registry (code, not a table)

`app/Services/AdCreative/AdCreativeTemplateRegistry.php` defines each template's regions parametrically (photo slot, text boxes, badge, logo corner, color roles), so one definition renders at every aspect ratio. Each template also declares its text fields, their max lengths, and which color roles (primary / secondary / accent) it uses.

| Key | Owner's template group | Text fields |
|---|---|---|
| `headline` | Headline + Offer badge | headline, subline (optional) |
| `offer_badge` | Headline + Offer badge | headline, badge label (e.g. "২০% ছাড়"), price shown from the product record |
| `testimonial` | Testimonial / Review | quote, name, star rating (from the review, or entered manually) |
| `before_after` | Before/After + Us vs Them | before label, after label, plus a second image (uploaded through the existing `OptimizesUploadedImages` pipeline) |
| `us_vs_them` | Before/After + Us vs Them | our label, their label, up to 4 comparison rows (✓ / ✗) |
| `listicle` | Listicle | title, 3–5 point lines |

### 7.2 New table: `ad_creatives`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | FK, indexed | `BelongsToCompany` |
| `user_id` | FK | who built it |
| `template_key` | string | key into the registry |
| `source_type` / `source_id` | morphs | the `Product` or `Offer` it was built for |
| `aspect_ratio` | string | `GeneratedImage::ASPECT_RATIOS` key |
| `language` | string | `bn` \| `en`, chooses the font pairing's script coverage and fallback |
| `text_fields` | json | the template's field values, as entered |
| `secondary_image_path` | string, nullable | Before/After's second image |
| `testimonial_source` | string, nullable | `review` \| `manual` (testimonial template only) |
| `product_review_id` | FK, nullable | set when `testimonial_source = review` |
| `brand_snapshot` | json | palette, font keys and logo path used at render time, frozen so a later brand change doesn't misdescribe old renders |
| `output_path` | string, nullable | the rendered file (one output per render) |
| `review_status` / `reviewed_by` / `reviewed_at` / `review_note` | as on `generated_images` | set to `pending` at creation when the creator's role is in Image Governance's review roles, otherwise `not_required` |
| `attached_type` / `attached_id` | nullable morphs | where it was attached |
| `status` | string | `completed` \| `failed`. Rendering is synchronous (local compositing, no external call) |
| `error_message` | text, nullable | |
| `created_at` / `updated_at` | timestamps | |

`app/Models/AdCreative.php` uses `BelongsToCompany` + `CompanyScope` and **must** be added to `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract` next to `Media::class, GeneratedImage::class, GeneratedVideo::class`, per `CLAUDE.md`. Choosing a `product_review_id` must also be validated as same-company and `approved`.

## 8. New service classes

```
app/Services/AdCreative/
  AdCreativeTemplateRegistry.php  // template definitions (§7.1)
  BrandDnaResolver.php            // StorefrontSetting -> palette, font file paths per language, logo path; safe defaults
  AdCreativeRenderer.php          // intervention/image compositor -> stored WebP/PNG via CompanyStorageService
  AdCreativeAttacher.php          // attach to Product (featured/gallery) / Offer (cover); same-company + review checks
  AdCreativeReviewService.php     // pending/approve/reject; reads ImageGovernanceService::roleRequiresReview(); writes AuditLogService entries
```

Finished renders are registered in the Media Hub (`Media::recordUpload`, the same thing Image Generation does), so they can be selected from any "Select From Media" field.

## 9. Phased rollout

- **Phase 0 — Bangla rendering spike (first, before anything else).** Render one real Bangla headline with a conjunct-heavy phrase (e.g. "স্বাস্থ্যকর ত্বকের জন্য সেরা ক্রিম") in Hind Siliguri and Noto Bengali through `intervention/image`, then check it visually. If the GD driver breaks the shaping, test the Imagick driver, then confirm the `imagick` extension exists in the production image (Coolify/nginx deployment). The result decides the rendering driver for everything after it. See §10.
- **Phase 0 result (2026-10-03).**
  - **GD fails.** PHP GD (bundled 2.1.0-compatible, FreeType) breaks Bangla with Shonar, Vrinda and Nirmala UI: the hasanta (্) shows as a separate glyph, so conjuncts like স্বাস্থ্য and ত্বক come out unjoined, and ি / ে land on the wrong side of the consonant (কিছু, সেরা). `intervention/image`'s GD driver can't be used for Bangla text.
  - **Imagick works locally.** With PECL imagick 3.8.1 (ImageMagick 7.1.1-46, bundled harfbuzz + fribidi + pango) both `Imagick::annotateImage()` with a font file and the `pango:` coder rendered every test line correctly: স্বাস্থ্যকর ত্বকের জন্য সেরা ক্রিম, কিছু, ক্ষ, র‍্য and ৳১,২৫০. A browser `<canvas>` also shaped everything correctly, so it stays the fallback.
  - **Not yet proven: production.** The test ran on a Windows build loaded from a scratch folder (Laragon's PHP and php.ini untouched). The Nixpacks/Coolify image needs `ext-imagick` and an ImageMagick built with harfbuzz/raqm, and neither is confirmed. Local Windows results don't predict that build.
  - **Resulting direction:** renderer = Imagick (`annotateImage` with bundled TTFs), guarded by a capability check that tells the admin plainly when the extension or shaping support is missing, instead of silently drawing broken Bangla. Browser-canvas compositor is the fallback if production can't get a shaping-capable ImageMagick. Adding `ext-imagick` to `composer.json` `require` would break `composer install` locally and in GitHub Actions (`deploy.yml` lists no imagick), so it goes under `suggest` unless the owner approves a CI and Laragon change.
  - **Diagnostic command:** `php artisan ad-creative:check-rendering [--font=path] [--render]` (`App\Services\AdCreative\TextRenderingCapability`). It checks GD, the Imagick extension, the ImageMagick `raqm` delegate, and a shaping probe: ক্ষ must draw at most 0.60 of its unshaped width (measured 0.43 when shaped, 0.81 with GD). It exits non-zero when the server isn't ready. `--render` writes `storage/app/diagnostics/ad-creative-rendering.png` for a visual check. Phase 1's renderer reuses `supportsBangla()` for the admin-facing guard. Locally, the Windows imagick build passed every check.
  - **Font + Imagick added (2026-10-03, owner-approved).** Hind Siliguri Regular/Bold (OFL, Indian Type Foundry) bundled at `resources/fonts/ad-creative/` with `OFL.txt`. It shapes correctly through Imagick (ratio 0.51). `ext-imagick` was added to `composer.json` `require`, because that's the only way Nixpacks installs a PHP extension, and to the CI `setup-php` extension list. Local machines without imagick need `composer install --ignore-platform-req=ext-imagick`; the app itself still boots, because Composer's generated `platform_check.php` doesn't check extensions here. **Still open:** after the next deploy, run `php artisan ad-creative:check-rendering --render` in the Coolify terminal to confirm the Nix ImageMagick build has `raqm`.
- **Phase 1 — Foundation.** Bundle the font files; add the `ad_creatives` migration, the `AdCreative` model and its isolation-test registration; relabel and grant permissions in `User.php` and the roles doc; add `BrandDnaResolver`, the registry with the first two templates (`headline`, `offer_badge`), `AdCreativeRenderer`, and the Content Creation page with manual entry and gallery. Flip the Tool Menu card live.
- **Phase 2 — Attach + review.** `AdCreativeAttacher` and `AdCreativeReviewService`, the reviewer queue on the page, and audit-log entries. Attaching is what connects the tool to the storefront and Meta, so it should follow Phase 1 closely.
- **Phase 3 — Remaining templates.** `testimonial` (with the approved-review picker and manual entry), `before_after`, `us_vs_them`, `listicle`.
- **Phase 4 — AI copy drafting** (owner decision §2.3). Draft headline/text-field variants via `AiLlmClient`, following the existing per-company "bring your own key" config convention. The user always edits or accepts a draft explicitly, and prices stay record-sourced. This phase is also where to revisit a monthly cap (§2.8) and add caption and product-description copywriting to Content Creation.
- **Later, separate plan documents:** UGC avatar video, competitor ad spy.

## 10. Risks and open engineering questions

- **Bangla text shaping is the main technical risk.** GD's `imagettftext` draws glyphs one by one and doesn't do complex-script shaping, so Bangla conjuncts (যুক্তাক্ষর) and reordered vowel signs (ি, ে, ৈ) can come out broken. Imagick can shape correctly when its text rendering goes through Pango, but that depends on how ImageMagick is built on the server. Phase 0 exists to answer this before any template work. If neither driver shapes Bangla correctly in production, the fallback is to pre-render text layers through a shaping-capable path (e.g. an SVG text layer rasterised by a Pango/HarfBuzz-backed renderer). That would be a larger change and should go back to the owner before starting.
- **Font licensing**: confirm each bundled family's license allows redistribution in the repo (Google Fonts families are generally OFL; verify each one).
- **Manual testimonials** (owner decision §2.5): `testimonial_source = manual` is stored on every creative and shown in the gallery and reviewer queue, so it's always clear which quotes came from a verified purchase and which were typed in.
- **Testing**, per `CLAUDE.md`: renderer, attacher and review tests create their own records under `RefreshDatabase` and run with plain `php artisan test` (no `--env` flag). They never touch demo data. Phase 4's LLM calls must be HTTP-mocked, following the existing `AiReplyService` / Prompt Enhancer test precedent.
- **Release notes**, per `CLAUDE.md`: every phase's commit needs its `CHANGELOG.md` entry and an `UPDATE_NOTES.md` update, and nothing is committed or pushed without the owner's explicit approval.

# Agent Rules (Claude Code)

These rules are mandatory for any AI agent working in this repository.

## Release notes are part of every commit

- **Before any `git commit` / `git push`, write the release note first.** Add or update the entry in `CHANGELOG.md` describing the change (follow `docs/release-policy.md`: categorize as major, minor, patch, security, hotfix, or maintenance, with Added/Fixed/Security/Technical Notes sections). A commit without a matching CHANGELOG entry is not allowed.
- Also keep the working log in `UPDATE_NOTES.md` current — it describes what a pending commit contains, ending with the commit status line.

## Commit policy

- Never `git commit` or `git push` without the owner's explicit approval in the conversation.
- Never run destructive commands (`migrate:fresh`, broad seeders) against non-demo databases.

## Business rules

- Never invent demo/placeholder business rules — real rules only, gathered from the owner. External credentials (payment, SMS, WhatsApp, WooCommerce) are always admin-configurable encrypted settings fields; the owner plugs in keys.
- Storefront-visible content must be manageable from the Filament admin panel, never hardcoded in views.

## WhatsApp Web (QR) channel rule

- Any work on WhatsApp channels with `connection_type = 'web'` (the `whatsapp-bridge` service) must follow `docs/whatsapp-web-rules.md`: the archive is never deleted; sending is off by default and only allowed as a staff reply to a customer who messaged first; no bulk, broadcast, follow-up or AI sending; honour opt-out; admin-set rate limits; stop on ban/logout; no SMS fallback.

## Bilingual (Bangla + English) rule

- Follow `docs/i18n-bangla-english-plan.md` (section 0 applies to every task, not only i18n work).
- Every new or changed user-visible string goes through `__('English text')` and is added to **both** `lang/en.json` and `lang/bn.json` in the same commit, with identical `:placeholders`. `TranslationParityTest` enforces this.
- Never hardcode Bangla in Blade/PHP (only in `lang/bn.json`); admin-entered content stays admin-managed and is translated per the plan's Phase 4, not in lang files.
- If a correct Bangla translation is uncertain, still add the key (English text as the value) and list it under "Needs Bangla review" in `UPDATE_NOTES.md`.

## Multi-company isolation

- Every new company-owned model must use `BelongsToCompany` + `CompanyScope` and be added to `MultiCompanyIsolationTest::test_every_company_owned_model_uses_the_company_scope_contract`.
- `SetCurrentCompany` must stay pinned before `SubstituteBindings` in `bootstrap/app.php`; new queued jobs set/clear `CompanyContext` explicitly; scheduled commands loop per company.

## Test runs must never touch demo/dev data

- Running tests must NEVER refresh, reseed, or otherwise modify the demo/development database.
- **Always run tests as plain `php artisan test` (or `--filter=...`) with NO `--env` flag.** Verified 2026-07-05: `php artisan test --env=testing` bypasses `phpunit.xml`'s env overrides and runs `RefreshDatabase` against the real `database/demo.sqlite` (env=local, file cache) — wiping demo data. Plain `php artisan test` correctly uses the in-memory sqlite + array cache + testing env from `phpunit.xml`.
- Never run `demo:refresh`, `db:seed`, `migrate:fresh`, or any seeder as part of testing or verification. The only place demo refresh may execute is inside the test suite's own isolated-database test (`DemoDataSeederTest`), which must keep using a disposable database.
- If a test needs data, it creates its own records under `RefreshDatabase`; never rely on or mutate seeded demo data.

## Verification before handoff

- Run the affected test files plus the full `php artisan test` suite, and `npm run build` when frontend assets change.

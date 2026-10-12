# FTP Hosting Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for the coordinator;
> delegate independent modules with explicit file ownership. Check each module
> and then obtain an independent review of the complete distribution.

**Goal:** Deliver CSV Studio for `motorworld.it/csvstudio/` through FTP with
private login, persistent archives and all existing CSV behavior.

**Architecture:** Keep React's models and implement a PHP service with bounded
checkpointed jobs and SQLite v2. Bundle production assets and PHP dependencies
so the hosting needs only HTTPS/PHP and writable private storage.

**Tech Stack:** PHP 8.1+, PDO SQLite/JSON, mbstring/iconv, bundled Brick Math
0.12.3 and Unicode normalizer polyfill, React/TypeScript/Vite, Python pytest
differential runner, Apache/PHP Docker fixture.

**Spec:** `docs/superpowers/specs/2026-10-12-ftp-hosting-design.md`.

## Global Constraints

- No credentials, installation keys, supplier CSVs or private archives in Git/releases.
- Data outside the real public webroot; HTTPS, authenticated downloads and CSRF.
- Decimal precision 28; NFC title 80; UTF-8 BOM/semicolon/CRLF; threshold 15,000,000 bytes.
- PHP distributions include all required code; no installer dependency downloads.
- Python defaults continue working when window.MW_HOSTING is absent.
- Stage changes and spool files; atomically publish completed results.

## Review Focus

- Setup visited before the owner: cannot claim the account without private FTP key.
- Two tabs/process death: checkpoints prevent duplicate rows and partially published state.
- Nested deployment: all uploads, logo, assets and downloads stay under the correct prefix.
- Unicode and decimal boundaries: exact identities, title counts, ,90 prices and 20% diagnostics.
- Huge/multiline CSV and reimports: bounded memory, stable row provenance and manual corrections.

## Interfaces and ownership

PHP namespace is `MotorWorld\Hosting`; models are associative arrays matching
the existing JSON. Bootstrap autoloads `hosting/php/src/<Class>.php` and bundled
vendor namespaces. Shared helpers are `Support::uuid()`, `Support::now()`,
`Support::diagnostic(level,code,message,extra=[])`, `Support::json(array)`;
`AppError(code,message,status=422)` and `NotFound` signal API errors.

`Store(string $dataDir)` exposes existing Repository snake_case methods with
array inputs/outputs, plus `pdo(): PDO` and `dataDir(): string`. Staging tables
are additive. `JobService(Store)` exposes `register(kind, callable)`,
`submit(kind,payload,requestKey): array`, `get(id): array`, `step(id): array`,
`resume(id): array`. Handler signature is `(array $job, array $checkpoint): array`
with `done`, `checkpoint`, `progress`, optional `result`. Checkpoint persistence
is internal to JobService and is not appended to the Job's top-level model.

`ImportService(Store)::inspect(path,encoding=null,delimiter=null): array`,
`ImportService::step(job,checkpoint): array`; `ExportService(Store,resources)::step`
uses the same handler contract. `DraftCompiler(resources)::compile(product,fits,
priceSettings,profile,catalog,rules,overrides): array`; `Pricing::calculate(cost,
settings,override=null): array`; `Catalog(resources)::load(): array`,
`Catalog::normalizeKey(string): string`, `Catalog::resolve(...): array`.
Validation job handler is wired by the coordinator using staging and compiler.

### Task 1: Domain parity (independent)

Files: `hosting/php/src/{Pricing,Catalog,DraftCompiler,Descriptions,Defaults}.php`,
`hosting/php/tests/domain.php`, `scripts/test_php_domain.py`, bundled licenses.

- [x] Generate reference fixtures from real Python pricing/compile/title/category cases.
- [x] Run PHP fixture runner and observe missing implementation failures.
- [x] Implement exact decimal, NFC identity/title/category and description behavior.
- [x] Compare final/minimum prices, diagnostics, identities and CSV field snapshots.
- [x] Run full domain fixture suite, including 80/81 emoji and price boundaries.

### Task 2: Storage and jobs (independent)

Files: `hosting/php/src/{Store,JobService}.php`, `hosting/php/tests/storage_jobs.php`.

- [x] Write failures for SQLite v2 reopen, manual corrections, duplicate intent and concurrent step.
- [x] Port Repository methods and additive staging/checkpoint tables.
- [x] Implement flock/transaction checkpoint service, preserving GET purity.
- [x] Prove process interruption restores confirmed checkpoint, without resetting other workers.
- [x] Verify reimport, stale flags, immutable exported state and path-independent downloads.

### Task 3: Import and export (independent)

Files: `hosting/php/src/{ImportService,ExportService,CsvWriter,ZipWriter}.php`,
`hosting/php/tests/import_export.php`, `scripts/test_php_csv.py`.

- [x] Write differential cases for quoted multiline source rows, conflicts and joins.
- [x] Implement progressive inspection/import staging and atomic final publication.
- [x] Write byte fixtures and 15 MB/tie/single-record failures before encoder/split code.
- [x] Implement bounded spool export, immutable artifacts/reports/snapshot and streaming ZIP.
- [x] Verify partial export → correction → second export with original bytes unchanged.

### Task 4: Hosting entry, authentication and installer (coordinator)

Files: `hosting/php/src/{Support,AppError,Auth,Requirements,Installer,Router,Application}.php`,
`hosting/php/public/{index,api,setup,logout}.php`, `hosting/php/tests/http_test.py`.

- [x] Test unopened setup, missing private key, CSRF/auth bypass and unsafe filesystem paths.
- [x] Implement setup with private random key, atomic one-time config and owner credentials.
- [x] Implement sessions/login/rate limits and API/router validation with generic errors.
- [x] Wire job handlers and route all originals/downloads through authenticated endpoints.
- [x] Run HTTP isolation and session concurrency checks with private directory outside webroot.

### Task 5: Frontend adapter and delivery (coordinator with optional delegation)

Files: `frontend/src/api.ts`, affected download/logo pages, `frontend/src/App.tsx`,
`frontend/src/tests/hosting-api.test.ts`, `scripts/package_php_hosting.py`,
`docs/ftp-hosting.md`, Apache fixture and PHP E2E runner.

- [x] Test nested PHP URLs, CSRF multipart header and step-based persisted job recovery.
- [x] Add deployment adapter without changing Python defaults or the user workflow.
- [x] Build with relative assets and generate ZIP with public app/private empty data directory.
- [x] Verify contents contain no secrets, ignored data or development dependencies.
- [x] Run packaged HTTP/CSV E2E, Python/React suites and independent security/parity review.
- [x] Publish only verified source/code package and give minimal FTP setup instructions.

## Verification result (2026-10-12)

Domain parity: 581 cases. CSV/import/export parity and integration: 81 checks, with independent Python ZIP CRC/artifact verification. PHP storage/jobs: 198 behavioral checks. Python regression: 75 tests; React: 33 tests; Python browser workflow: 1 test. Actual FTP ZIP was installed in HTTPS Apache/PHP 8.3 and verified in Chromium across all six views, upload, cooperative jobs, partial export, protected downloads and logout/login. Independent security/parity review findings were corrected and reverified.

Publication and live installation are separate: release only the verified production ZIP; installing on motorworld.it and checking its real PHP configuration still require the hosting panel/FTP. No supplier archives or credentials are included in the public package.

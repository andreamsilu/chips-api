# Implementation verification — 8 November 2026

- PHP 8.5.12 used locally; application requirement is PHP 8.4+.
- Laravel 13.40.0, Sanctum 4.3.3, Reverb 1.12.0 and Larastan 3.14.0 installed through Composer with an updated lockfile.
- 54 versioned API routes registered. The original welcome route is preserved.
- Full PHPUnit run: **34 passed, 719 assertions**. Later payment-acknowledgement/reconciliation hardening also passed its four targeted tests.
- PHPStan/Larastan level 5: **zero errors**, without an error baseline or ignored findings.
- Pint formatting completed; Composer metadata validates; dependency audit reports **no known vulnerability advisories**.
- Generated OpenAPI 3.1 document passes **openapi-spec-validator**. Tests also check registered route coverage, generated-document consistency and all schema references.
- Two real MySQL process-concurrency tests are provided for same-key replay and last-item overselling. They were **skipped locally**, because the available local MySQL credentials were denied and the Docker daemon was unavailable. They refuse to reset databases unless the connection is MySQL and the database name ends in `_test`.
- CI configuration covers PHP 8.4/8.5 with disposable MySQL 8 and Redis services. It has not been executed remotely.

No production database was migrated. No real charge, refund or settlement transfer was sent. Snippe HTTP calls are covered by exact test fixtures; live/sandbox merchant certification and credentials are outstanding. Redis worker crash recovery, Reverb socket delivery, production-like load/query plans and backup restoration remain operational release gates. See [operations](operations.md) for setup, recovery procedures and client integration limits.

## Flutter integration verification — 9 November 2026

- Live Flutter adapter and password authentication implemented; demo mode is explicit opt-in. Server quote and expected_total guard added, with owner-scoped profile/menu endpoints.
- Backend suite: 43 passed, 805 assertions. PHPStan: zero errors. Pint completed. Generated OpenAPI passes openapi-spec-validator.
- Flutter transport/recovery tests cover same-key interrupted checkout replay, origin isolation, stale-session responses, pagination and pending-payment presentation. Release web compilation succeeds with an HTTPS API URL.
- MySQL application login is still denied locally; migrations and a live database/provider end-to-end run remain unverified. No real Snippe payment was sent. Two MySQL concurrency tests still require their disposable test service.

- Final Flutter suite: 32 passed; flutter analyze reports no issues. Additional regressions ensure secure-storage failures prevent every payment submission attempt and invalid phone validation allows correction.

## NextSMS verification — 9 November 2026

- NextSMS v2 customer order alerts implemented with encrypted delivery records, queued sending, atomic claims and scheduler recovery. Provider fixtures cover acceptance, rejection, missing configuration, oversized numeric IDs, invalid recipient data, obsolete snapshots, uncertain outcomes and bounded rate-limit retries.
- Full backend suite: 57 passed, 865 assertions, including 14 NextSMS tests. PHPStan: zero errors. Pint completed. No dependencies or public API endpoints were added.
- The additive sms_deliveries migration passes in the isolated SQLite feature-test database. Live MySQL migration remains dependent on repairing the existing local MySQL login. No live SMS was sent and no merchant token/sender was configured.

# Request Inspector 0.9.0 release candidate

Updated 2026-09-09. Author: Hammad Farooq Meer, https://hammadmeer.netlify.app. License: GPL-2.0-or-later. No public submission has occurred.

## Delivered scope

| Phases | Implemented behavior |
|---|---|
| 0–1 | Requirements traceability, site-scoped schema, lifecycle, capability checks, secure settings, redaction, bounded storage, retention and uninstall policy |
| 2–3 | WordPress HTTP API capture, authenticated REST endpoints, Explorer filters, details and structured body inspection |
| 4–5 | Incoming request context, targeted recording, SQL timings/duplicates, PHP diagnostics and sanitized attribution |
| 6–7 | Hook occurrence/registration inspection, timeline, diagnostic links, JSON/HAR/SQL/body exports and cURL copying |
| 8 | CIDR/proxy controls, restricted redaction patterns, adaptive sampling, audit, guarded staging replay and restricted synthetic EXPLAIN |
| 9 | Runtime/browser fixtures, concurrency and multisite checks, benchmark tooling, CI definition, source-inclusive deterministic packaging |
| 10 | Saved scopes, existing-user sharing, comparison, component reports, regression review and WP-CLI commands |

The user's React component/hook refactor and logo are retained. The final UI adds consistent button/control alignment, branded skeletons, background refresh indicators, independent list/statistics rendering, lightweight polling and SPA handling of native plugin sidebar links. Initial and React logo rendering share the same URL. The original high-resolution PNG is preserved; no image-size reduction or measured page-load speedup is claimed.

## Verification evidence

- TypeScript and production build validated during implementation.
- WordPress coding standards / PHPCompatibility checks pass.
- PHPStan level 5 passes with a 512 MiB analysis limit.
- Official WordPress Plugin Check reports no errors on the local source plugin.
- Existing unit suite: 25 tests / 58 assertions passed before the final frontend-only adjustments.
- Final backend integration: 64 assertions pass on WordPress 7.1 / PHP 8.2 / MariaDB 10.6. The intentionally emitted synthetic PHP warning verifies native handler behavior.
- Earlier compatibility run: 64 integration assertions each on PHP 8.1, 8.2, 8.3, 8.4 and 8.5; WordPress 6.6 integration also passed. This matrix predates final metadata and frontend adjustments.
- Earlier concurrency checks retained exactly seven next-N claims across eight workers and admitted nine rows under a ten-row cap.
- Earlier multisite suite: 13 assertions passed, including new-site initialization, isolation and opt-in uninstall.
- Earlier Chrome workflows passed capture, inspector tabs, export, preview and saved scopes. Final UI browser result is recorded in the implementation status.

The CI workflow is provided, not remotely executed. Existing benchmark results in `BENCHMARK.md` are workstation fixture measurements made before the final changes; they are not a performance guarantee. No additional full matrix or benchmark rerun was performed after the user requested prioritizing development.

## Package verification

The final archive contains 62 files. Its paths, runtime, React source, lockfile, logo and license were checked. The extracted plugin loaded in the isolated WordPress fixture and returned HTTP 200 from its authenticated settings API. Two consecutive builds produced identical SHA-256: `78fe2088d3745d680deaebd16ae3a881196f2b9a3a1db573eac32d2995a95198`.

The final Chrome workflow also verifies that the native plugin sidebar preserves the current document. Capture, database/hooks/timeline/body tabs, JSON export, advanced redaction preview and saved scope creation pass. Final production compilation completes without warnings.

## Explicit limits and submission follow-up

Callback execution timing remains unavailable: callbacks are not wrapped. Cache/GraphQL/Query Monitor detection does not invent unavailable metrics. HAR phase timings are labelled projections. General output buffering, browser-network capture, public sharing and remote collaboration services are outside the implemented local contract. Saved scopes do not pin data past retention.

Firefox/WebKit browser downloads timed out; Safari, Linux, MySQL 8 and persistent-cache/profiler coexistence remain unverified. Failure injection and production overhead are not exhaustively covered. Restricted EXPLAIN currently needs the MariaDB timeout adapter.

The user authorized use of the supplied logo, which is included. Confirm artwork redistribution rights and a real WordPress.org contributor username before public submission. GPL metadata and author credit do not guarantee directory acceptance.

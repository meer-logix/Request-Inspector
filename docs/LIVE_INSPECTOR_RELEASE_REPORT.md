# Request Inspector 0.10.0 release candidate

This release adds Live Inspector inside the existing Request Inspector plugin: one entry point, namespace, settings system, recorder, upgrade/uninstall lifecycle and release ZIP. Query Monitor 4.0.7 and the supplied rendered markup informed the feature design; its runtime, assets and drop-in are not redistributed.

The feature includes all 17 toolbar panels, exact-document identity, temporary private snapshots, per-account preferences, independent history policy, lazy React docking, logo/skeleton feedback, filters, timeline event links and related-hook subviews. Context/resource collectors, explicit timer/log APIs and opt-in transient/capability observers share bounded sanitization and recorder budgets. See [usage and API documentation](LIVE_INSPECTOR_GUIDE.md) and [implementation status](../IMPLEMENTATION_STATUS.md).

## Focused verification

- WPCS and PHPCompatibility passed for the plugin PHP source.
- PHPStan level 5 passed with a 1 GiB analysis limit.
- TypeScript and the production Webpack build passed.
- Official WordPress Plugin Check reported no errors for the local source plugin.
- Existing backend integration passed all 64 assertions on the isolated WordPress 7.1 / PHP 8.2.27 / MariaDB 10.6 fixture. The synthetic PHP warning intentionally verifies native handler behavior.
- Chrome exercised the integrated toolbar/dock, all diagnostic tabs, unchanged document identity during navigation, two-tab UUID separation, denial of mismatched and anonymous tickets, secret redaction, mobile modal behavior and no history rows in live-only mode.
- The focused coexistence workflow passed with the supplied Query Monitor 4.0.7 active: one toolbar per plugin, independently opened QM/RI panels, and no Query Monitor DB drop-in installation. Initial runs hit a short assertion timeout and then the isolated PHP server's execution timeout while loading QM; the subsequent run passed. This is functional evidence, not a profiler overhead benchmark.

The final browser pass covered the Request and Admin Screen panels as well as the other tabs. The iframe exclusion is checked by source/type/build validation; an exhaustive iframe/browser matrix was not run. The last internal-generation lookup guard was checked with WPCS and the extracted-package API smoke after the browser pass. Related-hook content is an on-demand view of existing retained hook observations and shares their availability limits.

## Package and performance

The package includes readable PHP/TypeScript/CSS, build configuration, lockfiles, WordPress dependency manifests, the lazy dock chunk, RTL styles, the supplied logo, licenses and the Live Inspector guide. Development fixtures, tests, dependencies and the Query Monitor reference remain outside the archive.

Live mode stays disabled by default. The toolbar loads a small bootstrap and obtains one summary with bounded pending retries. Dock code and panel data load on demand; there is no continuous polling. A bounded in-memory data cache avoids repeat panel fetches. The 100-row/24-KB panel limits and 256-KiB snapshot limit are enforced independently of UI pagination.

Final gzip sizes: toolbar JS 3,495 bytes, toolbar CSS 438 bytes; lazy dock JS 5,867 bytes and CSS 1,931 bytes. These figures exclude WordPress dependencies and the supplied logo. Both proposed compressed bundle budgets are met. The proposed 5-ms median / 10-ms p95 PHP overhead targets were not measured for this release. No zero-overhead or production performance claim is made. The dock uses one small lazy code chunk rather than separate chunks for every panel.

The final ZIP contains 81 files. Archive path checks, required assets/source/licenses, exactly one current lazy dock chunk, RTL styles, extracted-plugin bootstrap and authenticated settings/live-preference APIs passed. Two consecutive package runs produced identical SHA-256:

`ef5470820c45856b2c3b958ae0eca6f40cf59bd6fb0722c3ce17bb62ae52cbb7`

Artifact: `dist/request-inspector-0.10.0.zip`; checksum: `dist/request-inspector-0.10.0.zip.sha256`.

## Limits and submission follow-up

Callback execution timing, arbitrary page-output capture, browser network traffic and unverified persistent-cache adapters remain unavailable. Server measurements stop at the named recorder boundary. SQL time can cover fewer queries than the connection count. Translation candidates do not prove successful loading, registry state does not prove script execution, and capability filter observations do not prove final authorization.

The new live feature has not undergone the earlier release's complete WordPress/PHP/multisite matrix. Safari/WebKit, Firefox, Linux, MySQL 8, persistent-cache integrations, aggressive full-page caching, real production load and broad failure injection remain unverified. Earlier phases 0–10 evidence is preserved in [the 0.9.0 report](RELEASE_REPORT.md); it is not silently reused as final live-feature evidence.

GPL-2.0-or-later and author credit are applied. The supplied logo is included at the user's request. Confirm a real WordPress.org contributor username and GPL-compatible artwork redistribution rights before public submission. Plugin Check passing is not a guarantee of directory acceptance. No public submission or production deployment was performed.

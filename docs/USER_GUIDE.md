# Request Inspector user guide

Request Inspector stores local, sanitized WordPress diagnostics. Install the release ZIP through **Plugins → Add New → Upload Plugin**, activate it, and open **Request Inspector → Settings**. Recording starts disabled. PHP 8.1+ and WordPress 6.6+ are required. No external service, telemetry account or remote font is used.

## Diagnose, inspect, fix and compare

1. On a development or staging site, select a recording mode and enable recording. Request/response bodies and detailed collectors require separate opt-in.
2. Reproduce the issue. Use Explorer to filter requests by URL, method, component, execution context, direction, status or elapsed time.
3. Open a request. Inspect headers, structured bodies, related HTTP calls, SQL, PHP diagnostics, hook registrations and the timeline. Availability markers distinguish missing measurements from zero activity.
4. In **Sessions & Reports**, start a named saved scope before reproducing a baseline workload, then stop the scope. Make the fix and record a second scope.
5. Compare 2–5 request IDs, or run regression review against baseline and candidate scope IDs. Different routes, environments, sampling and collector policies are not equivalent workloads. Regression review requires at least ten comparable, nonoverlapping retained samples per scope. It produces a review signal, not an automatic notification or proof of causation.
6. Disable recording and purge diagnostics when finished.

Saved scopes identify persisted captures between explicit row boundaries. They do not enable recording, duplicate captures or pin data beyond global retention. At most twenty scopes are retained, expiring within seven days or the configured capture retention, whichever is shorter. Sharing applies to saved scope access and requires existing local diagnostic viewing permission. Administrators can revoke sharing. There are no public trace URLs or remote team services.

## Recording modes

| Mode | Retention behavior |
| --- | --- |
| Disabled | No recorder starts. |
| All | Retain eligible observed executions. |
| External HTTP | Retain outgoing WordPress HTTP API calls with a minimal incoming parent context. |
| REST/AJAX | Retain executions recognized as REST or AJAX. |
| Errors | Retain candidates with observed HTTP failures, status failures or PHP issues. |
| Slow | Retain candidates exceeding the configured elapsed threshold. |
| Component | Require observed matching component attribution. Unknown ownership is not a match. |
| Next N | Atomically reserve a bounded number of root captures; reservations expire after ten minutes. |
| Current target | Arm one future request using a short-lived token; this cannot retroactively capture an already-rendered page. |

Sampling is random and independent of visitor identifiers. Adaptive sampling, when enabled, reduces the configured sampling percentage tenfold above 80% storage pressure. Reports disclose the effective sample percentage. Error/slow modes still incur candidate collection overhead.

## Coverage and limits

- Collection begins after normal plugin loading. Earlier bootstrap work, cached pages bypassing PHP and browser-side requests are not covered.
- HTTP durations are observed WordPress HTTP API elapsed spans. Nonblocking requests have dispatch-only timing. Unsupported or unmatched completion paths are dropped rather than assigned invented durations. Redirects are observed as one HTTP API operation.
- Database timings require externally enabled `SAVEQUERIES`; the plugin never enables global query logging or replaces `$wpdb`. Global WordPress query logging has memory overhead independent of this plugin. SQL values/comments are removed; exact duplicate groups use request-local keyed digests. Sanitized query text may not be executable.
- General HTML, streaming, downloads and arbitrary output buffers are not intercepted. Body capture supports bounded JSON and URL-encoded data, with explicit omission/truncation reasons. Login bodies, unsupported objects and unsafe payloads are omitted.
- Hook occurrences are observed invocations. Callback registrations are a snapshot at first observation, capped at twenty callbacks per hook and one hundred distinct hooks. Registered callbacks are not necessarily executed. Callback duration, exclusive CPU time and original fatal stack frames are unavailable.
- PHP observation preserves the previous error handler and the host reporting/suppression policy. Source snippets and callback arguments are omitted.
- Request-local telemetry has a 2 MiB admission budget, at most 1,000 retained events/HTTP records, one hundred pending HTTP operations, bounded structured text and per-body limits. Dropped counts are visible. Incoming body metadata adds separately bounded space.
- Timeline bars are inclusive elapsed spans and can overlap. They must not be summed as exclusive CPU time. The first one hundred diagnostic events are drawn; diagnostic tabs provide the full retained list. DNS, connect, TLS and TTFB are not measured.

## Data and exports

Known credentials, common personal-data field names, SQL literals and unknown HTTP headers are removed before storage. Additional exact keys and restricted text patterns can strengthen redaction. Defaults cannot be disabled. Unknown sensitive content can still appear in diagnostics; use a narrow scope and restrict viewing permission.

Exports require administrator permission and are generated in memory, limited to 100 requests / 5 MiB per trace, with a per-operator rate limit. JSON has schema `request-inspector/1`; partial exports are marked. Current redaction is reapplied. HAR is an HTTP-only server-side projection, not a browser capture: total observed time is allocated to HAR's wait field for interoperability and explicitly marked as a projection. Its network subphase times and original body sizes are not measurements. SQL and body fixture downloads remain sanitized. Copy as cURL targets a POSIX shell and omits credentials and bodies; review before executing it yourself.

## Advanced actions

CIDRs support IPv4/IPv6. Forwarded addresses are accepted only from configured proxy peers, walking a bounded chain from right to left. An active CIDR restriction excludes requests without a matching network address, including CLI executions.

Additional text patterns support literals, character classes and bounded repetitions up to 64, for example `INV-[0-9]{5}`. Groups, backreferences, alternation and unbounded quantifiers are rejected. Pattern compilation/matching is bounded and fails closed. The preview uses fixed synthetic data. Pattern search examines at most 200 sanitized records from the last 24 hours.

Replay is disabled by default. It requires staging/local/development environment, an exact public HTTPS hostname allowlist, port 443 and explicit confirmation. Only GET/HEAD are accepted; cookies, restored secrets, request bodies and redirects are excluded. Even GET can have side effects, so review the destination. Response size and timeout are bounded and the attempt is audited.

EXPLAIN accepts a reviewed synthetic single-table SELECT over current-site WordPress/diagnostic tables. It rejects writes, multiple statements, comments, functions, joins and string literals. The shipped timeout adapter is MariaDB-only, using a one-second per-statement limit. EXPLAIN ANALYZE and arbitrary stored SQL execution are not provided.

Object-cache, GraphQL and Query Monitor presence/coverage is reported. Cache hit/miss and resolver timings remain unavailable without a measuring adapter. The plugin coexists with shared query logs and does not mutate another profiler's data.

## Retention, multisite and removal

Defaults are seven days, 5,000 request rows and a 250 MiB estimated admission budget. This is an estimate for telemetry, not a physical database disk quota. Hourly WP-Cron removes bounded batches; near capacity, oldest traces are pruned together. Purge immediately invalidates visibility and in-flight older generations, then removes physical rows in batches. Delayed/disabled cron can delay physical deletion; the administrative purge progress action also advances maintenance.

Each multisite site has separate capture tables, settings, quotas and audit data. Network activation initializes sites in batches and new sites start disabled. Deactivation removes scheduled maintenance but retains diagnostics. Uninstall retains data unless **Delete on uninstall** was explicitly enabled for that site; WordPress core tables are never removed.

Audit records contain operator ID, operation, related object ID and UTC timestamp, with no payloads or IP addresses. Retention is separately bounded to 1,000 entries / 30 days. Saved-scope metadata is bounded and expires independently. Export and reviewed analysis fail if the required audit write is unavailable.

## Upgrade and rollback

Back up the database before upgrading. Schema installation is idempotent and the current site upgrades on authorized dashboard access. Disable recording before replacing a release. To roll back, restore the prior plugin package and, when necessary, its matching database backup; do not run speculative destructive down-migrations. If storage fails, keep capture disabled and inspect the database outside the diagnostic UI. A directory submission still requires verified contributor metadata, asset licensing and reviewer acceptance.
# Integrated Live Inspector

Version 0.10.0 adds a private current-page toolbar and dock inside this plugin. Enable the site policy in Settings, opt in your own account, then visit a normal WordPress page. Historical recording is independent. See [Live Inspector guide](LIVE_INSPECTOR_GUIDE.md) for all panels, controls, privacy and expiry behavior.

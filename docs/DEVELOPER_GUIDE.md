# Development and extension contract

The original proposal, PRD, developer specification and design exports remain in `Doc/`. `IMPLEMENTATION_PLAN.md` is the complete roadmap; `PHASE_0_TO_5_EXECUTION_PLAN.md` provides the first-phase work packages. `IMPLEMENTATION_STATUS.md` and the release report distinguish implemented functions, verified coverage and conditional adapters.

## Structure

`request-inspector.php` owns bootstrap and lifecycle registration. Settings and Policy validate configuration; Redactor is the shared sanitizer. Recorder owns request-local instrumentation and buffers. Storage owns four telemetry/control tables; Audit owns a separately retained operation table. Api, Advanced and Workflows expose permission-protected interfaces. Admin loads generated assets only on this plugin's pages. React/WordPress packages are externalized to the libraries supplied by WordPress. Fonts and licenses are local.

Saved scopes are a bounded, non-autoloaded option containing names, owners, sharing and row boundaries; they contain no copied capture bodies. Mutations use database locks. Diagnostic table writes reserve row/byte capacity atomically and finalize complete traces only after child writes. Generation checks invalidate in-flight work after purge. Cleanup removes complete traces together.

## Build and verification

In the development workspace: `npm ci`, `npm run typecheck`, `npm run build`. PHP development dependencies use Composer. Run PHPUnit, PHPStan (2 GiB analysis memory recommended for current WordPress stubs), WPCS/PHPCompatibilityWP and the official WordPress Plugin Check. Bundled plugin source-build instructions are in `request-inspector/BUILD.txt`.

`tests/integration.php` uses a real isolated WordPress site, MariaDB and `tests/http-fixture.php`. `RI_WORDPRESS_PATH` selects an alternative isolated core installation. `tests/concurrency.mjs` spawns eight workers against only the dedicated fixture database. `tests/multisite.php` exercises network/new-site lifecycle and direct uninstall cleanup without deleting the shared plugin source. Never point these fixtures at a real site. Browser scripts log into the synthetic site; they are excluded from the ZIP.

## REST contracts

All capture routes use `/request-inspector/v1`. Cookie authentication requires WordPress REST nonces; capability checks run on every route. Viewing defaults to `manage_options`, optionally `edit_others_posts`. Mutations/exports/advanced actions require `manage_options`. Responses are private/no-store.

Core routes: requests, request detail/events/bodies, stats, settings, health, components, recording/sessions, sample-data and purge progress. Export uses `POST /requests/{id}/export` with `json`, `har`, `sql`, `curl` or `bodies`. Advanced routes provide audit, synthetic preview, integration coverage, bounded search, replay and EXPLAIN. Workflow POST routes provide sessions, comparison, reports and regression review. Settings include a revision; stale updates return 409.

## WP-CLI

Use a permitted account and select a multisite site through WP-CLI's standard `--url`:

```text
wp --user=administrator request-inspector list
wp --user=administrator request-inspector show 123
wp --user=administrator request-inspector record on --mode=external
wp --user=administrator request-inspector record off
wp --user=administrator request-inspector export 123
wp --user=administrator request-inspector purge --yes
```

CLI respects settings validation and operator permissions, exports through the same sanitizer and confirms destructive actions. Export writes to standard output; choosing a private destination is the operator's responsibility.

## Versioned extension boundaries

The portable contract is `request-inspector/1`: ordered request objects include trace/parent IDs, observed durations, sanitized metadata, events and optional body metadata. Consumers must check `truncated`, coverage, sampling, availability and body omission reasons. Additive metadata fields may appear. Do not interpret absence as a measured zero or try to recover redacted values.

`request_inspector_collector_ready` receives the active Recorder and the schema string after the operator's capture policy has admitted the execution. Trusted plugin extensions may call `add_event($name, $metadata, $duration_us)`; metadata is sanitized and shares the recorder's byte/event budgets. Optional durations must be measured inclusive integer microseconds in the supported range. `request_inspector_trace_stored` receives the trace ID, sanitized retained rows and schema string after a successful write. Extensions must not mutate application results or assume they receive raw credentials. No endpoint lets remote callers register collectors.

The observer uses public WordPress HTTP, REST and query-logging hooks. It does not wrap callbacks, replace `$wpdb`, register a global exception handler, mutate shared profiler buffers, or install a persistent MU/drop-in helper. Query Monitor interoperability is coexistence, not a dependency. Cache and GraphQL adapters must supply measured metrics through a separately reviewed collector implementation; detection alone is not profiling.

Transparent callback timing, arbitrary output buffering, remote sharing and additional database adapters remain conditional contracts. Callback timing must preserve references, recursion, callback identity, dynamic registry updates, return values and exceptions before being offered. Remote services require a separate authentication/storage/deployment design and are not shipped by the local plugin.

## Security review notes

The few PHPCS annotations document reviewed direct custom-table operations, diagnostic stack/error observation and closed-grammar EXPLAIN. They do not suppress general security checks. Error reporting is read, never modified. No captured data is evaluated, inserted as HTML, executed as SQL or automatically transmitted. Public temporary exports, arbitrary filesystem reads, arbitrary regex, arbitrary SQL replay and reconstructed credentials are intentionally outside the implementation.
# Live Inspector extension API

The integrated feature exposes bounded `Live_Inspector::log()`, `timer_start()`, `timer_lap()` and `timer_stop()` APIs after authorized live startup. See [the Live Inspector guide](LIVE_INSPECTOR_GUIDE.md#developer-api) for signatures, no-op behavior, limits and examples. It shares the existing recorder and portable extension-event type.

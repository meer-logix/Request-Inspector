# Live Inspector — Request Inspector 0.10.1

Live Inspector is a feature of Request Inspector. Install or update the same plugin ZIP; it has no separate activation, plugin header, Query Monitor dependency or database drop-in.

## Enable and use

1. On new installations, Live Inspector is enabled by default for authorized users. Open Request Inspector → Settings to change site policy.
2. **Live toolbar for my account** defaults to enabled. Each authorized user can disable their own preference. Existing saved site and account choices remain respected on upgrade.
3. Visit a normal WordPress administration page, such as Tools. The Request Inspector dashboard itself is excluded.
4. Click Request Inspector in the WordPress toolbar. Select any panel without reloading the page. Use Dock right/bottom, drag or keyboard-resize the separator, and Escape to close.
5. Authenticated theme-page inspection is also enabled by default and can be disabled separately. The toolbar and normal footer hooks must be available.

Historical recording remains independently controlled. Live-only inspection creates temporary snapshots, not Explorer history. **Open retained trace** appears only when that exact request was also retained under history policy.

The supplied logo appears in the toolbar, dock and loading feedback. Panels use bounded pagination, search and filters; SQL offers component/caller grouping, slow-query and duplicate filters. Timeline includes layer selection, zoom and links to retained event details. Related-hook sections fetch existing observations only when expanded. Small screens use a modal with focus containment.

## Panels and measurement meaning

| Panel | Content and limits |
|---|---|
| Overview | Server elapsed time to the recorder boundary, allocated PHP peak memory, connection query count and observed SQL duration; environment/cache indicators where supported |
| Timeline | Shared monotonic offsets for the observed request, queries, HTTP, hooks, lifecycle milestones and explicit developer events; inclusive spans must not be summed as exclusive CPU time |
| Database Queries | Existing sanitized SQL observations, callers, duplicates and slow flags; requires the database setting and externally enabled SAVEQUERIES |
| Timings | Explicit RI timer handles, completed/lap spans and incomplete markers |
| Logs | Explicit developer messages and sanitized context; no application log-file reading |
| Request | Method, redacted URL/headers, status, body omission metadata and available frontend routing context |
| Admin Screen | Current screen identifiers; not applicable to frontend requests |
| Scripts / Styles | Bounded WordPress registry entries, dependencies and enqueue/print state; no inline code or localized values; not proof of browser execution; script modules excluded |
| Hooks & Actions | Observed occurrences and registered callbacks; no callback wrapping or execution duration |
| Languages | Observed translation-load candidates and locales; not confirmation of successful loading |
| HTTP API Calls | Existing outgoing WordPress HTTP API diagnostics; not browser network traffic |
| Transient Updates | Optional set/delete metadata; values are never captured |
| Capability Checks | Optional user_has_cap filter-stage observations; not a guaranteed final authorization decision |
| Environment | Allowlisted WordPress/PHP/database/configuration information; core nonpersistent-cache hit/miss counters when available; unknown cache adapters are unavailable |
| Conditionals | An explicit safe list of context booleans, with frontend query conditionals evaluated only after the main query |
| PHP Diagnostics | Existing bounded PHP issue observations, severity and safe caller information; native PHP handling remains intact |

Advanced observations respect the existing production guard. Earlier bootstrap, later shutdown handlers, hard out-of-memory failures, cached pages bypassing PHP and browser execution are outside the observation window. A partial or unavailable metric is not a measured zero.

## Privacy, bounds and failure states

Live mode defaults to enabled for authorized accounts on supported HTML pages; site/account controls can disable it. Historical recording, bodies and optional detailed observers keep their separate settings. Snapshot reads require the existing diagnostic capability, current account preference, WordPress cookie/nonce, a signed document ticket, matching session/site/UUID, and an unchanged purge generation. UUIDs never authorize access by themselves. Response caching is disabled; captured content is not embedded in page markup or persisted in browser storage.

Snapshots expire after ten minutes. Admission is bounded to 20 snapshots per account/session, 200 per site, 8 MiB per site and 256 KiB per snapshot. Reads are limited to 120 per snapshot. Details are additionally limited to 100 rows and 24 KB per panel, 16 KB per row and 180 KB across panel rows. The UI distinguishes observed counts from retained details and reports omissions. Expired data may remain physically present until bounded cleanup runs, but cannot be read through the API.

Only dock dimensions/position are saved in localStorage; panel filters and response data remain in document memory. No remote analytics, fonts or service is added. Known secret fields are redacted before retention and again under current policy on read. Diagnostic content can still contain sensitive information, so keep collection scoped.

A pending snapshot receives a few bounded retries, with no continuous polling. Missing/expired storage, revoked permission, session changes, read limits and blocked assets/API requests lead to unavailable or retry feedback. A footer that does not run cannot mount a dock. REST, AJAX, cron, CLI, feeds, downloads, redirects and login pages do not receive the document dock.

Disabling collection stops future snapshots; account/site disabling also prevents reads. Purging history invalidates existing live tickets through the shared generation. Opt-in uninstall deletion removes the live table, schema option and site-qualified account preferences as part of the existing lifecycle.

## Developer API

Call after live observation has started at `init` priority 0, for example from `admin_init`. Optional developer events must be enabled. These APIs are no-ops when live collection is unavailable and do not enable it.

```php
if ( class_exists( '\\RequestInspector\\Live_Inspector' ) ) {
    $timer = \RequestInspector\Live_Inspector::timer_start( 'Build catalogue' );
    \RequestInspector\Live_Inspector::log( 'info', 'Catalogue prepared', array( 'items' => 12 ) );
    if ( $timer ) {
        \RequestInspector\Live_Inspector::timer_lap( $timer );
        \RequestInspector\Live_Inspector::timer_stop( $timer );
    }
}
```

Start returns a unique handle or null; identical labels do not share handles. Stop/lap returns whether the event was accepted. Laps measure from the original start. At most 20 timers remain open and 100 events per optional collector are admitted. Durations above the existing 60-second extension-event limit are omitted and counted as dropped. Unclosed timers are labelled incomplete with unknown duration. Logs accept standard debug/info/notice/warning/error/critical/alert/emergency severities and a sanitized array context. Do not pass raw secrets intentionally.

The temporary transport schema is `request-inspector/live/1`. Developer events retain the existing `extension` event type and `metadata.live_panel` discriminator, so history exports preserve them under the existing portable schema. No remote endpoint registers or executes instrumentation.

Readable sources, Webpack configuration, WordPress dependency manifests, lazy/RTL assets and lockfiles are included in the normal ZIP. See BUILD.txt to rebuild.

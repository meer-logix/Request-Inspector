=== Request Inspector ===
Tags: debugging, http, database, diagnostics, developer
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.10.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inspect local WordPress requests, outgoing HTTP calls, sanitized database queries and PHP diagnostics.

== Description ==

Request Inspector provides a native administration dashboard for local diagnostics. Recording is disabled on activation. Administrators choose recording scope, retention, body capture and access permissions.

Features include searchable captures, incoming/outgoing correlation, caller attribution, sanitized JSON/form bodies, SQL timing and duplicate groups, PHP warnings, short-lived targeted capture, pagination and bounded cleanup.

Live Inspector adds an optional WordPress toolbar and lazy React dock with 17 current-request panels. Live inspection is enabled by default for authorized accounts on supported administration and frontend pages. Site and account settings can disable it; existing saved choices are preserved on upgrade. Live snapshots are private to the current account/session/site, expire after ten minutes and do not enable historical recording. Captured contents are not embedded in page markup or saved to browser storage. Admission and reads are bounded; delayed cleanup can delay physical removal after expiry. No second plugin or database drop-in is installed. Readable source is included; this readme describes activation and privacy.

Hook occurrence/registration inspection, a shared-origin timeline, sanitized JSON/HAR/SQL/body exports, POSIX cURL copying, CIDR/proxy rules, restricted redaction patterns, audit records, saved scopes, local sharing, comparison, component reports, regression review and permission-protected WP-CLI commands are included. Callback execution timings remain unavailable. HAR network phases are explicitly marked as projections. Saved scopes never bypass capture retention.

Advanced replay is disabled by default and limited to explicitly confirmed safe-method public HTTPS requests on staging with an exact hostname allowlist. Synthetic EXPLAIN is limited to a restricted read-only grammar and a MariaDB per-statement timeout adapter. No captured SQL is automatically executed and redacted credentials are never reconstructed.

Database timings require SAVEQUERIES to be explicitly enabled outside this plugin. Detailed database and stack collection requires WP_DEBUG when the production guard is enabled. Request Inspector does not enable global query logging itself.

Capture starts after plugins load. Cached pages that bypass PHP, earlier bootstrap events and browser-side requests are outside coverage. General page output is not buffered; structured REST responses and WordPress HTTP API responses can be captured when body capture is enabled. Missing data is labelled explicitly.

Privacy: diagnostic data stays in this site's database. No analytics or external service is contacted by the plugin. Bodies are off by default. Known credentials, SQL literals and common personal-data keys are removed before storage, but diagnostic content can still contain personal data. Restrict access and capture scope, and purge data after debugging. Retention defaults to seven days, 5,000 request rows and a 250 MB admission budget. WP-Cron performs bounded cleanup; delayed cron may delay physical removal. Deactivation stops collection. Uninstall retains data unless the administrator enabled deletion.

Author: Hammad Farooq Meer â€” https://hammadmeer.netlify.app

== Installation ==

1. Upload the request-inspector directory to wp-content/plugins, or install the release ZIP through Plugins > Add New.
2. Activate Request Inspector.
3. Open Request Inspector > Settings and select a recording mode before enabling capture.
4. Reproduce the issue, inspect the capture, and disable recording when finished.

== Frequently Asked Questions ==

= Why are database timings unavailable? =
Enable SAVEQUERIES in your own development configuration and enable database diagnostics in the plugin. Query logging has memory overhead independent of this plugin's bounded buffers.

= Does a zero count prove nothing happened? =
No. Check collector availability, coverage, sampling and dropped-event indicators.

= How is source provided? =
Readable TypeScript and CSS are in admin/src. The release includes build manifests and instructions in BUILD.txt. WordPress supplies React and the WordPress JavaScript APIs. Fonts are bundled locally under the SIL Open Font License.

== Changelog ==

= 0.10.2 =
* Fixed single-site deactivation and uninstall calling multisite-only functions.
* Restore the original site after network deactivation cleanup, including exceptional exits.
* Explicitly restrict recorder site switching to multisite.

= 0.10.1 =
* Removed the unexpected root Markdown guide from the production package.
* Enabled live inspection by default for authorized accounts, preserving saved opt-outs.
* Replaced generic loaders with panel-specific skeleton layouts.

= 0.10.0 =
* Integrated private Live Inspector toolbar and lazy React dock with 17 diagnostic panels.
* Per-account opt-in, ten-minute snapshots, session isolation and bounded collectors.
* Developer timers/logs, lifecycle, assets, language, transient and capability observations.
* Independent live and history policies; no additional plugin or database drop-in.

= 0.3.0 =
* Initial local request, HTTP, database and PHP diagnostic implementation.

= 0.9.0 =
* Added hooks, timeline, portable traces, advanced policy, audit, local sessions, comparison, reports and CLI workflows.
* Added bounded structured text, concurrent admission checks and multisite lifecycle coverage.

== Upgrade Notice ==

= 0.3.0 =
Recording starts disabled. Review diagnostic data policy before enabling capture.


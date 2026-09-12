# Request Inspector

Local WordPress diagnostics by Hammad Farooq Meer. GPL-2.0-or-later. WordPress 6.6+ / PHP 8.1+.

Install `dist/request-inspector-0.10.2.zip` through WordPress Plugins → Add New → Upload Plugin. Historical recording is disabled on activation. Live Inspector defaults to enabled for authorized users on supported administration and frontend pages; saved site/account choices remain respected on upgrade.

- [User guide](docs/USER_GUIDE.md)
- [Developer guide](docs/DEVELOPER_GUIDE.md)
- [Implementation and verification status](IMPLEMENTATION_STATUS.md)
- [Current submission fixes — no tests run](docs/SUBMISSION_FIXES_0.10.1.md)
- [Previous release verification](docs/LIVE_INSPECTOR_RELEASE_REPORT.md)
- [Supplied document/design traceability](docs/INPUT_TRACEABILITY.md)
- [Phased implementation plan](IMPLEMENTATION_PLAN.md)
- [Live Inspector guide and developer API](docs/LIVE_INSPECTOR_GUIDE.md)
- [Live Inspector implementation plan](docs/LIVE_INSPECTOR_IMPLEMENTATION_PLAN.md)

The release candidate includes the existing phases 0–10 and the integrated Live Inspector toolbar, private transport and 17-panel React dock. Conditional instrumentation and verification limits are documented explicitly. Directory acceptance and universal compatibility are not claimed.

Development: `npm ci`, `npm run typecheck`, `npm run build`, `composer install`. PHP development tooling requires PHP 8.2+. Run `npm run package` to create the deterministic ZIP and SHA-256 file. Development fixtures, tests, dependencies and original design documents stay outside the distributed plugin.

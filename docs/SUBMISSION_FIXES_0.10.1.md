# Submission fixes — 0.10.1

Changes requested on 2026-09-11:

1. Removed LIVE_INSPECTOR.md from the distributed plugin root. The guide remains in workspace docs. Packaging also excludes root Markdown guides to prevent accidental reintroduction.
2. Live Inspector defaults to enabled for authorized accounts on supported administration and frontend pages. Accounts without a saved preference inherit the enabled default; saved site/account opt-outs remain respected. Historical recording and optional detailed collection remain independently controlled.
3. Replaced generic logo-and-random-lines loaders with layouts for summary cards, seven-column request tables, forms, request details, key/value facts, event rows, code and timelines. The dock keeps its panel navigation visible while data loads. Initial pre-React branding retains the centered logo without fake content lines; background refresh keeps existing content with a thin progress indicator.

Version/header/readme and source-inclusive build manifests are updated to 0.10.1. Only production asset compilation and packaging were performed. No tests, Plugin Check, browser runs or standards-validation runs were performed for these changes, as requested. Earlier release verification must not be interpreted as testing this revision.

Artifact: dist/request-inspector-0.10.1.zip. This is the corrected upload archive, not a claim of WordPress.org approval.

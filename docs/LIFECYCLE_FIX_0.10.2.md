# Single-site lifecycle fix — 0.10.2

The reported deactivation fatal error came from unconditionally calling switch_to_blog() and restore_current_blog(), which are multisite functions. Single-site deactivation now clears the current site's cleanup schedule and returns without switching. Network-wide deactivation continues bounded site traversal and restores the original site in a finally block.

Uninstall contained the same unconditional calls; they now run only on multisite. The existing explicit deletion preference still controls removal of stored data. Activation already limits its site-switch loop to multisite network activation. Recorder site switching now also explicitly requires multisite.

This is a PHP-only correction. Existing frontend production assets are retained. Version headers, readme and package manifests are updated to 0.10.2. No tests or validation suites were run, honoring the user's instruction.

Install dist/request-inspector-0.10.2.zip as an update to the existing plugin, then retry deactivation. Uninstall is not needed to apply the fix.

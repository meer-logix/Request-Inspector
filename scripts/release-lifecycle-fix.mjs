import fs from 'node:fs';
for (const file of ['request-inspector/request-inspector.php', 'scripts/package.php', 'scripts/package.mjs', 'scripts/verify-package.php']) {
  fs.writeFileSync(file, fs.readFileSync(file, 'utf8').replaceAll('0.10.1', '0.10.2'));
}
const readme = 'request-inspector/readme.txt';
fs.writeFileSync(readme, fs.readFileSync(readme, 'utf8').replace('Stable tag: 0.10.1', 'Stable tag: 0.10.2').replace('== Changelog ==', '== Changelog ==\n\n= 0.10.2 =\n* Fixed single-site deactivation and uninstall calling multisite-only functions.\n* Restore the original site after network deactivation cleanup, including exceptional exits.\n* Explicitly restrict recorder site switching to multisite.'));
const intro = 'README.md';
fs.writeFileSync(intro, fs.readFileSync(intro, 'utf8').replace('dist/request-inspector-0.10.1.zip', 'dist/request-inspector-0.10.2.zip'));

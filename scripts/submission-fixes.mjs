import fs from 'node:fs';
function edit(file, transform) { fs.writeFileSync(file, transform(fs.readFileSync(file, 'utf8'))); }
edit('request-inspector/includes/class-live-inspector.php', source => source
  .replaceAll('! get_user_meta( get_current_user_id(), self::preference_key(), true )', '! self::account_enabled()')
  .replace("'enabled'      => (bool) get_user_meta( get_current_user_id(), self::preference_key(), true )", "'enabled'      => self::account_enabled()")
  .replace("'uuid'          => $id,", "'uuid'          => $id,\n\t\t\t'panels'        => self::panels(),"));
edit('request-inspector/admin/src/live/client.ts', source => source.replace('uuid: string; expires: number;', 'uuid: string; expires: number; panels: Record<string, string>;'));
edit('request-inspector/admin/src/live/dock.tsx', source => source
  .replace("import { Cards } from '../components/Cards';", "import { Cards } from '../components/Cards';\nimport { DiagnosticSkeleton, type SkeletonLayout } from '../components/DiagnosticSkeleton';")
  .replace(/function Skeleton\(\{ logo \}:[\s\S]*?\n}\nfunction Dock/, `function Skeleton({ panel }: { panel: string }) {
  const layout: SkeletonLayout = panel === 'overview' ? 'overview' : panel === 'timeline' ? 'timeline' : ['request', 'admin', 'environment', 'conditionals'].includes(panel) ? 'facts' : 'events';
  return <DiagnosticSkeleton layout={layout} />;
}
function Dock`)
  .replace('(info?.registry || []).map', "(info?.registry || Object.entries(config.panels || {}).map(([id, label]) => ({ id, label, observed_count: 0 }))).map")
  .replace('<Skeleton logo={config.logo} />', '<Skeleton panel={selected} />'));
edit('request-inspector/admin/src/index.tsx', source => source
  .replace('Save site policy, then enable your account below.', 'Live inspection is enabled by default for authorized accounts. Site and account controls below can disable it.')
  .replace('!settings && !error ? <Loading />', `!settings && !error ? <Loading layout={id ? 'detail' : ['settings', 'advanced'].includes(view) ? 'form' : view === 'explorer' ? 'explorer' : view === 'workflows' ? 'overview' : 'table'} />`));
edit('request-inspector/admin/src/components/Detail.tsx', source => source
  .replace('!error && <Loading />', '!error && <Loading layout="detail" />')
  .replace('!error && <Loading />', '!error && <Loading layout="code" />'));
edit('request-inspector/includes/class-admin.php', source => source.replace('<div class="ri-skeleton-lines" aria-hidden="true"><span></span><span></span><span></span></div>', ''));
edit('request-inspector/admin/src/live/dock.css', source => source.split('\n').filter(line => !line.includes('.rid-skeleton') && !line.includes('@keyframes rid-pulse')).join('\n'));
for (const file of ['request-inspector/request-inspector.php', 'scripts/package.php', 'scripts/package.mjs', 'scripts/verify-package.php']) edit(file, source => source.replaceAll('0.10.0', '0.10.1'));
edit('scripts/package.php', source => source.replace(" // Earlier builds imported", " // Keep workspace guides out of the production plugin root.\n if(preg_match('~^request-inspector/[^/]+\\\\.md$~i',$name))continue;\n // Earlier builds imported"));
edit('request-inspector/readme.txt', source => source.replace('Stable tag: 0.10.0', 'Stable tag: 0.10.1')
  .replace('Enable site policy and opt in your account in Settings, then visit a normal administration page. Frontend inspection requires a separate opt-in.', 'Live inspection is enabled by default for authorized accounts on supported administration and frontend pages. Site and account settings can disable it; existing saved choices are preserved on upgrade.')
  .replace('Source and usage guidance are included in the package.', 'Readable source is included; this readme describes activation and privacy.')
  .replace('== Changelog ==', '== Changelog ==\n\n= 0.10.1 =\n* Removed the unexpected root Markdown guide from the production package.\n* Enabled live inspection by default for authorized accounts, preserving saved opt-outs.\n* Replaced generic loaders with panel-specific skeleton layouts.'));

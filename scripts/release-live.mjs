import fs from 'node:fs';
const version = '0.10.0';
for (const file of ['request-inspector/request-inspector.php', 'scripts/package.mjs', 'scripts/package.php', 'scripts/verify-package.php']) {
  fs.writeFileSync(file, fs.readFileSync(file, 'utf8').replaceAll('0.9.0', version));
}
const readme = 'request-inspector/readme.txt';
fs.writeFileSync(readme, fs.readFileSync(readme, 'utf8').replace('Stable tag: 0.9.0', `Stable tag: ${version}`).replace('== Changelog ==', `== Changelog ==\n\n= ${version} =\n* Integrated private Live Inspector toolbar and lazy React dock with 17 diagnostic panels.\n* Per-account opt-in, ten-minute snapshots, session isolation and bounded collectors.\n* Developer timers/logs, lifecycle, assets, language, transient and capability observations.\n* Independent live and history policies; no additional plugin or database drop-in.`));
const file = 'request-inspector/admin/src/live/dock.tsx';
let source = fs.readFileSync(file, 'utf8');
source = source.replace("const label = (row: Row)", "const panelPreferences = new Map<string, { search: string; component: string; slow: boolean; duplicates: boolean; page: number; group: string; layer: string; zoom: number; severity: string }>();\nconst label = (row: Row)");
source = source.replace("  const [search, setSearch] = useState(''), [component, setComponent] = useState(''), [slow, setSlow] = useState(false), [duplicates, setDuplicates] = useState(false), [page, setPage] = useState(1), [group, setGroup] = useState('events');", "  const saved = panelPreferences.get(panel.id);\n  const [search, setSearch] = useState(saved?.search || ''), [component, setComponent] = useState(saved?.component || ''), [slow, setSlow] = useState(saved?.slow || false), [duplicates, setDuplicates] = useState(saved?.duplicates || false), [page, setPage] = useState(saved?.page || 1), [group, setGroup] = useState(saved?.group || 'events');");
source = source.replace("  const [layer, setLayer] = useState(''), [zoom, setZoom] = useState(1), [severity, setSeverity] = useState('');", "  const [layer, setLayer] = useState(saved?.layer || ''), [zoom, setZoom] = useState(saved?.zoom || 1), [severity, setSeverity] = useState(saved?.severity || '');\n  useEffect(() => { panelPreferences.set(panel.id, { search, component, slow, duplicates, page, group, layer, zoom, severity }); }, [search, component, slow, duplicates, page, group, layer, zoom, severity]);");
source = source.replace("    if (index >= 0) setPage(Math.floor(index / 25) + 1);", "    if (index >= 0) { setSearch(''); setComponent(''); setLayer(''); setSeverity(''); setSlow(false); setDuplicates(false); setGroup('events'); setPage(Math.floor(index / 25) + 1); }");
fs.writeFileSync(file, source);

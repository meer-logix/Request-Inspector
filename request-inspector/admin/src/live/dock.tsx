import { createRoot, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { KeyValues } from '../components/KeyValues';
import { Cards } from '../components/Cards';
import { DiagnosticSkeleton, type SkeletonLayout } from '../components/DiagnosticSkeleton';
import { RelatedHooks } from './RelatedHooks';
import { clearLiveCache, LiveError, milliseconds, read, summary, type LiveConfig, type Panel, type Row, type Summary } from './client';
import './dock.css';

let reactRoot: ReturnType<typeof createRoot> | undefined;
let intent = 0;
export function open(root: HTMLElement, config: LiveConfig, panel: string, trigger: HTMLElement) {
  if (!reactRoot) { root.replaceChildren(); reactRoot = createRoot(root); }
  reactRoot.render(<Dock config={config} initialPanel={panel} trigger={trigger} intent={++intent} />);
}
function Skeleton({ panel }: { panel: string }) {
  const layout: SkeletonLayout = panel === 'overview' ? 'overview' : panel === 'timeline' ? 'timeline' : ['request', 'admin', 'environment', 'conditionals'].includes(panel) ? 'facts' : 'events';
  return <DiagnosticSkeleton layout={layout} />;
}
function Dock({ config, initialPanel, trigger, intent: activation }: { config: LiveConfig; initialPanel: string; trigger: HTMLElement; intent: number }) {
  const [opened, setOpened] = useState(true), [selected, setSelected] = useState(initialPanel), [info, setInfo] = useState<Summary>(), [panel, setPanel] = useState<Panel>(), [error, setError] = useState(''), [retry, setRetry] = useState(0);
  const [side, setSide] = useState(false), [size, setSize] = useState(380), [mobile, setMobile] = useState(window.innerWidth <= 782), [target, setTarget] = useState('');
  const dock = useRef<HTMLElement>(null), content = useRef<HTMLDivElement>(null), drag = useRef<{ start: number; size: number }>();
  const origin = useRef(trigger);
  const close = () => { setOpened(false); origin.current?.focus(); };
  useEffect(() => {
    setOpened(true); setSelected(initialPanel); setTarget(''); origin.current = trigger;
    requestAnimationFrame(() => dock.current?.querySelector<HTMLButtonElement>('.rid-close')?.focus());
  }, [activation]);
  useEffect(() => {
    try { const saved = JSON.parse(localStorage.getItem(config.preferenceKey) || '{}'); setSide(saved.side === true); if (Number.isFinite(saved.size)) setSize(Math.max(240, Math.min(saved.size, 700))); } catch { /* Private browsing may deny preference storage. */ }
    const resize = () => setMobile(window.innerWidth <= 782);
    const restore = () => { clearLiveCache(); setRetry(n => n + 1); };
    window.addEventListener('resize', resize); window.addEventListener('pageshow', restore);
    return () => { window.removeEventListener('resize', resize); window.removeEventListener('pageshow', restore); clearLiveCache(); };
  }, []);
  useEffect(() => {
    const timeout = setTimeout(() => { clearLiveCache(); setInfo(undefined); setPanel(undefined); setError(__('This private snapshot has expired. Navigate normally to inspect a new request.', 'request-inspector')); }, Math.max(0, config.expires * 1000 - Date.now()));
    return () => clearTimeout(timeout);
  }, []);
  useEffect(() => {
    let active = true;
    setInfo(undefined); setPanel(undefined);
    summary(config, true).then(data => { if (active) { setInfo(data); setError(''); } }).catch(e => { if (active) { clearLiveCache(); setError(e.message); } });
    return () => { active = false; };
  }, [retry, activation]);
  useEffect(() => {
    if (!opened || !info) return;
    const controller = new AbortController(); setPanel(undefined); setError('');
    read<Panel>(config, selected, controller.signal).then(setPanel).catch(e => {
      if (controller.signal.aborted) return;
      if (e instanceof LiveError && [401, 403, 410].includes(e.status)) { setInfo(undefined); clearLiveCache(); }
      setError(e.message);
    });
    return () => controller.abort();
  }, [selected, retry, opened, info]);
  useEffect(() => {
    if (!mobile || !opened || !dock.current) return;
    const changed: [HTMLElement, boolean][] = [];
    let current: HTMLElement | null = dock.current.parentElement;
    while (current && current !== document.body) {
      for (const sibling of Array.from(current.parentElement?.children || [])) {
        if (sibling !== current && sibling instanceof HTMLElement) { changed.push([sibling, sibling.inert]); sibling.inert = true; }
      }
      current = current.parentElement;
    }
    return () => changed.forEach(([element, previous]) => { element.inert = previous; });
  }, [mobile, opened]);
  function save(nextSize = size, nextSide = side) {
    try { localStorage.setItem(config.preferenceKey, JSON.stringify({ side: nextSide, size: nextSize })); } catch { /* Display preference is optional. */ }
  }
  const limit = side ? Math.max(320, window.innerWidth - 80) : Math.max(240, window.innerHeight - 80);
  const clamped = Math.min(size, limit);
  return <aside ref={dock} hidden={!opened} className={`rid-dock ${side && !mobile ? 'rid-side' : ''} ${mobile ? 'rid-mobile' : ''}`} style={mobile ? undefined : side ? { width: clamped } : { height: clamped }} role={mobile ? 'dialog' : 'region'} aria-modal={mobile ? true : undefined} aria-labelledby="ri-live-title" onKeyDown={event => {
    if (event.key === 'Escape') { event.stopPropagation(); close(); }
    if (mobile && event.key === 'Tab') {
      const focusable = Array.from(dock.current?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input, select, [tabindex="0"]') || []).filter(node => node.getClientRects().length);
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  }}>
    {!mobile && <div className="rid-resize" role="separator" aria-label={__('Resize diagnostics panel', 'request-inspector')} aria-orientation={side ? 'vertical' : 'horizontal'} aria-valuemin={240} aria-valuemax={limit} aria-valuenow={Math.round(clamped)} tabIndex={0} onPointerDown={e => { drag.current = { start: side ? e.clientX : e.clientY, size: clamped }; e.currentTarget.setPointerCapture(e.pointerId); }} onPointerMove={e => { if (drag.current) setSize(Math.max(240, Math.min(limit, drag.current.size + drag.current.start - (side ? e.clientX : e.clientY)))); }} onPointerUp={() => { drag.current = undefined; save(); }} onPointerCancel={() => { drag.current = undefined; }} onKeyDown={e => { if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) { e.preventDefault(); const next = e.key === 'Home' ? 240 : e.key === 'End' ? limit : Math.max(240, Math.min(limit, clamped + (['ArrowUp', 'ArrowLeft'].includes(e.key) ? 20 : -20))); setSize(next); save(next); } }} />}
    <header className="rid-header"><div className="rid-brand"><img src={config.logo} alt="" width="30" height="30" /><div><h2 id="ri-live-title">{__('Request Inspector', 'request-inspector')}</h2><span>{__('Live · current document', 'request-inspector')}</span></div></div><div className="rid-actions">
      {!mobile && <button type="button" onClick={() => { setSide(!side); save(size, !side); }}>{side ? __('Dock bottom', 'request-inspector') : __('Dock right', 'request-inspector')}</button>}
      {info?.trace && <a href={`${config.dashboard}&ri_f_trace_id=${encodeURIComponent(info.trace)}`}>{__('Open retained trace', 'request-inspector')}</a>}
      <button type="button" className="rid-close" aria-label={__('Close live inspector', 'request-inspector')} onClick={close}>×</button>
    </div></header>
    <div className="rid-layout"><nav aria-label={__('Live diagnostics panels', 'request-inspector')}><div role="tablist" aria-orientation="vertical">
      {(info?.registry || Object.entries(config.panels || {}).map(([id, label]) => ({ id, label, observed_count: 0 }))).map((item, index, all) => <button type="button" key={item.id} role="tab" id={`ri-live-tab-${item.id}`} aria-controls="ri-live-panel" aria-selected={selected === item.id} tabIndex={selected === item.id ? 0 : -1} onClick={() => setSelected(item.id)} onKeyDown={e => {
        const delta = e.key === 'ArrowDown' ? 1 : e.key === 'ArrowUp' ? -1 : 0;
        if (delta || e.key === 'Home' || e.key === 'End') { e.preventDefault(); const next = e.key === 'Home' ? all[0] : e.key === 'End' ? all[all.length - 1] : all[(index + delta + all.length) % all.length]; setSelected(next.id); document.getElementById(`ri-live-tab-${next.id}`)?.focus(); }
      }}><span>{item.label}</span>{item.observed_count > 0 && <small>{item.observed_count}</small>}</button>)}
    </div></nav><div className="rid-content" ref={content}>
      {error && <div className="rid-notice rid-error" role="alert"><p>{error}</p><button type="button" onClick={() => { clearLiveCache(); setRetry(n => n + 1); }}>{__('Retry snapshot', 'request-inspector')}</button> <a href={config.dashboard}>{__('Open Request Inspector', 'request-inspector')}</a></div>}
      {!error && (!info || !panel) && <Skeleton panel={selected} />}
      {!error && info && panel && <div role="tabpanel" id="ri-live-panel" aria-labelledby={`ri-live-tab-${selected}`} tabIndex={0}>
        <div className="rid-panel-heading"><h3>{panel.label}</h3><span className="rid-status">{statusLabel(panel.status)}</span></div>
        {panel.reason && <p className="rid-description">{panel.reason}</p>}
        {(panel.dropped > 0 || info.summary.dropped > 0) && <p className="rid-notice">{sprintf(__('Bounded capture: %1$d panel details omitted; %2$d recorder details dropped. Counts and retained rows may differ.', 'request-inspector'), panel.dropped, info.summary.dropped)}</p>}
        {selected === 'overview' ? <><Overview info={info} />{panel.rows[1] && <KeyValues values={panel.rows[1]} />}</> : <PanelRows key={selected} panel={panel} info={info} target={target} onSelect={(id, event) => { setTarget(event || ''); setSelected(id); }} />}
        <RelatedHooks key={selected} config={config} panel={selected} />
        <footer className="rid-footer">{__('Private snapshot · ten-minute expiry · measurements end at the recorder boundary', 'request-inspector')}<br />{info.trace ? __('Also retained under history policy.', 'request-inspector') : __('Not retained in history. Live inspection does not enable recording.', 'request-inspector')}</footer>
      </div>}
    </div></div>
  </aside>;
}
function statusLabel(status: string) {
  return ({ available: __('Observed', 'request-inspector'), partial: __('Partial', 'request-inspector'), disabled: __('Disabled', 'request-inspector'), unsupported: __('Unavailable', 'request-inspector'), not_applicable: __('Not applicable', 'request-inspector'), failed: __('Failed', 'request-inspector') } as Record<string, string>)[status] || status;
}
function Overview({ info }: { info: Summary }) {
  const s = info.summary;
  return <><p className="rid-route"><strong>{s.method}</strong> <code>{s.url}</code> <span>{s.status ?? '—'}</span></p><Cards values={[
    [__('Server request time', 'request-inspector'), milliseconds(s.elapsed_us)], [__('Allocated peak memory', 'request-inspector'), `${(s.memory_bytes / 1048576).toFixed(1)} MiB`], [__('Observed database time', 'request-inspector'), milliseconds(s.database_us)], [__('Connection query count', 'request-inspector'), s.query_count],
  ]} /><KeyValues values={{ [__('Observed interval', 'request-inspector')]: milliseconds(s.observed_us), [__('Timed queries', 'request-inspector')]: s.timed_queries, [__('PHP issues observed', 'request-inspector')]: s.issues, [__('Coverage boundary', 'request-inspector')]: s.coverage, [__('Snapshot identity', 'request-inspector')]: info.uuid }} /></>;
}
const panelPreferences = new Map<string, { search: string; component: string; slow: boolean; duplicates: boolean; page: number; group: string; layer: string; zoom: number; severity: string }>();
const label = (row: Row) => String(row.name ?? row.handle ?? row.condition ?? row.url ?? row.id ?? row.wordpress ?? '');
function PanelRows({ panel, info, target, onSelect }: { panel: Panel; info: Summary; target: string; onSelect: (panel: string, event?: string) => void }) {
  const saved = panelPreferences.get(panel.id);
  const [search, setSearch] = useState(saved?.search || ''), [component, setComponent] = useState(saved?.component || ''), [slow, setSlow] = useState(saved?.slow || false), [duplicates, setDuplicates] = useState(saved?.duplicates || false), [page, setPage] = useState(saved?.page || 1), [group, setGroup] = useState(saved?.group || 'events');
  const [layer, setLayer] = useState(saved?.layer || ''), [zoom, setZoom] = useState(saved?.zoom || 1), [severity, setSeverity] = useState(saved?.severity || '');
  useEffect(() => { panelPreferences.set(panel.id, { search, component, slow, duplicates, page, group, layer, zoom, severity }); }, [search, component, slow, duplicates, page, group, layer, zoom, severity]);
  const components = useMemo(() => [...new Set(panel.rows.map(row => row.component).filter(Boolean))] as string[], [panel]);
  const rows = useMemo(() => panel.rows.filter(row => (!search || JSON.stringify(row).toLowerCase().includes(search.toLowerCase())) && (!component || row.component === component) && (!layer || row.event_type === layer) && (!severity || (row.metadata?.severity || row.metadata?.details?.severity) === severity) && (!slow || row.metadata?.is_slow) && (!duplicates || row.metadata?.duplicate_count > 1)), [panel, search, component, slow, duplicates, layer, severity]);
  useEffect(() => {
    if (!target) return;
    const index = panel.rows.findIndex(row => row.live_id === target);
    if (index >= 0) { setSearch(''); setComponent(''); setLayer(''); setSeverity(''); setSlow(false); setDuplicates(false); setGroup('events'); setPage(Math.floor(index / 25) + 1); }
  }, [target, panel]);
  useEffect(() => {
    const frame = requestAnimationFrame(() => { const row = document.getElementById(`ri-live-row-${target}`) as HTMLDetailsElement | null; if (row) { row.open = true; row.querySelector<HTMLElement>('summary')?.focus(); row.scrollIntoView({ block: 'nearest' }); } });
    return () => cancelAnimationFrame(frame);
  }, [target, page, panel]);
  const pages = Math.max(1, Math.ceil(rows.length / 25));
  const visible = rows.slice((Math.min(page, pages) - 1) * 25, Math.min(page, pages) * 25);
  const grouped = useMemo(() => {
    const result: Record<string, { count: number; time: number }> = {};
    for (const row of rows) { const key = String(group === 'caller' ? row.metadata?.caller?.function || row.metadata?.caller?.file || __('Unknown', 'request-inspector') : row.component || __('Unknown', 'request-inspector')); result[key] ||= { count: 0, time: 0 }; result[key].count++; result[key].time += row.duration_us || 0; }
    return result;
  }, [rows, group]);
  if (['request', 'admin', 'environment', 'conditionals'].includes(panel.id)) return panel.rows.length ? <div className="rid-facts">{panel.rows.map((row, i) => <KeyValues key={i} values={row} />)}</div> : <p>{__('No observations are available in this context.', 'request-inspector')}</p>;
  return <><div className="rid-filters"><label>{__('Search retained details', 'request-inspector')}<input type="search" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} /></label>
    {components.length > 0 && <label>{__('Component', 'request-inspector')}<select value={component} onChange={e => { setComponent(e.target.value); setPage(1); }}><option value="">{__('All components', 'request-inspector')}</option>{components.map(value => <option key={value}>{value}</option>)}</select></label>}
    {panel.id === 'database' && <><label>{__('View', 'request-inspector')}<select value={group} onChange={e => setGroup(e.target.value)}><option value="events">{__('Queries', 'request-inspector')}</option><option value="component">{__('By component', 'request-inspector')}</option><option value="caller">{__('By caller', 'request-inspector')}</option></select></label><label className="rid-check"><input type="checkbox" checked={slow} onChange={e => { setSlow(e.target.checked); setPage(1); }} />{__('Slow', 'request-inspector')}</label><label className="rid-check"><input type="checkbox" checked={duplicates} onChange={e => { setDuplicates(e.target.checked); setPage(1); }} />{__('Duplicates', 'request-inspector')}</label></>}
    {['logs','php'].includes(panel.id) && <label>{__('Severity', 'request-inspector')}<select value={severity} onChange={e => { setSeverity(e.target.value); setPage(1); }}><option value="">{__('All severities', 'request-inspector')}</option>{['debug','info','notice','warning','error','fatal','deprecated','critical','alert','emergency'].map(value => <option key={value}>{value}</option>)}</select></label>}
    {panel.id === 'timeline' && <><label>{__('Layer', 'request-inspector')}<select value={layer} onChange={e => { setLayer(e.target.value); setPage(1); }}><option value="">{__('All layers', 'request-inspector')}</option>{[...new Set(panel.rows.map(row => String(row.event_type || 'extension')))].map(value => <option key={value}>{value}</option>)}</select></label><label>{__('Zoom', 'request-inspector')}<select value={zoom} onChange={e => setZoom(Number(e.target.value))}><option value={1}>{__('Fit', 'request-inspector')}</option><option value={2}>2×</option><option value={4}>4×</option></select></label></>}
  </div>
  {group !== 'events' && panel.id === 'database' ? <table><thead><tr><th>{__('Group', 'request-inspector')}</th><th>{__('Retained count', 'request-inspector')}</th><th>{__('Inclusive duration', 'request-inspector')}</th></tr></thead><tbody>{Object.entries(grouped).map(([key, value]) => <tr key={key}><td>{key}</td><td>{value.count}</td><td>{milliseconds(value.time)}</td></tr>)}</tbody></table> : panel.id === 'timeline' ? <div className="rid-waterfall" style={{width: zoom * 100 + "%"}}>{visible.map((row, i) => <div className="rid-span" key={i}><button type="button" onClick={() => onSelect(({ db_query: 'database', error: 'php', hook: 'hooks', http: 'http', execution: 'overview' } as Record<string, string>)[row.event_type] || row.metadata?.live_panel || 'overview', row.live_id)} title={label(row)}>{label(row)}</button><div className="rid-track"><span style={{ insetInlineStart: `${Math.min(99, Math.max(0, (row.start_offset_us || 0) / Math.max(1, info.summary.observed_us) * 100))}%`, width: `${Math.max(.5, Math.min(100, (row.duration_us || 0) / Math.max(1, info.summary.observed_us) * 100))}%` }} /></div><small>{milliseconds(row.duration_us)}</small></div>)}</div> : <div className="rid-events">{visible.map((row, i) => <details id={row.live_id ? "ri-live-row-" + row.live_id : undefined} key={`${panel.id}-${i}`}><summary><code>{label(row)}</code><span>{row.component || row.state || ''}</span>{row.duration_us != null && <small>{milliseconds(row.duration_us)}</small>}</summary><KeyValues values={row.metadata || row} />{row.metadata && <p><code>{label(row)}</code></p>}{row.bodies && <KeyValues values={row.bodies} />}</details>)}</div>}
  {!rows.length && <p className="rid-empty">{panel.status === 'available' ? __('No matching observations were retained for this request.', 'request-inspector') : __('This collector did not provide data for this request. Check its coverage and settings above.', 'request-inspector')}</p>}
  {pages > 1 && <div className="rid-pagination"><button type="button" disabled={page <= 1} onClick={() => setPage(n => n - 1)}>{__('Previous', 'request-inspector')}</button><span>{page} / {pages}</span><button type="button" disabled={page >= pages} onClick={() => setPage(n => n + 1)}>{__('Next', 'request-inspector')}</button></div>}</>;
}

import { __ } from '@wordpress/i18n';
import { summary, type LiveConfig } from './live/client';
import './live/toolbar.css';

function start() {
  if (window.self !== window.top) return;
  const root = document.getElementById('ri-live-inspector-root');
  const toolbar = document.getElementById('wp-admin-bar-request-inspector-live');
  if (!root || !toolbar || root.dataset.ready) return;
  root.dataset.ready = 'true';
  const config: LiveConfig = JSON.parse(root.dataset.config || '{}');
  const title = toolbar.querySelector<HTMLAnchorElement>(':scope > .ab-item');
  let dock: Promise<typeof import('./live/dock')> | undefined;
  const load = () => dock ||= import(/* webpackChunkName: "ri-live-dock" */ './live/dock').catch(error => { dock = undefined; throw error; });
  toolbar.addEventListener('click', async event => {
    const mouse = event as MouseEvent;
    if (mouse.button !== 0 || mouse.ctrlKey || mouse.metaKey || mouse.shiftKey || mouse.altKey) return;
    const link = (event.target as Element).closest<HTMLAnchorElement>('a');
    if (!link) return;
    const panel = link === title ? 'overview' : link.hash.replace('#ri-live-', '');
    if (link !== title && !link.hash.startsWith('#ri-live-')) return;
    event.preventDefault();
    if (!root.childElementCount) {
      const loader = document.createElement('div');
      loader.className = 'ri-live-opening'; loader.setAttribute('role', 'status');
      loader.setAttribute('aria-label', __('Opening Request Inspector', 'request-inspector'));
      const logo = document.createElement('img'); logo.src = config.logo; logo.alt = ''; logo.width = 48; logo.height = 48;
      loader.append(logo); root.append(loader);
    }
    try { (await load()).open(root, config, panel, link); }
    catch { root.replaceChildren(); title?.setAttribute('title', __('Panel assets could not load. Click to retry.', 'request-inspector')); }
  });
  summary(config).then(data => {
    if (!title) return;
    const s = data.summary;
    const text = `${s.elapsed_us == null ? '—' : (s.elapsed_us / 1e6).toFixed(2) + 's'} · ${(s.memory_bytes / 1048576).toFixed(1)} MiB · ${s.database_us == null ? '—' : (s.database_us / 1e6).toFixed(2) + 's'} · ${s.query_count} Q`;
    const logo = document.createElement('img'); logo.src = config.logo; logo.alt = ''; logo.width = 20; logo.height = 20;
    const metrics = document.createElement('span'); metrics.className = 'ri-live-toolbar-metrics'; metrics.textContent = text;
    const name = document.createElement('span'); name.className = 'ri-live-toolbar-name'; name.textContent = __('Request Inspector', 'request-inspector');
    title.replaceChildren(logo, name, metrics);
    title.setAttribute('aria-label', `${__('Request Inspector: request time, allocated peak memory, observed database time, connection query count', 'request-inspector')}: ${text}`);
    title.title = __('Current document only. Timing ends at the recorder boundary; database timing can cover fewer queries than the connection count.', 'request-inspector');
    toolbar.dataset.severity = s.issues ? 'warning' : s.dropped || s.database_us == null ? 'partial' : 'observed';
    for (const panel of data.registry) {
      const anchor = document.querySelector(`#wp-admin-bar-request-inspector-live-${panel.id} > a`);
      if (anchor && panel.observed_count) anchor.append(document.createTextNode(` (${panel.observed_count})`));
    }
  }).catch(() => {
    if (title) title.title = __('Live summary is unavailable. Open the inspector to retry.', 'request-inspector');
  });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
else start();

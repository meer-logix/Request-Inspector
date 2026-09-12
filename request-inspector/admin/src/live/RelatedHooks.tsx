import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { KeyValues } from '../components/KeyValues';
import { read, type LiveConfig, type Panel } from './client';

// Deliberate panel associations: never inspect hook argument payloads.
const definitions: Record<string, string[]> = {
  request: ['parse_request', 'wp', 'template_redirect'],
  admin: ['admin_init', 'current_screen', 'admin_enqueue_scripts', 'admin_footer'],
  scripts: ['wp_enqueue_scripts', 'admin_enqueue_scripts', 'wp_print_scripts', 'script_loader_tag'],
  styles: ['wp_enqueue_scripts', 'admin_enqueue_scripts', 'wp_print_styles', 'style_loader_tag'],
  languages: ['load_textdomain', 'load_translation_file', 'load_script_translation_file'],
  http: ['http_request_args', 'pre_http_request', 'http_api_debug'],
  transients: ['set_transient', 'setted_transient', 'deleted_transient', 'set_site_transient', 'setted_site_transient', 'deleted_site_transient'],
  caps: ['map_meta_cap', 'user_has_cap'],
};

export function RelatedHooks({ config, panel }: { config: LiveConfig; panel: string }) {
  const [data, setData] = useState<Panel>(), [error, setError] = useState(''), [busy, setBusy] = useState(false);
  const names = definitions[panel];
  if (!names) return null;
  return <details onToggle={async event => {
    if (!event.currentTarget.open || data || busy) return;
    setBusy(true);
    try { setData(await read<Panel>(config, 'hooks')); setError(''); }
    catch (failure) { setError((failure as Error).message); }
    finally { setBusy(false); }
  }}><summary>{__('Related hook observations', 'request-inspector')}</summary>
    <p>{__('Registered callbacks at observation time; this does not prove callback execution or duration.', 'request-inspector')}</p>
    {busy && <p role="status">{__('Preparing hook details', 'request-inspector')}</p>}
    {error && <p role="alert">{error}</p>}
    {data && <><p>{data.reason}</p>{data.rows.filter(row => names.includes(row.name)).map((row, index) => <details key={index}><summary><code>{row.name}</code></summary><KeyValues values={row.metadata} /></details>)}
      {!data.rows.some(row => names.includes(row.name)) && <p>{__('No related observations were retained. Collection settings and bounds may limit coverage.', 'request-inspector')}</p>}</>}
  </details>;
}

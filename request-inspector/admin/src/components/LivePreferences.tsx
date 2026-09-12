import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Notice } from './Notice';

export function LivePreferences() {
  const [enabled, setEnabled] = useState(false), [ready, setReady] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState('');
  const [schemaReady, setSchemaReady] = useState(true);
  useEffect(() => {
    const controller = new AbortController();
    apiFetch<{ enabled: boolean; schema_ready: boolean }>({ path: '/request-inspector/v1/live/preferences', signal: controller.signal }).then(data => { setEnabled(data.enabled); setSchemaReady(data.schema_ready); setReady(true); }).catch(e => { if (!controller.signal.aborted) setError(e.message); });
    return () => controller.abort();
  }, []);
  return <section className="ri-panel"><h3>{__('Live toolbar for my account', 'request-inspector')}</h3><p>{__('When site policy permits, inspect your next page request in a private, ten-minute snapshot. This does not enable historical recording. The Request Inspector dashboard itself is excluded.', 'request-inspector')}</p>
    {!schemaReady && <Notice error>{__('Private snapshot storage is not ready. Live inspection will remain unavailable until the site migration succeeds.', 'request-inspector')}</Notice>}
    <label className="ri-check"><input type="checkbox" checked={enabled} disabled={!ready || busy || !schemaReady} onChange={async e => {
      const next = e.target.checked; setBusy(true);
      try { await apiFetch({ path: '/request-inspector/v1/live/preferences', method: 'POST', data: { enabled: next } }); setEnabled(next); setError(''); }
      catch (err) { setError((err as Error).message); } finally { setBusy(false); }
    }} />{__('Enable live inspection for my account', 'request-inspector')}</label>{error && <Notice error>{error}</Notice>}</section>;
}

import { __ } from '@wordpress/i18n';

export interface LiveConfig {
  uuid: string; expires: number; panels: Record<string, string>; summaryUrl: string; panelUrl: string;
  nonce: string; ticket: string; logo: string; site: number;
  preferenceKey: string; dashboard: string;
}
export type Row = Record<string, any>;
export interface Panel {
  id: string; label: string; status: string; reason: string;
  coverage: string; observed_count: number; dropped: number; rows: Row[];
}
export interface Summary {
  schema: string; uuid: string; expires: number; site: number;
  summary: { elapsed_us: number | null; observed_us: number; memory_bytes: number; query_count: number; database_us: number | null; timed_queries: number; issues: number; dropped: number; coverage: string; boundary: string; method: string; url: string; status: number | null };
  registry: Omit<Panel, 'rows'>[]; trace: string | null; retention: string;
}
const cache = new Map<string, unknown>();
export class LiveError extends Error {
  constructor(message: string, public status: number) { super(message); }
}
export function clearLiveCache() { cache.clear(); }
export async function read<T>(config: LiveConfig, panel?: string, signal?: AbortSignal, revalidate = false): Promise<T> {
  if (Date.now() >= config.expires * 1000) { cache.clear(); throw new LiveError(__('This private snapshot has expired.', 'request-inspector'), 410); }
  const key = `${config.site}:${config.uuid}:${panel || 'summary'}`;
  if (!revalidate && cache.has(key)) return cache.get(key) as T;
  const response = await fetch(panel ? config.panelUrl + encodeURIComponent(panel) : config.summaryUrl, {
    credentials: 'same-origin', cache: 'no-store', signal,
    headers: { 'X-WP-Nonce': config.nonce, 'X-RI-Live-Ticket': config.ticket },
  });
  const data = await response.json();
  if (!response.ok || response.status === 202) {
    if ([401, 403, 410].includes(response.status)) cache.clear();
    throw new LiveError(data.message || __('Live diagnostics are unavailable.', 'request-inspector'), response.status);
  }
  if (cache.size >= 20) cache.clear();
  cache.set(key, data);
  return data as T;
}
export async function summary(config: LiveConfig, revalidate = false): Promise<Summary> {
  for (let attempt = 0; ; attempt++) {
    try { return await read<Summary>(config, undefined, undefined, revalidate); }
    catch (error) {
      if (!(error instanceof LiveError) || error.status !== 202 || attempt >= 3) throw error;
      await new Promise(resolve => setTimeout(resolve, 250 * 2 ** attempt));
    }
  }
}
export const milliseconds = (us: number | null | undefined) => us == null ? '—' : `${(us / 1000).toLocaleString(undefined, { maximumFractionDigits: 2 })} ms`;

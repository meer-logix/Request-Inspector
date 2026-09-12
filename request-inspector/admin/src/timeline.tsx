import { useEffect, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";
import type { Capture, Event, Page } from "./types";

type Span = {
  id: string;
  type: string;
  name: string;
  start: number;
  duration: number;
  request?: string;
  event?: string;
  metadata: unknown;
};
export function Timeline({
  record,
  children,
  onOpen,
  onEvent,
}: {
  record: Capture;
  children: Capture[];
  onOpen: (id: string) => void;
  onEvent: (type: string, id: string) => void;
}) {
  const [events, setEvents] = useState<Event[]>([]),
    [error, setError] = useState(""),
    [layer, setLayer] = useState("all"),
    [zoom, setZoom] = useState(1),
    [selected, setSelected] = useState<string>();
  useEffect(() => {
    const c = new AbortController();
    apiFetch<Page<Event>>({
      path: `/request-inspector/v1/requests/${record.id}/events?per_page=100`,
      signal: c.signal,
    })
      .then((r) => setEvents(r.items))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [record.id]);
  const spans: Span[] = [
    {
      id: `request-${record.id}`,
      type: "execution",
      name: record.url,
      start: 0,
      duration: record.duration_ms,
      metadata: record.metadata,
    },
    ...children.map((c) => ({
      id: `http-${c.id}`,
      type: "http",
      name: `${c.method} ${c.url}`,
      start: Number(c.metadata.start_offset_us || 0) / 1000,
      duration: c.duration_ms,
      request: c.id,
      metadata: c.metadata,
    })),
    ...events.map((e) => ({
      id: `event-${e.id}`,
      type: e.event_type,
      name: e.name,
      start: Number(e.start_offset_us || 0) / 1000,
      duration: e.duration_ms || 0,
      event: e.id,
      metadata: e.metadata,
    })),
  ];
  const rows = spans
    .filter((r) => layer === "all" || r.type === layer)
    .sort((a, b) => a.start - b.start);
  const duration = Math.max(record.duration_ms, 1);
  return (
    <section>
      <h2>{__("Request timeline", "request-inspector")}</h2>
      <p>
        {__(
          "Bars show inclusive elapsed spans relative to the current request. Points have no measured duration. DNS, TLS and TTFB are unavailable. The first 100 diagnostic events are shown; diagnostic tabs contain the full retained list.",
          "request-inspector",
        )}
      </p>
      {error && <p role="alert">{error}</p>}
      <div className="ri-actions">
        <label>
          {__("Layer", "request-inspector")}
          <select
            value={layer}
            onChange={(e) => {
              setLayer(e.target.value);
              setSelected(undefined);
            }}
          >
            <option value="all">{__("All", "request-inspector")}</option>
            <option value="http">HTTP</option>
            <option value="db_query">SQL</option>
            <option value="error">PHP</option>
            <option value="hook">{__("Hooks", "request-inspector")}</option>
          </select>
        </label>
        <button onClick={() => setZoom(Math.min(8, zoom * 2))}>
          {__("Zoom in", "request-inspector")}
        </button>
        <button onClick={() => setZoom(1)}>
          {__("Fit", "request-inspector")}
        </button>
      </div>
      <div className="ri-waterfall">
        <div style={{ minWidth: `${zoom * 100}%` }}>
          {rows.map((r) => {
            const left = Math.max(
              0,
              Math.min(99.75, (r.start / duration) * 100),
            );
            return (
              <div key={r.id} className="ri-waterfall-row">
                <button
                  aria-pressed={selected === r.id}
                  onClick={() => setSelected(r.id)}
                >
                  {r.name}
                </button>
                <div className="ri-waterfall-track">
                  <span
                    style={{
                      left: `${left}%`,
                      width: `${Math.max(
                        0.25,
                        Math.min(100 - left, (r.duration / duration) * 100),
                      )}%`,
                    }}
                  />
                </div>
              </div>
            );
          })}
        </div>
      </div>
      <ol className="ri-timeline-list">
        {rows
          .filter((r) => !selected || r.id === selected)
          .map((r) => (
            <li key={r.id}>
              <code>{r.name}</code> — {r.start.toFixed(2)} ms /{" "}
              {r.duration.toFixed(2)} ms{" "}
              {r.request && (
                <button onClick={() => onOpen(r.request!)}>
                  {__("Open HTTP request", "request-inspector")}
                </button>
              )}
              {r.event && ["db_query", "error", "hook"].includes(r.type) && (
                <button onClick={() => onEvent(r.type, r.event!)}>
                  {__("Open diagnostic tab", "request-inspector")}
                </button>
              )}
              {selected === r.id && (
                <pre>{JSON.stringify(r.metadata, null, 2)}</pre>
              )}
            </li>
          ))}
      </ol>
      <button onClick={() => setSelected(undefined)}>
        {__("Show all events", "request-inspector")}
      </button>
    </section>
  );
}

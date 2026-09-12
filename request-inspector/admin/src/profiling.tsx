import { useEffect, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { __, sprintf } from "@wordpress/i18n";
import type { Capture, Event, Page } from "./types";

type Registration = {
  function: string;
  component: string;
  file?: string;
  priority: number;
  accepted_args: number;
};
type HookMetadata = {
  occurrences: number;
  registered: number;
  callbacks: Registration[];
};

export function HookPage() {
  const [requests, setRequests] = useState<Capture[]>([]),
    [record, setRecord] = useState<Capture>(),
    [error, setError] = useState("");
  useEffect(() => {
    const c = new AbortController();
    apiFetch<Page<Capture>>({
      path: "/request-inspector/v1/requests?per_page=50",
      signal: c.signal,
    })
      .then((r) => setRequests(r.items))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, []);
  return (
    <section>
      <h1>{__("Hooks Inspector", "request-inspector")}</h1>
      {error && <p role="alert">{error}</p>}
      <label>
        {__("Select request context", "request-inspector")}
        <select
          value={record?.id || ""}
          onChange={async (e) => {
            if (!e.target.value) return;
            try {
              setRecord(
                await apiFetch<Capture>({
                  path: `/request-inspector/v1/requests/${e.target.value}`,
                }),
              );
            } catch (err) {
              setError((err as Error).message);
            }
          }}
        >
          <option value="">
            {__("Choose a retained request", "request-inspector")}
          </option>
          {requests.map((r) => (
            <option key={r.id} value={r.parent_id || r.id}>
              #{r.id} {r.method} {r.url}
            </option>
          ))}
        </select>
      </label>
      {record && (
        <HookInspector
          id={record.id}
          available={record.metadata.hooks_available}
        />
      )}
    </section>
  );
}

export function ExportControls({ id }: { id: string }) {
  const [format, setFormat] = useState("json"),
    [busy, setBusy] = useState(false),
    [message, setMessage] = useState("");
  return (
    <div className="ri-actions">
      <label>
        {__("Portable trace", "request-inspector")}
        <select value={format} onChange={(e) => setFormat(e.target.value)}>
          <option value="json">JSON</option>
          <option value="har">HAR</option>
          <option value="sql">SQL</option>
          <option value="bodies">
            {__("Body fixtures", "request-inspector")}
          </option>
          <option value="curl">
            {__("Copy as cURL (POSIX)", "request-inspector")}
          </option>
        </select>
      </label>
      <button
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          try {
            const file = await apiFetch<{
              content: string;
              mime: string;
              filename: string;
              truncated: boolean;
            }>({
              path: `/request-inspector/v1/requests/${id}/export`,
              method: "POST",
              data: { format },
            });
            if (format === "curl")
              await navigator.clipboard.writeText(file.content);
            else {
              const url = URL.createObjectURL(
                new Blob([file.content], { type: file.mime }),
              );
              const a = document.createElement("a");
              a.href = url;
              a.download = file.filename;
              a.click();
              setTimeout(() => URL.revokeObjectURL(url), 1000);
            }
            setMessage(
              file.truncated
                ? __(
                    "Export reached its size limit; the file is marked partial.",
                    "request-inspector",
                  )
                : __("Sanitized export prepared.", "request-inspector"),
            );
          } catch (e) {
            setMessage((e as Error).message);
          } finally {
            setBusy(false);
          }
        }}
      >
        {__("Export", "request-inspector")}
      </button>
      <span role="status">{message}</span>
    </div>
  );
}

export function HookInspector({
  id,
  available,
}: {
  id: string;
  available?: boolean;
}) {
  const [data, setData] = useState<Page<Event>>(),
    [page, setPage] = useState(1),
    [search, setSearch] = useState(""),
    [error, setError] = useState("");
  useEffect(() => {
    const c = new AbortController();
    apiFetch<Page<Event>>({
      path: `/request-inspector/v1/requests/${id}/events?event_type=hook&page=${page}&search=${encodeURIComponent(
        search,
      )}`,
      signal: c.signal,
    })
      .then(setData)
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, [id, page, search]);
  useEffect(()=>{requestAnimationFrame(revealEvent);},[data]);
  return (
    <section>
      <h2>{__("Hooks Inspector", "request-inspector")}</h2>
      <p>
        {__(
          "Occurrences are observed hook invocations. Registrations are a snapshot at first observation; callback execution and duration are unavailable. Nested spans are not exclusive CPU time.",
          "request-inspector",
        )}
      </p>
      {!available && (
        <p role="status">
          {__(
            "Hook observation was disabled for this capture.",
            "request-inspector",
          )}
        </p>
      )}
      {error && <p role="alert">{error}</p>}
      <label>
        {__("Search hook", "request-inspector")}
        <input
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
      </label>
      {data?.items.map((event) => {
        const m = event.metadata as unknown as HookMetadata;
        return (
          <details className="ri-event" id={"ri-event-"+event.id} key={event.id}>
            <summary>
              <code>{event.name}</code>
              <span>
                {sprintf(
                  __(
                    "%1$d occurrences · %2$d registrations",
                    "request-inspector",
                  ),
                  m.occurrences,
                  m.registered,
                )}
              </span>
            </summary>
            <div className="ri-event-body">
              <table className="ri-table">
                <thead>
                  <tr>
                    <th>{__("Callback", "request-inspector")}</th>
                    <th>{__("Component", "request-inspector")}</th>
                    <th>{__("Priority", "request-inspector")}</th>
                    <th>{__("Accepted arguments", "request-inspector")}</th>
                  </tr>
                </thead>
                <tbody>
                  {m.callbacks.map((r, i) => (
                    <tr key={i}>
                      <td>
                        <code>{r.function}</code>
                        <br />
                        {r.file}
                      </td>
                      <td>{r.component}</td>
                      <td>{r.priority}</td>
                      <td>{r.accepted_args}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {m.registered > m.callbacks.length && (
                <p>
                  {__(
                    "Registry snapshot was capped at 20 callbacks.",
                    "request-inspector",
                  )}
                </p>
              )}
            </div>
          </details>
        );
      })}
      <div className="ri-pagination">
        <button disabled={page === 1} onClick={() => setPage(page - 1)}>
          {__("Previous", "request-inspector")}
        </button>
        <span>{page}</span>
        <button
          disabled={!data || page * data.per_page >= data.total}
          onClick={() => setPage(page + 1)}
        >
          {__("Next", "request-inspector")}
        </button>
      </div>
    </section>
  );
}


export function revealEvent() {
 const id=new URLSearchParams(window.location.search).get('ri_event');
 if(!id||! /^[0-9]+$/.test(id))return;
 const element=document.getElementById('ri-event-'+id);
 if(element instanceof HTMLDetailsElement){element.open=true;element.scrollIntoView({block:'nearest'});}
}

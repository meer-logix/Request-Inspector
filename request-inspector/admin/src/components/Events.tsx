import React, { useState, useEffect } from "@wordpress/element";
import { __, sprintf } from "@wordpress/i18n";
import { Notice } from "./Notice";
import { Loading } from "./Loading";
import { Pagination } from "./Pagination";
import { Trace } from "./Trace";
import { useEvents } from "../hooks/useEvents";
import { revealEvent } from "../profiling";
import { formatMs } from "../utils/format";

export function Events({
  requestId,
  type,
  openRequest,
  components,
}: {
  requestId?: string;
  type: "db_query" | "error";
  openRequest: (id: string) => void;
  components: string[];
}) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [component, setComponent] = useState("");
  const [severity, setSeverity] = useState("");
  const [slow, setSlow] = useState(false);
  const [duplicates, setDuplicates] = useState(false);

  const { data, error, busy } = useEvents({
    requestId,
    type,
    page,
    search,
    component,
    severity,
    slow,
    duplicates,
  });

  useEffect(() => {
    requestAnimationFrame(revealEvent);
  }, [data]);

  return (
    <section>
      <div className="ri-section-head">
        <h2>
          {type === "db_query"
            ? __("Database queries", "request-inspector")
            : __("PHP diagnostics", "request-inspector")}
        </h2>
        <div className="ri-muted">
          {busy
            ? <Loading compact />
            : sprintf(
                __("%d retained events", "request-inspector"),
                data?.total || 0,
              )}
        </div>
      </div>
      <div className="ri-filters">
        <label>
          {__("Search", "request-inspector")}
          <input
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            placeholder={
              type === "db_query"
                ? __("Search sanitized SQL…", "request-inspector")
                : __("Search issues…", "request-inspector")
            }
          />
        </label>
        <label>
          {__("Component", "request-inspector")}
          <select
            value={component}
            onChange={(e) => {
              setComponent(e.target.value);
              setPage(1);
            }}
          >
            <option value="">
              {__("All components", "request-inspector")}
            </option>
            {components.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </select>
        </label>
        {type === "error" ? (
          <label>
            {__("Severity", "request-inspector")}
            <select
              value={severity}
              onChange={(e) => {
                setSeverity(e.target.value);
                setPage(1);
              }}
            >
              <option value="">
                {__("All severities", "request-inspector")}
              </option>
              <option value="warning">
                {__("Warning", "request-inspector")}
              </option>
              <option value="notice">
                {__("Notice", "request-inspector")}
              </option>
              <option value="deprecated">
                {__("Deprecated", "request-inspector")}
              </option>
              <option value="fatal">{__("Fatal", "request-inspector")}</option>
              <option value="not_deprecated">
                {__("Hide deprecated", "request-inspector")}
              </option>
            </select>
          </label>
        ) : (
          <>
            <label className="ri-check">
              <input
                type="checkbox"
                checked={slow}
                onChange={(e) => {
                  setSlow(e.target.checked);
                  setPage(1);
                }}
              />
              {__("Slow only", "request-inspector")}
            </label>
            <label className="ri-check">
              <input
                type="checkbox"
                checked={duplicates}
                onChange={(e) => {
                  setDuplicates(e.target.checked);
                  setPage(1);
                }}
              />
              {__("Duplicates only", "request-inspector")}
            </label>
          </>
        )}
      </div>
      {error && <Notice error>{error}</Notice>}
      {data?.items.map((event) => (
        <details
          className={`ri-event ${event.metadata.is_slow ? "ri-slow" : ""}`}
          id={"ri-event-" + event.id}
          key={event.id}
        >
          <summary>
            <span className="ri-badge">
              {type === "error"
                ? event.metadata.severity
                : formatMs(event.duration_ms)}
            </span>
            <code>{event.name}</code>
            <span>{event.component}</span>
            {(event.metadata.duplicate_count || 0) > 1 && (
              <span className="ri-badge">
                ×{event.metadata.duplicate_count}
              </span>
            )}
          </summary>
          <div className="ri-event-body">
            <pre>{event.name}</pre>
            <Trace caller={event.metadata.caller} />
            {!requestId && (
              <button onClick={() => openRequest(event.request_id)}>
                {__("Open parent request", "request-inspector")}
              </button>
            )}
            {type === "error" && (
              <p className="ri-muted">
                {__(
                  "Source snippets and trace arguments are omitted to reduce sensitive-data exposure.",
                  "request-inspector",
                )}
              </p>
            )}
          </div>
        </details>
      ))}
      {data && !data.total && (
        <Notice>
          {__(
            "No retained events match this view. Check the capture’s collector availability before interpreting this as zero activity.",
            "request-inspector",
          )}
        </Notice>
      )}
      <Pagination
        page={page}
        total={data?.total || 0}
        size={25}
        onPage={setPage}
      />
    </section>
  );
}

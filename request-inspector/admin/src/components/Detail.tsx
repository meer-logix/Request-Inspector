import React, { useState, useRef, useEffect } from "@wordpress/element";
import { __, sprintf } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";
import { Notice } from "./Notice";
import { Loading } from "./Loading";
import { Badge } from "./Badge";
import { Cards } from "./Cards";
import { KeyValues } from "./KeyValues";
import { Trace } from "./Trace";
import { Payload } from "./Payload";
import { Events } from "./Events";
import { HookInspector, ExportControls } from "../profiling";
import { Timeline } from "../timeline";
import { useCapture } from "../hooks/useCapture";
import { formatMs, errorText } from "../utils/format";
import { urlState } from "../utils/url";

const base = "/request-inspector/v1";

export function Detail({
  id,
  onClose,
  onOpen,
  components,
  canManage,
  changed,
  drawer = false,
}: {
  id: string;
  onClose: () => void;
  onOpen: (id: string) => void;
  components: string[];
  canManage: boolean;
  changed: () => void;
  drawer?: boolean;
}) {
  const [tab, setTab] = useState(urlState().get("ri_tab") || "overview");
  const heading = useRef<HTMLHeadingElement>(null);

  const { record, bodies, children, error: captureError } = useCapture(id, tab);
  const [deleteError, setDeleteError] = useState("");

  useEffect(() => {
    heading.current?.focus();
  }, [id]);
  useEffect(() => {
    const onPop = () => setTab(urlState().get("ri_tab") || "overview");
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  const tabs: [string, string][] = [
    ["overview", __("Overview", "request-inspector")],
    ["request", __("Request", "request-inspector")],
    ["response", __("Response", "request-inspector")],
    ["headers", __("Headers", "request-inspector")],
    ["database", __("Database", "request-inspector")],
    ["hooks", __("Hooks", "request-inspector")],
    ["http", __("HTTP API", "request-inspector")],
    ["php", __("PHP", "request-inspector")],
    ["timeline", __("Timeline", "request-inspector")],
  ];

  function select(key: string) {
    setTab(key);
    const u = new URL(window.location.href);
    u.searchParams.set("ri_tab", key);
    window.history.replaceState({}, "", u);
  }

  const error = captureError || deleteError;

  return (
    <article className={drawer ? "ri-detail ri-drawer" : "ri-detail"}>
      <div className="ri-section-head">
        <h2 tabIndex={-1} ref={heading}>
          {sprintf(__("Request #%s", "request-inspector"), id)}
        </h2>
        <div className="ri-actions">
          {drawer && (
            <button onClick={() => onOpen(id)}>
              {__("Full inspector", "request-inspector")}
            </button>
          )}
          <button onClick={onClose}>
            {__("Close inspector", "request-inspector")}
          </button>
        </div>
      </div>
      {error && <Notice error>{error}</Notice>}
      {!record ? (
        !error && <Loading layout="detail" />
      ) : (
        <>
          <div className="ri-endpoint">
            <span className="ri-method">{record.method}</span>
            <code>{record.url}</code>
            <Badge status={record.status} />
            <span>{formatMs(record.duration_ms)}</span>
          </div>
          <div
            className="ri-tabs"
            role="tablist"
            aria-label={__("Request inspector tabs", "request-inspector")}
          >
            {tabs.map(([key, label], i) => (
              <button
                role="tab"
                key={key}
                aria-selected={tab === key}
                tabIndex={tab === key ? 0 : -1}
                id={`ri-tab-${key}`}
                aria-controls="ri-tabpanel"
                onClick={() => select(key)}
                onKeyDown={(event) => {
                  if (event.key === "ArrowRight" || event.key === "ArrowLeft") {
                    event.preventDefault();
                    const next =
                      tabs[
                        (i +
                          (event.key === "ArrowRight" ? 1 : tabs.length - 1)) %
                          tabs.length
                      ][0];
                    select(next);
                    document.getElementById(`ri-tab-${next}`)?.focus();
                  }
                }}
              >
                {label}
              </button>
            ))}
          </div>
          <div
            role="tabpanel"
            id="ri-tabpanel"
            aria-labelledby={`ri-tab-${tab}`}
            className="ri-tabpanel"
          >
            {tab === "overview" && (
              <>
                <Cards
                  values={[
                    [
                      __("DB queries", "request-inspector"),
                      record.metadata.database_available
                        ? record.metadata.queries ?? 0
                        : "—",
                    ],
                    [
                      __("PHP issues", "request-inspector"),
                      record.metadata.php_available
                        ? record.metadata.issues ?? 0
                        : "—",
                    ],
                    [
                      __("Peak process memory", "request-inspector"),
                      record.metadata.memory_peak
                        ? `${(record.metadata.memory_peak / 1048576).toFixed(
                            1,
                          )} MiB`
                        : "—",
                    ],
                    [
                      __("Dropped details", "request-inspector"),
                      record.metadata.dropped ?? 0,
                    ],
                  ]}
                />
                <div className="ri-grid">
                  <section className="ri-panel">
                    <h3>{__("Initiator and caller", "request-inspector")}</h3>
                    <Trace caller={record.metadata.caller} />
                  </section>
                  <section className="ri-panel">
                    <h3>{__("Request metadata", "request-inspector")}</h3>
                    <KeyValues
                      values={{
                        [__("Context", "request-inspector")]: record.type,
                        [__("Direction", "request-inspector")]:
                          record.direction,
                        [__("Started at UTC", "request-inspector")]:
                          record.started_at,
                        [__("Trace", "request-inspector")]: record.trace_id,
                        [__("Coverage", "request-inspector")]:
                          record.metadata.coverage,
                        [__("WordPress", "request-inspector")]:
                          record.metadata.wp,
                        [__("PHP", "request-inspector")]: record.metadata.php,
                      }}
                    />
                    {record.parent_id && (
                      <button onClick={() => onOpen(record.parent_id!)}>
                        {__("Open parent", "request-inspector")}
                      </button>
                    )}
                  </section>
                </div>
              </>
            )}
            {(tab === "request" || tab === "response") && (
              bodies ? <Payload body={bodies[tab]} /> : !error && <Loading layout="code" />
            )}
            {tab === "headers" && (
              <div className="ri-grid">
                <section>
                  <h3>{__("Request headers", "request-inspector")}</h3>
                  <KeyValues values={record.metadata.request_headers || {}} />
                </section>
                <section>
                  <h3>{__("Response headers", "request-inspector")}</h3>
                  <KeyValues values={record.metadata.response_headers || {}} />
                </section>
              </div>
            )}
            {tab === "database" && (
              <>
                {!record.metadata.database_available && (
                  <Notice>
                    {__(
                      "Database detail was unavailable or disabled for this request. Enable DB tracing and configure SAVEQUERIES explicitly for future captures.",
                      "request-inspector",
                    )}
                  </Notice>
                )}
                <Events
                  requestId={id}
                  type="db_query"
                  components={components}
                  openRequest={onOpen}
                />
              </>
            )}
            {tab === "php" && (
              <>
                {!record.metadata.php_available && (
                  <Notice>
                    {__(
                      "PHP observation was disabled or unavailable for this request.",
                      "request-inspector",
                    )}
                  </Notice>
                )}
                <Events
                  requestId={id}
                  type="error"
                  components={components}
                  openRequest={onOpen}
                />
              </>
            )}
            {tab === "http" && (
              <>
                {children.map((child) => (
                  <button
                    className="ri-related"
                    key={child.id}
                    onClick={() => onOpen(child.id)}
                  >
                    <code>
                      {child.method} {child.url}
                    </code>
                    <Badge status={child.status} />
                    {formatMs(child.duration_ms)}
                  </button>
                ))}
                {!children.length && (
                  <Notice>
                    {__(
                      "No child HTTP calls were retained. Network operations outside the WordPress HTTP API are not observable.",
                      "request-inspector",
                    )}
                  </Notice>
                )}
              </>
            )}
            {canManage && <ExportControls id={id} />}
            {tab === "hooks" && (
              <HookInspector
                id={id}
                available={record.metadata.hooks_available}
              />
            )}
            {tab === "timeline" && (
              <Timeline
                record={record}
                children={children}
                onOpen={onOpen}
                onEvent={(type, eventId) => {
                  const u = new URL(window.location.href);
                  u.searchParams.set("ri_event", eventId);
                  window.history.replaceState({}, "", u);
                  select(
                    type === "db_query"
                      ? "database"
                      : type === "error"
                      ? "php"
                      : "hooks",
                  );
                }}
              />
            )}
          </div>
          {canManage && (
            <button
              className="ri-danger"
              onClick={async () => {
                if (
                  !window.confirm(
                    __(
                      "Delete this entire trace, including parent, child calls, bodies and events?",
                      "request-inspector",
                    ),
                  )
                )
                  return;
                try {
                  await apiFetch({
                    path: `${base}/requests/${id}`,
                    method: "DELETE",
                  });
                  changed();
                  onClose();
                } catch (err) {
                  setDeleteError(errorText(err));
                }
              }}
            >
              {__("Delete trace", "request-inspector")}
            </button>
          )}
        </>
      )}
    </article>
  );
}

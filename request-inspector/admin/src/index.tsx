import { createRoot, useState, useEffect, useRef } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { __, sprintf } from "@wordpress/i18n";
import type {
  Health,
  Page,
  Settings,
  SettingsResponse,
  Capture,
} from "./types";
import "@fontsource/inter/latin-400.css";
import "@fontsource/inter/latin-600.css";
import "@fontsource/jetbrains-mono/latin-400.css";
import "./style.css";
import { HookPage } from "./profiling";

import { Timeline } from "./timeline";
import { AdvancedPage } from "./advanced";
import { WorkflowsPage } from "./workflows";
import { formatMs, errorText } from "./utils/format";
import { urlState, initialView } from "./utils/url";
import { Notice } from "./components/Notice";
import { Badge } from "./components/Badge";
import { Cards } from "./components/Cards";
import { Pagination } from "./components/Pagination";
import { KeyValues } from "./components/KeyValues";
import { Trace } from "./components/Trace";
import { Payload } from "./components/Payload";
import { Events } from "./components/Events";
import { Detail } from "./components/Detail";
import { LivePreferences } from "./components/LivePreferences";
import { Loading } from "./components/Loading";
import { logo } from "./utils/branding";

const base = "/request-inspector/v1";


function SettingsPage({
  initial,
  health,
  onSaved,
}: {
  initial: SettingsResponse;
  health?: Health;
  onSaved: () => void;
}) {
  const [settings, setSettings] = useState(initial.settings),
    [section, setSection] = useState("recording"),
    [message, setMessage] = useState(""),
    [error, setError] = useState(""),
    [saving, setSaving] = useState(false),
    [count, setCount] = useState(1),
    [target, setTarget] = useState<string | null>(null);
  const change = <K extends keyof Settings>(key: K, value: Settings[K]) =>
    setSettings({ ...settings, [key]: value });
  const toggle = (key: keyof Settings, label: string) => (
    <label className="ri-toggle">
      <span>{label}</span>
      <input
        type="checkbox"
        checked={Boolean(settings[key])}
        onChange={(e) => change(key, e.target.checked as never)}
      />
    </label>
  );
  const number = (
    key: keyof Settings,
    label: string,
    min: number,
    max: number,
  ) => (
    <label>
      {label}
      <input
        type="number"
        min={min}
        max={max}
        value={Number(settings[key])}
        onChange={(e) => change(key, Number(e.target.value) as never)}
      />
    </label>
  );
  async function save() {
    setSaving(true);
    setError("");
    try {
      const result = await apiFetch<SettingsResponse>({
        path: `${base}/settings`,
        method: "POST",
        data: settings,
      });
      setSettings(result.settings);
      setMessage(
        __(
          "Settings saved. New captures use this policy.",
          "request-inspector",
        ),
      );
      onSaved();
    } catch (err) {
      setError(errorText(err));
    } finally {
      setSaving(false);
    }
  }
  const modes: [string, string][] = [
    ["all", __("All requests", "request-inspector")],
    ["external", __("External HTTP only", "request-inspector")],
    ["rest_ajax", __("REST and AJAX only", "request-inspector")],
    ["errors", __("Errors only", "request-inspector")],
    ["slow", __("Slow requests only", "request-inspector")],
    ["component", __("Selected component", "request-inspector")],
    ["next", __("Next N requests", "request-inspector")],
    ["current", __("Current request", "request-inspector")],
  ];
  return (
    <section>
      <div className="ri-section-head">
        <div>
          <p className="ri-eyebrow">
            {__("CAPTURE POLICY", "request-inspector")}
          </p>
          <h1>{__("Settings", "request-inspector")}</h1>
        </div>
        <button
          className="ri-primary"
          disabled={saving || !initial.can_manage}
          onClick={save}
        >
          {saving
            ? __("Saving…", "request-inspector")
            : __("Save changes", "request-inspector")}
        </button>
      </div>
      <section className="ri-panel">
        <h2>{__("Live Inspector feature", "request-inspector")}</h2>
        <p>{__("Private current-page diagnostics inside Request Inspector. Existing body, database, hook and production policies still apply. Live inspection is enabled by default for authorized accounts. Site and account controls below can disable it.", "request-inspector")}</p>
        {toggle("live_enabled", __("Allow the live toolbar on this site", "request-inspector"))}
        {toggle("live_frontend", __("Allow authenticated frontend inspection", "request-inspector"))}
        {toggle("live_events", __("Observe developer logs, timers and transient changes", "request-inspector"))}
        {toggle("live_caps", __("Observe capability filter stages", "request-inspector"))}
      </section>
      <LivePreferences />
      <div className="ri-tabs">
        <button
          aria-pressed={section === "recording"}
          onClick={() => setSection("recording")}
        >
          {__("Recording and retention", "request-inspector")}
        </button>
        <button
          aria-pressed={section === "security"}
          onClick={() => setSection("security")}
        >
          {__("Security and redaction", "request-inspector")}
        </button>
      </div>
      {message && <Notice>{message}</Notice>}
      {error && <Notice error>{error}</Notice>}
      <fieldset disabled={!initial.can_manage || saving}>
        {section === "recording" ? (
          <div className="ri-grid">
            <section className="ri-panel">
              <h2>{__("Recording engine", "request-inspector")}</h2>
              {toggle("enabled", __("Enable recording", "request-inspector"))}
              <label>
                {__("Recording mode", "request-inspector")}
                <select
                  value={settings.mode}
                  onChange={(e) => change("mode", e.target.value)}
                >
                  {modes.map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
              <p className="ri-muted">
                {__(
                  "Off is controlled by the recording switch. Targeted modes must be saved before arming.",
                  "request-inspector",
                )}
              </p>
              {["next", "current"].includes(settings.mode) && (
                <div className="ri-panel">
                  <label>
                    {__("Requests to reserve", "request-inspector")}
                    <input
                      type="number"
                      min="1"
                      max="1000"
                      value={count}
                      onChange={(e) => setCount(Number(e.target.value))}
                    />
                  </label>
                  <button
                    onClick={async () => {
                      try {
                        const result = await apiFetch<{
                          target_url: string | null;
                        }>({
                          path: `${base}/recording/sessions`,
                          method: "POST",
                          data: { count },
                        });
                        setTarget(result.target_url);
                        setMessage(
                          __(
                            "Capture armed for 10 minutes. Only eligible root executions consume slots.",
                            "request-inspector",
                          ),
                        );
                      } catch (err) {
                        setError(errorText(err));
                      }
                    }}
                  >
                    {__("Arm capture", "request-inspector")}
                  </button>
                  {target && (
                    <a href={target} target="_blank" rel="noreferrer">
                      {__(
                        "Open the one-time target request",
                        "request-inspector",
                      )}
                    </a>
                  )}
                </div>
              )}
              {settings.mode === "component" && (
                <label>
                  {__("Component directory name", "request-inspector")}
                  <input
                    value={settings.component}
                    onChange={(e) => change("component", e.target.value)}
                  />
                </label>
              )}
              {number(
                "sample_percent",
                __("Sampling percentage", "request-inspector"),
                1,
                100,
              )}
              {number(
                "slow_ms",
                __("Slow request threshold in ms", "request-inspector"),
                1,
                60000,
              )}
              <h3>{__("Capture scope", "request-inspector")}</h3>
              {toggle(
                "database",
                __("Database query details", "request-inspector"),
              )}
              {toggle("php_errors", __("PHP issues", "request-inspector"))}
              {toggle(
                "hooks",
                __("Hook occurrences and registrations", "request-inspector"),
              )}
              {toggle(
                "stack_traces",
                __("Argument-free stack traces", "request-inspector"),
              )}
              {!health?.database_available && (
                <Notice>
                  {__(
                    "DB timing requires SAVEQUERIES to be explicitly enabled in your WordPress configuration. It has its own memory cost; this plugin never enables it globally.",
                    "request-inspector",
                  )}
                </Notice>
              )}
              {number(
                "slow_query_ms",
                __("Slow query threshold in ms", "request-inspector"),
                1,
                10000,
              )}
            </section>
            <section className="ri-panel">
              <h2>{__("Retention policy", "request-inspector")}</h2>
              {number(
                "retention_days",
                __("Keep captures for days", "request-inspector"),
                1,
                30,
              )}
              {number(
                "max_records",
                __("Maximum request rows", "request-inspector"),
                10,
                50000,
              )}
              {number(
                "max_storage_mb",
                __("Logical storage budget in MiB", "request-inspector"),
                1,
                1024,
              )}
              {number(
                "max_body_kb",
                __("Maximum sanitized body in KiB", "request-inspector"),
                1,
                256,
              )}
              <p>
                {sprintf(
                  __("%s MiB reserved for telemetry", "request-inspector"),
                  ((Number(health?.state.used_bytes) || 0) / 1048576).toFixed(
                    2,
                  ),
                )}
              </p>
              <p className="ri-muted">
                {__(
                  "Database allocation and indexes can use additional disk space. Cleanup runs in bounded background batches.",
                  "request-inspector",
                )}
              </p>
              {toggle(
                "delete_on_uninstall",
                __("Delete plugin data when uninstalled", "request-inspector"),
              )}
              <h3>{__("Delete captured data", "request-inspector")}</h3>
              <button
                className="ri-danger"
                onClick={async () => {
                  if (
                    !window.confirm(
                      __(
                        "Purge all captures? This cannot be undone.",
                        "request-inspector",
                      ),
                    )
                  )
                    return;
                  try {
                    await apiFetch({
                      path: `${base}/requests`,
                      method: "DELETE",
                      data: { confirm: true },
                    });
                    setMessage(
                      __(
                        "Purge started. Captures are immediately hidden while cleanup finishes.",
                        "request-inspector",
                      ),
                    );
                    onSaved();
                  } catch (err) {
                    setError(errorText(err));
                  }
                }}
              >
                {__("Purge all captures", "request-inspector")}
              </button>
            </section>
          </div>
        ) : (
          <div className="ri-grid">
            <section className="ri-panel">
              <h2>{__("Sensitive data protection", "request-inspector")}</h2>
              <div className="ri-chips">
                {[
                  "Authorization",
                  "Cookie",
                  "Set-Cookie",
                  "password",
                  "token",
                  "secret",
                  "api_key",
                  "client_secret",
                  "nonce",
                ].map((key) => (
                  <code key={key}>{key}</code>
                ))}
              </div>
              <p>
                {__(
                  "Default protections cannot be disabled. Unknown header values are masked. Redacted values cannot be recovered.",
                  "request-inspector",
                )}
              </p>
              <label>
                {__(
                  "Additional protected field names, one per line",
                  "request-inspector",
                )}
                <textarea
                  rows={6}
                  value={settings.custom_keys.join("\n")}
                  onChange={(e) =>
                    change(
                      "custom_keys",
                      e.target.value.split("\n").filter(Boolean),
                    )
                  }
                />
              </label>
              {toggle(
                "request_body",
                __("Capture sanitized request bodies", "request-inspector"),
              )}
              {toggle(
                "response_body",
                __("Capture sanitized response bodies", "request-inspector"),
              )}
              <Notice>
                {__(
                  "Bodies may contain personal information even after redaction. JSON and form fields are supported; other content is omitted. Capture only what you need and purge when finished.",
                  "request-inspector",
                )}
              </Notice>
            </section>
            <section className="ri-panel">
              <h2>{__("Access and environment", "request-inspector")}</h2>
              <label>
                {__("Minimum viewing capability", "request-inspector")}
                <select
                  value={settings.view_capability}
                  onChange={(e) => change("view_capability", e.target.value)}
                >
                  <option value="manage_options">
                    {__("Administrators — manage_options", "request-inspector")}
                  </option>
                  <option value="edit_others_posts">
                    {__(
                      "Editors and administrators — edit_others_posts",
                      "request-inspector",
                    )}
                  </option>
                </select>
              </label>
              <p>
                {__(
                  "Settings and deletion always require manage_options.",
                  "request-inspector",
                )}
              </p>
              {toggle(
                "production_guard",
                __(
                  "Disable detailed tracing when WP_DEBUG is off",
                  "request-inspector",
                ),
              )}
              {!initial.detailed_allowed && (
                <Notice>
                  {__(
                    "Detailed instrumentation is constrained by the environment guard.",
                    "request-inspector",
                  )}
                </Notice>
              )}
            </section>
          </div>
        )}
      </fieldset>
    </section>
  );
}

function App() {
  const [view, setView] = useState(initialView()),
    [id, setId] = useState(urlState().get("ri_id") || ""),
    [drawer, setDrawer] = useState(false),
    [settings, setSettings] = useState<SettingsResponse>(),
    [health, setHealth] = useState<Health>(),
    [components, setComponents] = useState<string[]>([]),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [revision, setRevision] = useState(0),
    [pollRevision, setPollRevision] = useState(0);
  const [filters, setFilters] = useState<Record<string, string>>(() =>
      Object.fromEntries(
        Array.from(urlState())
          .filter(([key]) => key.startsWith("ri_f_"))
          .map(([key, value]) => [key.slice(5), value]),
      ),
    ),
    [data, setData] = useState<Page<Capture>>(),
    [stats, setStats] = useState<Record<string, string>>(),
    [busy, setBusy] = useState(true),
    [selected, setSelected] = useState<string[]>([]);
  const tableRef = useRef<HTMLDivElement>(null);
  const refresh = () => setRevision((n) => n + 1);
  useEffect(() => {
    const abort = new AbortController();
    Promise.all([
      apiFetch<SettingsResponse>({
        path: `${base}/settings`,
        signal: abort.signal,
      }).then(setSettings),
      apiFetch<Health>({ path: `${base}/health`, signal: abort.signal }).then(setHealth),
      apiFetch<string[]>({ path: `${base}/components`, signal: abort.signal }).then(setComponents),
    ])
      .then(() => {
        setError("");
      })
      .catch((err) => {
        if (!abort.signal.aborted) setError(errorText(err));
      });
    return () => abort.abort();
  }, [revision]);
  useEffect(() => {
    const onPop = () => {
      setId(urlState().get("ri_id") || "");
      setDrawer(false);
      setView(urlState().get("ri_view") || initialView());
      setFilters(
        Object.fromEntries(
          Array.from(urlState())
            .filter(([key]) => key.startsWith("ri_f_"))
            .map(([key, value]) => [key.slice(5), value]),
        ),
      );
    };
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);
  useEffect(() => {
    if (view !== "explorer" || (id && !drawer)) return;
    const abort = new AbortController();
    const timer = setTimeout(() => {
      setBusy(true);
      const q = new URLSearchParams(filters);
      Promise.all([
        apiFetch<Page<Capture>>({
          path: `${base}/requests?${q}`,
          signal: abort.signal,
        }).then(setData),
        apiFetch<Record<string, string>>({
          path: `${base}/stats?${q}`,
          signal: abort.signal,
        }).then(setStats),
      ])
        .then(() => {
          setError("");
        })
        .catch((err) => {
          if (!abort.signal.aborted) setError(errorText(err));
        })
        .finally(() => {
          if (!abort.signal.aborted) setBusy(false);
        });
    }, filters.search ? 200 : 0);
    return () => {
      abort.abort();
      clearTimeout(timer);
    };
  }, [filters, revision, pollRevision, view, id, drawer]);
  useEffect(() => {
    if (!settings?.settings.enabled || view !== "explorer" || id) return;
    const timer = setInterval(() => {
      if (document.visibilityState === "visible" && !error && !busy) setPollRevision((n) => n + 1);
    }, 10000);
    return () => clearInterval(timer);
  }, [settings?.settings.enabled, view, error, id, busy]);
  function filter(
    key: string,
    value: string,
    additional: Record<string, string> = {},
  ) {
    const next = {
      ...filters,
      [key]: value,
      ...(key !== "page" ? { page: "1" } : {}),
      ...additional,
    };
    setFilters(next);
    setSelected([]);
    const u = new URL(window.location.href);
    Array.from(u.searchParams.keys())
      .filter((k) => k.startsWith("ri_f_"))
      .forEach((k) => u.searchParams.delete(k));
    Object.entries(next).forEach(([k, v]) => {
      if (v) u.searchParams.set(`ri_f_${k}`, v);
    });
    window.history.replaceState({}, "", u);
  }
  function open(requestId: string, asDrawer = false) {
    setId(requestId);
    setDrawer(asDrawer);
    const u = new URL(window.location.href);
    u.searchParams.set("ri_id", requestId);
    u.searchParams.set("ri_tab", "overview");
    window.history.pushState({}, "", u);
  }
  function close() {
    setId("");
    setDrawer(false);
    const u = new URL(window.location.href);
    u.searchParams.delete("ri_id");
    u.searchParams.delete("ri_tab");
    window.history.pushState({}, "", u);
    tableRef.current?.focus();
  }
  function navigate(next: string) {
    setView(next);
    setId("");
    setDrawer(false);
    const u = new URL(window.location.href);
    u.searchParams.set("ri_view", next);
    u.searchParams.delete("ri_id");
    u.searchParams.delete("ri_tab");
    u.searchParams.delete("ri_event");
    window.history.pushState({}, "", u);
  }
  useEffect(() => {
    const click = (event: MouseEvent) => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      const link = (event.target as Element).closest<HTMLAnchorElement>("#adminmenu a");
      if (!link || link.target) return;
      const url = new URL(link.href);
      const pages: Record<string, string> = { "request-inspector": "explorer", "request-inspector-database": "database", "request-inspector-php": "php", "request-inspector-settings": "settings" };
      const next = pages[url.searchParams.get("page") || ""];
      if (url.origin !== window.location.origin || !next) return;
      event.preventDefault();
      navigate(next);
    };
    document.addEventListener("click", click);
    return () => document.removeEventListener("click", click);
  }, []);
  const select = (key: string, label: string, options: [string, string][]) => (
    <label>
      {label}
      <select
        value={filters[key] || ""}
        onChange={(e) => filter(key, e.target.value)}
      >
        <option value="">{__("All", "request-inspector")}</option>
        {options.map(([value, text]) => (
          <option key={value} value={value}>
            {text}
          </option>
        ))}
      </select>
    </label>
  );
  return (
    <div>
      <header className="ri-topbar">
        <div>
          <img src={logo} className="ri-brand-mark" alt="Logo" aria-hidden="true" />
          <strong>{__("Request Inspector", "request-inspector")}</strong>
          <span className="ri-muted">/ {view}</span>
        </div>
        <div className="ri-actions">
          <span
            className={`ri-recording ${
              settings?.settings.enabled ? "is-on" : ""
            }`}
          >
            <i />
            {settings?.settings.enabled
              ? __("Recording ON", "request-inspector")
              : __("Recording OFF", "request-inspector")}
          </span>
          <code>
            WP {health?.wp || "—"} · PHP {health?.php || "—"}
          </code>
          <a
            href="https://wordpress.org/documentation/article/debugging-in-wordpress/"
            target="_blank"
            rel="noreferrer"
          >
            {__("Docs", "request-inspector")}
          </a>
        </div>
      </header>
      <nav
        className="ri-nav"
        aria-label={__("Inspector navigation", "request-inspector")}
      >
        {[
          ["explorer", __("Request Explorer", "request-inspector")],
          ["database", __("Database", "request-inspector")],
          ["php", __("PHP Errors", "request-inspector")],
          ["hooks", __("Hooks", "request-inspector")],
          ["workflows", __("Sessions & Reports", "request-inspector")],
          ["settings", __("Settings", "request-inspector")],
          ["advanced", __("Advanced", "request-inspector")],
        ]
          .filter(
            ([key]) =>
              !["settings", "advanced"].includes(key) || settings?.can_manage,
          )
          .map(([key, label]) => (
            <button
              key={key}
              aria-current={view === key ? "page" : undefined}
              onClick={() => navigate(key)}
            >
              {label}
            </button>
          ))}
      </nav>
      <main className="ri-content">
        {error && <Notice error>{error}</Notice>}
        {message && <Notice>{message}</Notice>}
        {!settings && !error ? <Loading layout={id ? 'detail' : ['settings', 'advanced'].includes(view) ? 'form' : view === 'explorer' ? 'explorer' : view === 'workflows' ? 'overview' : 'table'} /> : id && !drawer ? (
          <Detail
            key={id}
            id={id}
            onClose={close}
            onOpen={open}
            components={components}
            canManage={!!settings?.can_manage}
            changed={refresh}
          />
        ) : (
          <>
            {view === "hooks" ? (
              <HookPage />
            ) : view === "workflows" ? (
              <WorkflowsPage canManage={!!settings?.can_manage} />
            ) : view === "advanced" && settings?.can_manage ? (
              <AdvancedPage initial={settings} onSaved={refresh} />
            ) : view === "settings" && settings ? (
              <SettingsPage
                initial={settings}
                health={health}
                onSaved={refresh}
              />
            ) : view === "database" || view === "php" ? (
              <>
                <h1>
                  {view === "database"
                    ? __("Database Inspector", "request-inspector")
                    : __("PHP Error Inspector", "request-inspector")}
                </h1>
                <Events
                  key={view}
                  type={view === "database" ? "db_query" : "error"}
                  components={components}
                  openRequest={open}
                />
              </>
            ) : (
              <>
                <div className="ri-section-head">
                  <div>
                    <p className="ri-eyebrow">
                      {__("WORDPRESS REQUEST ACTIVITY", "request-inspector")}
                    </p>
                    <h1>{__("Request Explorer", "request-inspector")}</h1>
                  </div>
                  <div className="ri-actions">
                    <button onClick={refresh}>
                      {__("Refresh", "request-inspector")}
                    </button>
                    {settings?.can_manage && (
                      <button
                        onClick={async () => {
                          try {
                            await apiFetch({
                              path: `${base}/settings`,
                              method: "POST",
                              data: {
                                revision: settings.settings.revision,
                                enabled: !settings.settings.enabled,
                              },
                            });
                            refresh();
                          } catch (err) {
                            setError(errorText(err));
                          }
                        }}
                      >
                        {settings.settings.enabled
                          ? __("Pause recording", "request-inspector")
                          : __("Enable recording", "request-inspector")}
                      </button>
                    )}
                  </div>
                </div>
                <Cards
                  values={[
                    [
                      __("Total requests", "request-inspector"),
                      stats?.total || "0",
                    ],
                    [
                      __("Slow requests", "request-inspector"),
                      stats?.slow || "0",
                    ],
                    [
                      __("Failed requests", "request-inspector"),
                      stats?.failed || "0",
                    ],
                    [
                      __("External API failures", "request-inspector"),
                      stats?.external_failed || "0",
                    ],
                  ]}
                />
                <div className="ri-filters">
                  <label className="ri-search">
                    {__("Search URL", "request-inspector")}
                    <input
                      type="search"
                      value={filters.search || ""}
                      onChange={(e) => filter("search", e.target.value)}
                      placeholder={__(
                        "Filter by URL or route…",
                        "request-inspector",
                      )}
                    />
                  </label>
                  {select(
                    "method",
                    __("Method", "request-inspector"),
                    [
                      "GET",
                      "POST",
                      "PUT",
                      "PATCH",
                      "DELETE",
                      "HEAD",
                      "OPTIONS",
                    ].map((s) => [s, s]),
                  )}
                  {select(
                    "component",
                    __("Component", "request-inspector"),
                    components.map((c) => [c, c]),
                  )}
                  {select(
                    "type",
                    __("Type", "request-inspector"),
                    [
                      "rest",
                      "ajax",
                      "frontend",
                      "admin",
                      "cron",
                      "cli",
                      "login",
                    ].map((c) => [c, c]),
                  )}
                  {select("direction", __("Direction", "request-inspector"), [
                    ["incoming", __("Incoming", "request-inspector")],
                    ["outgoing", __("Outgoing", "request-inspector")],
                  ])}
                  <label>
                    {__("Status", "request-inspector")}
                    <select
                      value={filters.status_min || ""}
                      onChange={(e) => {
                        const value = e.target.value;
                        filter("status_min", value, {
                          status_max: value ? String(Number(value) + 99) : "",
                        });
                      }}
                    >
                      <option value="">{__("All", "request-inspector")}</option>
                      {["200", "300", "400", "500"].map((s) => (
                        <option key={s} value={s}>
                          {s[0]}xx
                        </option>
                      ))}
                    </select>
                  </label>
                  <label>
                    {__("Minimum ms", "request-inspector")}
                    <input
                      type="number"
                      min="0"
                      value={filters.duration_min || ""}
                      onChange={(e) => filter("duration_min", e.target.value)}
                    />
                  </label>
                  <label className="ri-check">
                    <input
                      type="checkbox"
                      checked={filters.error_only === "true"}
                      onChange={(e) =>
                        filter("error_only", String(e.target.checked))
                      }
                    />
                    {__("Errors only", "request-inspector")}
                  </label>
                  <label className="ri-check">
                    <input
                      type="checkbox"
                      checked={filters.include_samples === "true"}
                      onChange={(e) =>
                        filter("include_samples", String(e.target.checked))
                      }
                    />
                    {__("Include samples", "request-inspector")}
                  </label>
                  <button
                    onClick={() => {
                      setFilters({});
                      const u = new URL(window.location.href);
                      Array.from(u.searchParams.keys())
                        .filter((k) => k.startsWith("ri_f_"))
                        .forEach((k) => u.searchParams.delete(k));
                      window.history.replaceState({}, "", u);
                    }}
                  >
                    {__("Clear filters", "request-inspector")}
                  </button>
                </div>
                <div className={drawer ? "ri-explorer-split" : ""}>
                  <div>
                    <div
                      className="ri-table-wrap"
                      ref={tableRef}
                      tabIndex={-1}
                      aria-busy={busy}
                    >
                      {busy && <Loading compact={!!data} />}
                      {data?.items.length ? (
                        <table>
                          <thead>
                            <tr>
                              <th>{__("Select", "request-inspector")}</th>
                              <th>{__("Method", "request-inspector")}</th>
                              <th>{__("URL / Route", "request-inspector")}</th>
                              <th>{__("Status", "request-inspector")}</th>
                              <th>
                                <button
                                  onClick={() =>
                                    filter(
                                      "sort",
                                      filters.sort === "duration"
                                        ? "time"
                                        : "duration",
                                    )
                                  }
                                >
                                  {__("Duration", "request-inspector")} ↕
                                </button>
                              </th>
                              <th>{__("Component", "request-inspector")}</th>
                              <th>{__("Type", "request-inspector")}</th>
                            </tr>
                          </thead>
                          <tbody>
                            {data.items.map((row) => (
                              <tr
                                key={row.id}
                                className={id === row.id ? "selected" : ""}
                              >
                                <td>
                                  <input
                                    aria-label={sprintf(
                                      __(
                                        "Select request %s",
                                        "request-inspector",
                                      ),
                                      row.id,
                                    )}
                                    type="checkbox"
                                    checked={selected.includes(row.id)}
                                    onChange={(e) =>
                                      setSelected(
                                        e.target.checked
                                          ? [...selected, row.id]
                                          : selected.filter(
                                              (value) => value !== row.id,
                                            ),
                                      )
                                    }
                                  />
                                </td>
                                <td>
                                  <span className="ri-method">
                                    {row.method}
                                  </span>
                                </td>
                                <td>
                                  <button
                                    className="ri-row-link"
                                    onClick={() => open(row.id, true)}
                                  >
                                    <code>{row.url}</code>
                                  </button>
                                  {row.is_sample === "1" && (
                                    <span className="ri-badge">
                                      {__("Sample", "request-inspector")}
                                    </span>
                                  )}
                                </td>
                                <td>
                                  <Badge status={row.status} />
                                </td>
                                <td
                                  className={
                                    row.duration_ms >=
                                    (settings?.settings.slow_ms || 500)
                                      ? "ri-warning-text"
                                      : ""
                                  }
                                >
                                  <code>{formatMs(row.duration_ms)}</code>
                                </td>
                                <td>{row.component}</td>
                                <td>
                                  {row.type}
                                  <small>{row.direction}</small>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      ) : (
                        !busy && (
                          <div className="ri-empty">
                            <img src={logo} className="ri-mascot" alt="Mascot" aria-hidden="true" />
                            <h2>
                              {Object.values(filters).some(Boolean)
                                ? __(
                                    "No requests match these filters",
                                    "request-inspector",
                                  )
                                : __(
                                    "No requests captured yet",
                                    "request-inspector",
                                  )}
                            </h2>
                            <p>
                              {__(
                                "Enable recording, choose a scope, then trigger activity on your site. Only observable WordPress execution is captured.",
                                "request-inspector",
                              )}
                            </p>
                            {settings?.can_manage && (
                              <div className="ri-actions">
                                <button
                                  className="ri-primary"
                                  onClick={async () => {
                                    try {
                                      const r = await apiFetch<{
                                        message: string;
                                      }>({
                                        path: "/request-inspector-test/v1/ping",
                                      });
                                      setMessage(r.message);
                                      setTimeout(refresh, 500);
                                    } catch (err) {
                                      setError(errorText(err));
                                    }
                                  }}
                                >
                                  {__(
                                    "Trigger test REST request",
                                    "request-inspector",
                                  )}
                                </button>
                                <button
                                  onClick={async () => {
                                    try {
                                      await apiFetch({
                                        path: `${base}/sample-data`,
                                        method: "POST",
                                      });
                                      filter("include_samples", "true");
                                      refresh();
                                    } catch (err) {
                                      setError(errorText(err));
                                    }
                                  }}
                                >
                                  {__(
                                    "Load sample capture",
                                    "request-inspector",
                                  )}
                                </button>
                                <button onClick={() => navigate("settings")}>
                                  {__("Configure scope", "request-inspector")}
                                </button>
                              </div>
                            )}
                          </div>
                        )
                      )}
                    </div>
                    <div className="ri-section-head">
                      <label>
                        {__("Rows per page", "request-inspector")}
                        <select
                          value={filters.per_page || "25"}
                          onChange={(e) => filter("per_page", e.target.value)}
                        >
                          {[10, 25, 50, 100].map((n) => (
                            <option key={n}>{n}</option>
                          ))}
                        </select>
                      </label>
                      {!!selected.length && settings?.can_manage && (
                        <button
                          className="ri-danger"
                          onClick={async () => {
                            if (
                              !window.confirm(
                                __(
                                  "Delete selected traces and all related records?",
                                  "request-inspector",
                                ),
                              )
                            )
                              return;
                            try {
                              for (const selectedId of selected) {
                                try {
                                  await apiFetch({
                                    path: `${base}/requests/${selectedId}`,
                                    method: "DELETE",
                                  });
                                } catch (err) {
                                  if (
                                    (err as { code?: string }).code !==
                                    "ri_missing"
                                  )
                                    throw err;
                                }
                              }
                              refresh();
                            } catch (err) {
                              setError(errorText(err));
                            }
                          }}
                        >
                          {__("Delete selected traces", "request-inspector")}
                        </button>
                      )}
                      <Pagination
                        page={Number(filters.page) || 1}
                        size={Number(filters.per_page) || 25}
                        total={data?.total || 0}
                        onPage={(n) => filter("page", String(n))}
                      />
                    </div>
                  </div>
                  {drawer && id && (
                    <Detail
                      key={id}
                      drawer
                      id={id}
                      onClose={close}
                      onOpen={(requestId) => open(requestId, false)}
                      components={components}
                      canManage={!!settings?.can_manage}
                      changed={refresh}
                    />
                  )}
                </div>
              </>
            )}
          </>
        )}
        {settings && !settings.can_manage && <LivePreferences />}
        <footer className="ri-footer">
          {__(
            "Local diagnostics · body capture is opt-in · no external telemetry",
            "request-inspector",
          )}
        </footer>
      </main>
    </div>
  );
}
const root = document.getElementById("request-inspector-app");
if (root) createRoot(root).render(<App />);

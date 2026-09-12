import { useEffect, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";
import type { Settings, SettingsResponse } from "./types";

export function AdvancedPage({
  initial,
  onSaved,
}: {
  initial: SettingsResponse;
  onSaved: () => void;
}) {
  const [draft, setDraft] = useState(initial.settings),
    [message, setMessage] = useState(""),
    [result, setResult] = useState<unknown>(),
    [operation, setOperation] = useState("preview"),
    [input, setInput] = useState(""),
    [busy, setBusy] = useState(false);
  useEffect(() => setDraft(initial.settings), [initial]);
  const lists: [keyof Settings, string][] = [
    [
      "capture_cidrs",
      __("Capture CIDRs (empty allows all)", "request-inspector"),
    ],
    ["trusted_proxies", __("Trusted proxy CIDRs", "request-inspector")],
    [
      "redaction_patterns",
      __("Additional redaction patterns", "request-inspector"),
    ],
    ["replay_hosts", __("Replay HTTPS host allowlist", "request-inspector")],
  ];
  async function run() {
    setBusy(true);
    try {
      let data: Record<string, unknown> = {};
      if (operation === "replay") {
        if (
          !window.confirm(
            __(
              "Send a GET request to the reviewed URL? Even GET can have side effects. No credentials or body will be restored.",
              "request-inspector",
            ),
          )
        )
          return;
        data = { url: input, method: "GET", confirm: true };
      }
      if (operation === "explain") {
        if (
          !window.confirm(
            __(
              "Explain this synthetic SELECT on staging? No stored raw SQL is restored.",
              "request-inspector",
            ),
          )
        )
          return;
        data = { sql: input, confirm: true };
      }
      if (operation === "search") data = { pattern: input, field: "url" };
      setResult(
        await apiFetch({
          path: `/request-inspector/v1/advanced/${operation}`,
          method: ["audit", "integrations"].includes(operation)
            ? "GET"
            : "POST",
          data: ["audit", "integrations"].includes(operation)
            ? undefined
            : data,
        }),
      );
      setMessage("");
    } catch (e) {
      setMessage((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section>
      <h1>{__("Advanced controls", "request-inspector")}</h1>
      <p>
        {__(
          "All network and analysis controls are opt-in. Forwarded addresses are accepted only from configured proxies. CIDR restrictions exclude requests without a matching network address, including CLI captures.",
          "request-inspector",
        )}
      </p>
      {message && <p role="alert">{message}</p>}
      <div className="ri-settings-grid">
        {lists.map(([key, label]) => (
          <label key={key}>
            {label}
            <textarea
              rows={3}
              value={(draft[key] as string[]).join("\n")}
              onChange={(e) =>
                setDraft({
                  ...draft,
                  [key]: e.target.value.split("\n").filter(Boolean),
                })
              }
            />
          </label>
        ))}
      </div>
      <p>
        {__(
          "Patterns support literals, character classes and bounded repetition up to 64, for example INV-[0-9]{5}. Groups, alternation, backreferences and unbounded repetition are rejected. Default redaction cannot be disabled.",
          "request-inspector",
        )}
      </p>
      {(["adaptive_sampling", "replay_enabled"] as const).map((key) => (
        <label className="ri-check" key={key}>
          <input
            type="checkbox"
            checked={draft[key]}
            onChange={(e) => setDraft({ ...draft, [key]: e.target.checked })}
          />
          {key === "adaptive_sampling"
            ? __(
                "Reduce sampling tenfold above 80% storage pressure",
                "request-inspector",
              )
            : __("Enable reviewed replay on staging only", "request-inspector")}
        </label>
      ))}
      <button
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          try {
            await apiFetch({
              path: "/request-inspector/v1/settings",
              method: "POST",
              data: draft,
            });
            setMessage(__("Policy saved.", "request-inspector"));
            onSaved();
          } catch (e) {
            setMessage((e as Error).message);
          } finally {
            setBusy(false);
          }
        }}
      >
        {__("Save advanced policy", "request-inspector")}
      </button>
      <hr />
      <h2>{__("Reviewed diagnostic actions", "request-inspector")}</h2>
      <label>
        {__("Action", "request-inspector")}
        <select
          value={operation}
          onChange={(e) => {
            setOperation(e.target.value);
            setResult(undefined);
            setInput("");
          }}
        >
          <option value="preview">
            {__("Synthetic redaction preview", "request-inspector")}
          </option>
          <option value="integrations">
            {__("Integration coverage", "request-inspector")}
          </option>
          <option value="audit">
            {__("Recent audit operations", "request-inspector")}
          </option>
          <option value="search">
            {__("Restricted URL pattern search", "request-inspector")}
          </option>
          <option value="explain">
            {__(
              "Explain synthetic SELECT (MariaDB staging)",
              "request-inspector",
            )}
          </option>
          <option value="replay">
            {__("Reviewed HTTPS GET replay (staging)", "request-inspector")}
          </option>
        </select>
      </label>
      {["replay", "explain", "search"].includes(operation) && (
        <label>
          {__("Reviewed synthetic input", "request-inspector")}
          <textarea
            rows={3}
            value={input}
            onChange={(e) => setInput(e.target.value)}
          />
        </label>
      )}
      <button disabled={busy} onClick={run}>
        {__("Run selected action", "request-inspector")}
      </button>
      {result !== undefined && (
        <pre tabIndex={0}>{JSON.stringify(result, null, 2)}</pre>
      )}
    </section>
  );
}

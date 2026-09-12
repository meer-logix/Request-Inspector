import { useEffect, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { __ } from "@wordpress/i18n";

type Scope = {
  id: string;
  name: string;
  owner: number;
  started: string;
  stopped: string | null;
  expires: number;
  shared_with: number[];
};
export function WorkflowsPage({ canManage }: { canManage: boolean }) {
  const [scopes, setScopes] = useState<Scope[]>([]),
    [name, setName] = useState(""),
    [input, setInput] = useState(""),
    [users, setUsers] = useState(""),
    [operation, setOperation] = useState("compare"),
    [output, setOutput] = useState<unknown>(),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  const call = (action: string, data: unknown) =>
    apiFetch({
      path: `/request-inspector/v1/workflows/${action}`,
      method: "POST",
      data,
    });
  async function scope(operation: string, id?: string) {
    setBusy(true);
    try {
      const result = await call("sessions", {
        operation,
        id,
        name,
        users: users.split(",").filter(Boolean).map(Number),
      });
      if (operation === "show") setOutput(result);
      else setScopes(result as Scope[]);
      setError("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  useEffect(() => {
    scope("list");
  }, []);
  return (
    <section>
      <h1>{__("Sessions and comparison", "request-inspector")}</h1>
      <p>
        {__(
          "Saved scopes name a recording time window. They do not enable recording, copy captures or bypass global retention. Scopes expire within seven days. Sharing requires an existing local user with diagnostic viewing permission; there are no public links.",
          "request-inspector",
        )}
      </p>
      {error && <p role="alert">{error}</p>}
      {canManage && (
        <div className="ri-actions">
          <label>
            {__("New scope name", "request-inspector")}
            <input
              maxLength={80}
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </label>
          <button
            disabled={busy || !name.trim()}
            onClick={() => scope("create")}
          >
            {__("Start saved scope", "request-inspector")}
          </button>
          <label>
            {__(
              "Share with local user IDs (comma separated)",
              "request-inspector",
            )}
            <input value={users} onChange={(e) => setUsers(e.target.value)} />
          </label>
        </div>
      )}
      {scopes.map((s) => (
        <article className="ri-card" key={s.id}>
          <h2>{s.name}</h2>
          <code>{s.id}</code>
          <p>
            {s.started} → {s.stopped || __("Open", "request-inspector")} UTC
          </p>
          <p>
            {__("Shared users:", "request-inspector")}{" "}
            {s.shared_with.join(", ") || "—"}
          </p>
          <div className="ri-actions">
            <button disabled={busy} onClick={() => scope("show", s.id)}>
              {__("Inspect scope", "request-inspector")}
            </button>
            {canManage && (
              <>
                <button
                  disabled={busy || !!s.stopped}
                  onClick={() => scope("stop", s.id)}
                >
                  {__("Stop scope", "request-inspector")}
                </button>
                <button disabled={busy} onClick={() => scope("share", s.id)}>
                  {__("Share with listed users", "request-inspector")}
                </button>
                <button disabled={busy} onClick={() => scope("revoke", s.id)}>
                  {__("Revoke sharing", "request-inspector")}
                </button>
                <button
                  disabled={busy}
                  onClick={() => {
                    if (
                      window.confirm(
                        __(
                          "Remove saved scope metadata? Captures retain their normal lifetime.",
                          "request-inspector",
                        ),
                      )
                    )
                      scope("delete", s.id);
                  }}
                >
                  {__("Remove scope", "request-inspector")}
                </button>
              </>
            )}
          </div>
        </article>
      ))}
      <h2>{__("Compare and report", "request-inspector")}</h2>
      <label>
        {__("Workflow", "request-inspector")}
        <select
          value={operation}
          onChange={(e) => {
            setOperation(e.target.value);
            setOutput(undefined);
          }}
        >
          <option value="compare">
            {__("Compare 2–5 request IDs", "request-inspector")}
          </option>
          <option value="report">
            {__("Component activity report", "request-inspector")}
          </option>
          <option value="regression">
            {__(
              "Regression review: baseline and candidate scope IDs",
              "request-inspector",
            )}
          </option>
        </select>
      </label>
      {operation !== "report" && (
        <label>
          {__("IDs separated by commas", "request-inspector")}
          <input value={input} onChange={(e) => setInput(e.target.value)} />
        </label>
      )}
      <button
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          try {
            const ids = input
              .split(",")
              .map((v) => v.trim())
              .filter(Boolean);
            setOutput(
              await call(
                operation,
                operation === "compare"
                  ? { ids }
                  : operation === "regression"
                  ? { baseline: ids[0], candidate: ids[1] }
                  : {},
              ),
            );
            setError("");
          } catch (e) {
            setError((e as Error).message);
          } finally {
            setBusy(false);
          }
        }}
      >
        {__("Run workflow", "request-inspector")}
      </button>
      <p>
        {__(
          "Regression review needs at least ten comparable samples per scope and rejects overlapping or mismatched samples. Signals require a >20% and >10 ms mean increase beyond the estimated uncertainty. Notifications are disabled.",
          "request-inspector",
        )}
      </p>
      {output !== undefined && (
        <pre tabIndex={0}>{JSON.stringify(output, null, 2)}</pre>
      )}
    </section>
  );
}

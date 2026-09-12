import { useState, useEffect } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import type { Capture, Body, Page } from "../types";
import { errorText } from "../utils/format";

const base = "/request-inspector/v1";

export function useCapture(id: string, tab: string) {
  const [record, setRecord] = useState<Capture>();
  const [bodies, setBodies] = useState<Record<string, Body>>();
  const [children, setChildren] = useState<Capture[]>([]);
  const [error, setError] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    setRecord(undefined);
    setBodies(undefined);
    setChildren([]);
    setError("");
    apiFetch<Capture>({
      path: `${base}/requests/${id}`,
      signal: controller.signal,
    })
      .then((row) => {
        setRecord(row);
        setError("");
        apiFetch<Page<Capture>>({
          path: `${base}/requests?trace_id=${row.trace_id}&include_samples=true&per_page=100`,
          signal: controller.signal,
        })
          .then((p) =>
            setChildren(
              p.items.filter(
                (child) =>
                  child.parent_id === id && child.direction === "outgoing",
              ),
            ),
          )
          .catch((err) => {
            if (!controller.signal.aborted) setError(errorText(err));
          });
      })
      .catch((err) => {
        if (!controller.signal.aborted) setError(errorText(err));
      });
    return () => controller.abort();
  }, [id]);

  useEffect(() => {
    if (!["request", "response"].includes(tab) || bodies) return;
    const controller = new AbortController();
    apiFetch<Record<string, Body>>({
      path: `${base}/requests/${id}/bodies`,
      signal: controller.signal,
    })
      .then(setBodies)
      .catch((err) => {
        if (!controller.signal.aborted) setError(errorText(err));
      });
    return () => controller.abort();
  }, [tab, id, bodies]);

  return { record, bodies, children, error };
}

import { useState, useEffect } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import type { Event, Page } from "../types";
import { errorText } from "../utils/format";

export function useEvents({
  requestId,
  type,
  page,
  search,
  component,
  severity,
  slow,
  duplicates,
}: {
  requestId?: string;
  type: "db_query" | "error";
  page: number;
  search: string;
  component: string;
  severity: string;
  slow: boolean;
  duplicates: boolean;
}) {
  const [data, setData] = useState<Page<Event>>();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    const timer = setTimeout(() => {
      setBusy(true);
      const q = new URLSearchParams({
        event_type: type,
        page: String(page),
        per_page: "25",
        search,
        component,
        severity,
        slow_only: String(slow),
        duplicates_only: String(duplicates),
      });
      const base = "/request-inspector/v1";
      const path = requestId
        ? `${base}/requests/${requestId}/events?${q}`
        : `${base}/events?${q}`;

      apiFetch<Page<Event>>({
        path,
        signal: controller.signal,
      })
        .then((result) => {
          setData(result);
          setError("");
        })
        .catch((err) => {
          if (!controller.signal.aborted) setError(errorText(err));
        })
        .finally(() => {
          if (!controller.signal.aborted) setBusy(false);
        });
    }, search ? 200 : 0);

    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [requestId, type, page, search, component, severity, slow, duplicates]);

  return { data, error, busy };
}

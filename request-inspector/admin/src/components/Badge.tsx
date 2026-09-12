import React from "react";
import { __ } from "@wordpress/i18n";

export function Badge({ status }: { status: string | null }) {
  return (
    <span
      className={`ri-badge ri-status-${status ? status.charAt(0) : "unknown"}`}
    >
      {status || __("Unavailable", "request-inspector")}
    </span>
  );
}

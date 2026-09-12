import React from "react";
import { __, sprintf } from "@wordpress/i18n";

export function Pagination({
  page,
  total,
  size,
  onPage,
}: {
  page: number;
  total: number;
  size: number;
  onPage: (n: number) => void;
}) {
  const max = Math.max(1, Math.ceil(total / size));
  return (
    <div className="ri-pagination">
      <span>
        {sprintf(
          __("Page %1$d of %2$d · %3$d records", "request-inspector"),
          page,
          max,
          total,
        )}
      </span>
      <button disabled={page <= 1} onClick={() => onPage(page - 1)}>
        {__("Previous", "request-inspector")}
      </button>
      <button disabled={page >= max} onClick={() => onPage(page + 1)}>
        {__("Next", "request-inspector")}
      </button>
    </div>
  );
}

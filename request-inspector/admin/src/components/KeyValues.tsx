import React from "react";

export function KeyValues({ values }: { values: Record<string, unknown> }) {
  return (
    <dl className="ri-kv">
      {Object.entries(values).map(([key, value]) => (
        <div key={key}>
          <dt>{key}</dt>
          <dd>
            {typeof value === "object"
              ? JSON.stringify(value)
              : String(value ?? "—")}
          </dd>
        </div>
      ))}
    </dl>
  );
}

import React from "react";

export function Notice({
  children,
  error = false,
}: {
  children: React.ReactNode;
  error?: boolean;
}) {
  return (
    <div
      className={`ri-notice ${error ? "ri-error" : ""}`}
      role={error ? "alert" : "status"}
    >
      {children}
    </div>
  );
}

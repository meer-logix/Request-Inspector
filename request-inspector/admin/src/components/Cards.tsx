import React from "react";

export function Cards({ values }: { values: [string, React.ReactNode][] }) {
  return (
    <div className="ri-cards">
      {values.map(([name, value]) => (
        <section key={name} className="ri-card">
          <span>{name}</span>
          <strong>{value}</strong>
        </section>
      ))}
    </div>
  );
}

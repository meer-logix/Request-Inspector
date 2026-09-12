import { __ } from "@wordpress/i18n";
import { DiagnosticSkeleton, type SkeletonLayout } from "./DiagnosticSkeleton";

export function Loading({ compact = false, layout = 'table' }: { compact?: boolean; layout?: SkeletonLayout }) {
  if (!compact) return <DiagnosticSkeleton layout={layout} />;
  return (
    <div className={compact ? "ri-progress" : "ri-skeleton"} role="status" aria-label={__("Loading diagnostics", "request-inspector")}>
      <span className="screen-reader-text">{__("Loading diagnostics", "request-inspector")}</span>
      <div className="ri-skeleton-lines" aria-hidden="true"><span /></div>
    </div>
  );
}

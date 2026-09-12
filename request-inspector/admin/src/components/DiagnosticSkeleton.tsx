import { __ } from '@wordpress/i18n';
import './diagnostic-skeleton.css';

export type SkeletonLayout = 'explorer' | 'overview' | 'table' | 'detail' | 'form' | 'facts' | 'timeline' | 'events' | 'code';

/** Mirrors the content geometry while leaving the surrounding application shell visible. */
export function DiagnosticSkeleton({ layout = 'table' }: { layout?: SkeletonLayout }) {
  const bars = (count: number) => Array.from({ length: count }, (_, index) => <span className="ri-placeholder-bar" key={index} />);
  const cards = <div className="ri-placeholder-cards">{Array.from({ length: 4 }, (_, index) => <div className="ri-placeholder-card" key={index}><span className="ri-placeholder-bar ri-placeholder-label" /><span className="ri-placeholder-bar ri-placeholder-value" /></div>)}</div>;
  const facts = <div className="ri-placeholder-facts">{Array.from({ length: 6 }, (_, index) => <div key={index}>{bars(2)}</div>)}</div>;
  const filters = <div className="ri-placeholder-filters">{Array.from({ length: 3 }, (_, index) => <div key={index}><span className="ri-placeholder-bar ri-placeholder-label" /><span className="ri-placeholder-control" /></div>)}</div>;
  const table = <div className="ri-placeholder-table"><div className="ri-placeholder-table-head">{bars(7)}</div>{Array.from({ length: 6 }, (_, index) => <div className="ri-placeholder-table-row" key={index}>{bars(7)}</div>)}</div>;
  return <div className={`ri-placeholder ri-placeholder-${layout}`} role="status" aria-label={__('Preparing diagnostics', 'request-inspector')} aria-busy="true">
    <div aria-hidden="true">
      {layout !== 'table' && layout !== 'code' && <div className="ri-placeholder-heading"><span className="ri-placeholder-bar" /><span className="ri-placeholder-badge" /></div>}
      {(layout === 'overview' || layout === 'detail') && <div className="ri-placeholder-route">{bars(3)}</div>}
      {(layout === 'overview' || layout === 'explorer') && cards}
      {(layout === 'overview' || layout === 'facts' || layout === 'detail') && facts}
      {(layout === 'explorer' || layout === 'events' || layout === 'timeline') && filters}
      {(layout === 'explorer' || layout === 'table') && table}
      {layout === 'form' && <div className="ri-placeholder-form">{Array.from({ length: 6 }, (_, index) => <div key={index}><span className="ri-placeholder-bar ri-placeholder-label" /><span className="ri-placeholder-control" /></div>)}</div>}
      {layout === 'events' && <div className="ri-placeholder-events">{Array.from({ length: 6 }, (_, index) => <div key={index}>{bars(3)}</div>)}</div>}
      {layout === 'timeline' && <div className="ri-placeholder-timeline">{Array.from({ length: 6 }, (_, index) => <div key={index}><span className="ri-placeholder-bar" /><span className={`ri-placeholder-span ri-placeholder-span-${index % 3}`} /><span className="ri-placeholder-bar" /></div>)}</div>}
      {layout === 'code' && <div className="ri-placeholder-code">{bars(8)}</div>}
    </div>
  </div>;
}

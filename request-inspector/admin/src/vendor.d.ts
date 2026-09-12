declare module '@wordpress/api-fetch' {
 const apiFetch: <T = unknown>(options: {path: string; method?: string; data?: unknown; signal?: AbortSignal}) => Promise<T>;
 export default apiFetch;
}
declare module '@wordpress/element' {
 export {useState, useEffect, useRef, useCallback, useMemo, Fragment} from 'react';
 export function createRoot(element: Element): {render(node: import('react').ReactNode): void};
}
declare module '@wordpress/i18n' {
 export function __(text: string, domain: string): string;
 export function sprintf(format: string, ...args: (string|number)[]): string;
}
declare module '*.css';
declare module '*.png' {
  const src: string;
  export default src;
}

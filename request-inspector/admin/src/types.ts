export interface Settings {
  live_enabled: boolean;
  live_frontend: boolean;
  live_events: boolean;
  live_caps: boolean;
  capture_cidrs: string[];
  trusted_proxies: string[];
  redaction_patterns: string[];
  replay_hosts: string[];
  adaptive_sampling: boolean;
  replay_enabled: boolean;
  enabled: boolean;
  mode: string;
  request_body: boolean;
  response_body: boolean;
  database: boolean;
  hooks: boolean;
  php_errors: boolean;
  stack_traces: boolean;
  production_guard: boolean;
  max_records: number;
  retention_days: number;
  max_storage_mb: number;
  max_body_kb: number;
  slow_ms: number;
  slow_query_ms: number;
  sample_percent: number;
  component: string;
  custom_keys: string[];
  view_capability: string;
  delete_on_uninstall: boolean;
  revision: number;
}
export interface Caller {
  component?: string;
  file?: string;
  line?: number;
  function?: string;
  trace?: Caller[];
}
export interface Body {
  content: string | null;
  content_type: string;
  reason: string | null;
  original_size: number | null;
  stored_size: number;
  is_truncated: boolean;
}
export interface Metadata {
  hooks_available?: boolean;
  start_offset_us?: number;
  caller?: Caller;
  request_headers?: Record<string, unknown>;
  response_headers?: Record<string, unknown>;
  queries?: number;
  query_us?: number;
  issues?: number;
  dropped?: number;
  wp?: string;
  php?: string;
  memory_peak?: number;
  coverage?: string;
  database_available?: boolean;
  php_available?: boolean;
  severity?: string;
  duplicate_count?: number;
  is_slow?: boolean;
  source_reason?: string;
}
export interface Capture {
  id: string;
  trace_id: string;
  parent_id: string | null;
  direction: string;
  type: string;
  method: string;
  url: string;
  status: string | null;
  duration_ms: number;
  component: string;
  started_at: string;
  has_error: string;
  is_sample: string;
  metadata: Metadata;
}
export interface Event {
  id: string;
  request_id: string;
  event_type: string;
  name: string;
  component: string;
  duration_ms?: number;
  start_offset_us?: string;
  metadata: Metadata;
}
export interface Page<T> {
  items: T[];
  total: number;
  page: number;
  per_page: number;
}
export interface Health {
  wp: string;
  php: string;
  database_available: boolean;
  detailed_allowed: boolean;
  state: {
    used_bytes: string;
    records: string;
    remaining: string;
    expires: string;
  };
}
export interface SettingsResponse {
  settings: Settings;
  can_manage: boolean;
  detailed_allowed: boolean;
}
declare global {
  interface Window {
    wp: unknown;
  }
}

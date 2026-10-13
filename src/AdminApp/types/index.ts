export type Tone = 'neutral' | 'success' | 'info' | 'warning' | 'error';

export const checkoutModes = [ 'off', 'block', 'hold', 'warn' ] as const;
export type CheckoutMode = (typeof checkoutModes)[number];

export interface CheckoutSettingsResponse {
  mode: CheckoutMode;
  message: string;
  message_max_length: number;
}

export interface CheckoutSettingsInput {
  mode?: CheckoutMode;
  message?: string;
}

export interface OverviewResponse {
  product: string;
  version: string;
  status: 'foundation_ready';
  woocommerce: {
    active: boolean;
    version: string | null;
    hpos: 'compatible';
  };
  modules: unknown[];
  checkout_protection: {
    mode: CheckoutMode;
    storage: {
      status: 'healthy' | 'unavailable';
    };
  };
  blocklist: BlocklistCounts;
  activity: {
    counts: {
      total: number;
      today: number;
      last_7_days: number;
    };
    recent: ActivityEvent[];
  };
}

export type BlocklistType = 'email' | 'domain' | 'phone' | 'ip';

export interface BlocklistCounts {
  total: number;
  email: number;
  domain: number;
  phone: number;
  ip: number;
}

export interface BlocklistEntry {
  id: number;
  type: BlocklistType;
  value: string;
  normalized_value: string;
  note: string;
  created_at: string;
  updated_at: string;
}

export interface BlocklistResponse {
  items: BlocklistEntry[];
  pagination: {
    page: number;
    per_page: number;
    total_items: number;
    total_pages: number;
  };
  counts: BlocklistCounts;
}

export interface BlocklistInput {
  type: BlocklistType;
  value: string;
  note: string;
}

export type ImportDiagnosticCategory = 'duplicate' | 'invalid' | 'storage_failure';

export interface ImportDiagnostic {
  row: number;
  category: ImportDiagnosticCategory;
  field: string;
  code: string;
  message: string;
}

export interface BlocklistImportResult {
  processed: number;
  imported: number;
  duplicates: number;
  invalid: number;
  storage_failures: number;
  diagnostics: ImportDiagnostic[];
  diagnostics_limited: boolean;
}

export type ActivityEventType = 'block' | 'hold' | 'warn' | 'blocklist';
export type ActivityFilter = '' | 'checkout' | ActivityEventType;
export type ActivityOutcome = 'rejected' | 'held' | 'flagged' | 'added' | 'edited' | 'unblocked' | 'unknown';
export type ActivitySource = 'classic' | 'blocks' | 'admin' | 'order_panel' | 'unknown';

export interface ActivityEvent {
  id: number;
  event_type: ActivityEventType;
  outcome: ActivityOutcome;
  source: ActivitySource;
  order_id: number | null;
  order: {
    id: number;
    number: string;
    edit_url: string | null;
  } | null;
  matched_types: BlocklistType[];
  matched_identifiers: Array<{
    type: BlocklistType;
    value: string;
  }>;
  occurrence_count: number;
  created_at: string;
}

export interface ActivityResponse {
  items: ActivityEvent[];
  pagination: {
    page: number;
    per_page: number;
    total_items: number;
    total_pages: number;
  };
  counts: {
    total: number;
    block: number;
    hold: number;
    warn: number;
  };
}

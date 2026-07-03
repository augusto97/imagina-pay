/**
 * Tipos espejo del API impay/v1 (Presenter.php).
 */

export interface Price {
  uuid: string;
  currency: string;
  amount: number;
  formatted: string;
  interval: 'one_time' | 'month' | 'year';
  trial_days: number;
  status: 'active' | 'archived';
  gateway_refs: Record<string, string> | null;
}

export interface Product {
  uuid: string;
  name: string;
  slug: string;
  type: 'one_time' | 'subscription' | 'annual_hybrid';
  description: string | null;
  features: string[] | null;
  image_url: string | null;
  status: 'active' | 'archived' | 'draft';
  provisioning: { type?: string; updater_product_id?: number } | null;
  prices: Price[];
  checkout_url?: string;
  created_at: string;
}

export interface Customer {
  uuid: string;
  email: string;
  full_name: string;
  company: string | null;
  tax_id_type: string | null;
  tax_id: string | null;
  country: string;
  phone: string | null;
  created_at: string;
}

export interface Subscription {
  uuid: string;
  gateway: string;
  gateway_sub_id: string | null;
  status: 'pending' | 'active' | 'past_due' | 'paused' | 'cancelled' | 'expired';
  status_label: string;
  current_period_start: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  failed_payments: number;
  license_key: string | null;
  manual_task_pending: boolean;
  customer: Customer | null;
  product: { uuid: string; name: string; type: string } | null;
  created_at: string;
}

export interface Payment {
  uuid: string;
  gateway: string;
  gateway_payment_id: string;
  status: string;
  currency: string;
  amount: number;
  formatted: string;
  method: string | null;
  paid_at: string | null;
  customer: Customer | null;
  created_at: string;
}

export interface Order {
  uuid: string;
  kind: string;
  status: string;
  currency: string;
  amount: number;
  formatted: string;
  gateway: string;
  paid_at: string | null;
  customer: Customer | null;
  product: { uuid: string; name: string } | null;
  created_at: string;
}

export interface WebhookEvent {
  id: number;
  gateway: string;
  event_id: string;
  topic: string;
  status: string;
  error: string | null;
  attempts: number;
  payload: Record<string, unknown> | null;
  received_at: string;
  processed_at: string | null;
}

export interface CurrencyAmount {
  currency: string;
  amount: number;
  formatted: string;
}

export interface DashboardMetrics {
  products_count: number;
  mrr: CurrencyAmount[];
  active_subscriptions: number;
  past_due_subscriptions: number;
  month_revenue: CurrencyAmount[];
  revenue_12m: { month: string; currency: string; amount: number }[];
  upcoming_renewals: Subscription[];
  manual_tasks: Subscription[];
  webhook_health: Record<string, string>;
}

export interface Paginated<T> {
  items: T[];
  total: number;
}

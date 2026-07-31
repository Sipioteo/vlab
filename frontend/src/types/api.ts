/**
 * Hand-written TS mirrors of SPEC §7.4 canonical representations.
 * Field names are FROZEN — never rename.
 */

export type Role = 'student' | 'technician' | 'assistant' | 'admin';

export type OrderStatus =
  | 'draft'
  | 'pending'
  | 'approved'
  | 'rejected'
  | 'cancelled'
  | 'picked_up'
  | 'overdue'
  | 'returned'
  | 'returned_late'
  | 'no_show';

export type OrderAction =
  | 'submit'
  | 'approve'
  | 'reject'
  | 'cancel'
  | 'pickup'
  | 'return'
  | 'mark_no_show'
  | 'mark_overdue'
  | 'reopen'
  | 'note'
  | 'edit';

export type ActorType = 'user' | 'system';
export type ProductStatus = 'available' | 'maintenance' | 'retired';
export type UnitStatus = 'available' | 'maintenance' | 'missing' | 'retired' | 'internal_use';
export type LoanMode = 'takeaway' | 'on_site_only';
export type LogType = 'damage' | 'maintenance' | 'inspection' | 'note' | 'loss' | 'repair';
export type LogSeverity = 'info' | 'warning' | 'critical';
export type ConditionValue = 'ok' | 'damaged' | 'incomplete' | 'missing';
export type RegulationScope = 'global' | 'category' | 'product';
export type RegulationContentType = 'markdown' | 'pdf';
export type RecommendationRelation = 'accessory' | 'alternative' | 'required_with';
export type SettingType = 'string' | 'int' | 'bool' | 'json' | 'time' | 'date' | 'enum' | 'secret';
export type SettingGroupKey =
  | 'lab'
  | 'hours'
  | 'booking'
  | 'regulations'
  | 'ldap'
  | 'security'
  | 'notifications'
  | 'ui'
  | 'stats';
export type ViolationSeverity = 'soft' | 'hard';
export type StatsGranularity = 'day' | 'week' | 'month';
export type BannerLevel = 'info' | 'warning' | 'danger';

/* ------------------------------------------------------------------ envelopes */

export interface PageMeta {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PageMeta | null;
}

export interface Collection<T> {
  data: T[];
  meta: null;
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    details: Record<string, unknown> | null;
    trace_id: string;
  };
}

/* ------------------------------------------------------------------ resources */

export interface User {
  id: number;
  ldap_uid: string;
  email: string;
  first_name: string;
  last_name: string;
  display_name: string;
  role: Role;
  role_label: string;
  role_locked?: boolean;
  matricola: string | null;
  course: string | null;
  phone: string | null;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
  notes?: string | null;
  /** aggregates added by GET /users */
  orders_count?: number;
  active_orders_count?: number;
  late_returns_count?: number;
  recent_orders?: OrderSummary[];
}

export interface UserRef {
  id: number;
  display_name: string;
  ldap_uid?: string;
  role?: Role;
}

export interface Category {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  icon: string | null;
  image_url: string | null;
  parent_id: number | null;
  position: number;
  is_active: boolean;
  products_count: number;
  regulations?: RegulationRef[];
}

export interface CategoryRef {
  id: number;
  slug: string;
  name: string;
}

export interface ProductSummary {
  id: number;
  slug: string;
  name: string;
  brand: string | null;
  model: string | null;
  category: CategoryRef;
  image_url: string | null;
  status: ProductStatus;
  loan_mode: LoanMode;
  requires_training: boolean;
  units_total: number;
  units_available: number;
  has_required_regulations: boolean;
  is_featured: boolean;
  /** present only when a date range was supplied */
  available_quantity?: number;
  capacity?: number;
  bottleneck_date?: string | null;
}

export interface ProductSpec {
  label: string;
  value: string;
}

export interface ProductImage {
  id: number;
  url: string;
  alt: string | null;
  position: number;
}

export interface RegulationRef {
  id: number;
  slug: string;
  title: string;
  scope?: RegulationScope;
  version: number;
  requires_acceptance?: boolean;
}

export interface RecommendedProduct {
  relation: RecommendationRelation;
  position: number;
  product: ProductSummary;
}

/** Directional substitute entry on the product detail (ordered by priority). */
export interface SubstituteProduct {
  priority: number;
  product: ProductSummary;
}

/** Substitute suggestion attached to an UNAVAILABLE availability entry. */
export interface SuggestedSubstitute {
  product_id: number;
  name: string;
  slug: string;
  image_url: string | null;
  available_quantity: number;
  priority: number;
}

export interface Product extends ProductSummary {
  description: string | null;
  specs: ProductSpec[];
  images: ProductImage[];
  min_loan_days: number | null;
  max_loan_days: number | null;
  replacement_value_note: string | null;
  source_notes: string | null;
  position: number;
  recommended_products: RecommendedProduct[];
  substitutes: SubstituteProduct[];
  regulations: RegulationRef[];
  /** omitted for students unless ui.show_unit_codes_to_students */
  units?: ProductUnit[] | ReducedProductUnit[];
  recent_logs: ProductLog[];
  created_at: string;
  updated_at: string;
}

export interface ReducedProductUnit {
  id: number;
  label: string;
  status: UnitStatus;
}

export interface ProductUnit {
  id: number;
  product_id: number;
  label: string;
  serial_number: string | null;
  asset_code: string | null;
  purchase_date: string | null;
  inspection_date: string | null;
  next_inspection_date: string | null;
  status: UnitStatus;
  status_label: string;
  condition_note: string | null;
  location: string | null;
  current_order: { id: number; code: string; return_date: string } | null;
  created_at: string;
}

export interface ProductLog {
  id: number;
  product_id: number;
  product_unit_id: number | null;
  unit_label: string | null;
  order_id: number | null;
  order_code: string | null;
  type: LogType;
  type_label: string;
  severity: LogSeverity;
  title: string;
  body: string | null;
  occurred_at: string;
  resolved_at: string | null;
  is_public: boolean;
  user: UserRef | null;
  created_at: string;
}

export interface AssignedUnit {
  id: number;
  product_unit_id: number;
  unit_label: string;
  assigned_at: string;
  returned_at: string | null;
  condition_out: ConditionValue | null;
  condition_in: ConditionValue | null;
  note: string | null;
}

export interface OrderItem {
  id: number;
  product_id: number;
  quantity: number;
  notes: string | null;
  returned_quantity: number;
  product: ProductSummary;
  product_name_snapshot: string;
  product_brand_snapshot: string | null;
  /** staff-only */
  assigned_units?: AssignedUnit[];
}

export interface OrderSummary {
  id: number;
  code: string | null;
  status: OrderStatus;
  status_label: string;
  user: UserRef;
  pickup_date: string | null;
  pickup_time: string | null;
  return_date: string | null;
  return_time: string | null;
  items_count: number;
  distinct_products: number;
  exceeds_limits: boolean;
  is_late: boolean;
  late_days: number | null;
  submitted_at: string | null;
  created_at: string;
}

export interface OrderRequiredRegulation {
  id: number;
  slug: string;
  title: string;
  version: number;
  accepted: boolean;
  accepted_at?: string | null;
  scope?: RegulationScope;
}

export interface Order extends OrderSummary {
  subject: string | null;
  motivation: string | null;
  professor: string | null;
  notes: string | null;
  /** omitted for students */
  staff_notes?: string | null;
  rejection_reason: string | null;
  limit_violations: Violation[];
  picked_up_at: string | null;
  returned_at: string | null;
  decided_by: UserRef | null;
  decided_at: string | null;
  handed_over_by: UserRef | null;
  received_by: UserRef | null;
  cancelled_at: string | null;
  items: OrderItem[];
  events: OrderEvent[];
  required_regulations: OrderRequiredRegulation[];
  allowed_actions: OrderAction[];
  updated_at: string;
}

export interface OrderEvent {
  id: number;
  from_status: OrderStatus | null;
  to_status: OrderStatus | null;
  action: OrderAction;
  action_label: string;
  actor: UserRef | null;
  actor_type: ActorType;
  comment: string | null;
  meta: Record<string, unknown> | null;
  created_at: string;
  order?: { id: number; code: string };
}

export interface Setting {
  key: string;
  value: unknown;
  type: SettingType;
  group: SettingGroupKey;
  label_it: string;
  description_it: string | null;
  is_public: boolean;
  is_secret: boolean;
  nullable: boolean;
  options: string[] | null;
  position: number;
  updated_at: string | null;
}

export interface SettingGroupInfo {
  key: SettingGroupKey;
  label_it: string;
  position: number;
}

export interface SettingsResponse {
  data: Setting[];
  meta: null;
  groups: SettingGroupInfo[];
}

export interface Closure {
  id: number;
  title: string;
  description: string | null;
  start_date: string;
  end_date: string;
  blocks_pickup: boolean;
  blocks_return: boolean;
  is_recurring_yearly: boolean;
  created_at: string;
  affected_orders?: { id: number; code: string; pickup_date: string }[];
}

export interface RegulationTarget {
  target_type: 'category' | 'product';
  target_id: number;
  target_name?: string;
}

export interface Regulation {
  id: number;
  slug: string;
  title: string;
  summary: string | null;
  scope: RegulationScope;
  content_type: RegulationContentType;
  /** omitted from list responses */
  body?: string | null;
  file_url: string | null;
  file_name: string | null;
  file_size: number | null;
  version: number;
  requires_acceptance: boolean;
  is_active: boolean;
  published_at: string | null;
  position: number;
  targets: RegulationTarget[];
  acceptance: { accepted: boolean; version: number; accepted_at: string } | null;
  acceptances_count?: number;
  created_at: string;
  updated_at: string;
}

export interface PendingRegulation {
  id: number;
  slug: string;
  title: string;
  summary?: string | null;
  scope: RegulationScope;
  version: number;
  content_type: RegulationContentType;
  file_url?: string | null;
  blocking?: boolean;
}

/* ------------------------------------------------------------------ auth */

export interface Permissions {
  'products.manage': boolean;
  'orders.manage': boolean;
  'orders.create': boolean;
  'logs.create': boolean;
  'settings.manage': boolean;
  'settings.view': boolean;
  'stats.view_full': boolean;
  'stats.view_limited': boolean;
  'users.manage': boolean;
  'users.view': boolean;
  'regulations.manage': boolean;
  'regulations.delete': boolean;
  'closures.manage': boolean;
  'orders.reopen': boolean;
  'audit.view': boolean;
}

export type PermissionKey = keyof Permissions;

export interface LoginResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  expires_at: string;
  refresh_token: string;
  refresh_expires_at: string;
  user: User;
  pending_regulations: PendingRegulation[];
}

export interface MeResponse {
  user: User;
  permissions: Permissions;
  pending_regulations: PendingRegulation[];
  cart_items_count: number;
  active_orders_count: number;
}

/* ------------------------------------------------------------------ availability */

export interface Violation {
  code: string;
  severity: ViolationSeverity;
  message: string;
  limit: number | null;
  actual: number | null;
  product_ids: number[];
}

export interface TimeSlot {
  start: string;
  end: string;
}

export interface AvailabilityEntry {
  product_id: number;
  requested: number;
  available: number;
  capacity?: number;
  sufficient: boolean;
  /** present only when sufficient === false; max 3, ordered by priority */
  suggested_substitutes?: SuggestedSubstitute[];
}

export interface AvailabilityCheckResponse {
  ok: boolean;
  can_submit: boolean;
  exceeds_limits: boolean;
  violations: Violation[];
  duration_days: number | null;
  availability: AvailabilityEntry[];
  required_regulations: OrderRequiredRegulation[];
  pickup_slots: TimeSlot[];
  return_slots: TimeSlot[];
  quota: {
    orders_this_month: number;
    max_orders_per_month: number | null;
    orders_this_year: number;
    max_orders_per_year: number | null;
    active_orders: number;
    max_active_orders: number | null;
  };
}

export interface AvailabilityDay {
  date: string;
  all_available: boolean;
  is_open: boolean;
  can_pickup: boolean;
  can_return: boolean;
  closure_id: number | null;
  per_product: { product_id: number; requested: number; available: number; sufficient: boolean }[];
}

export interface AvailabilityWindow {
  pickup_date: string;
  return_date: string;
  days: number;
  all_available: boolean;
  blocking_product_ids: number[];
}

export interface AvailabilityDatesResponse {
  range: { from: string; to: string };
  duration_days: number;
  days: AvailabilityDay[];
  windows: AvailabilityWindow[];
  first_available_window: { pickup_date: string; return_date: string; days: number } | null;
  unavailable_products: { product_id: number; reason: string }[];
}

export interface ProductAvailabilityResponse {
  product_id: number;
  capacity: number;
  range: { from: string; to: string };
  days: {
    date: string;
    available: number;
    reserved: number;
    is_open: boolean;
    can_pickup: boolean;
    can_return: boolean;
    closure_id: number | null;
  }[];
}

export interface FacetFilters {
  categories: { id: number; name: string; slug: string; count: number }[];
  brands: { name: string; count: number }[];
}

export interface ProductListResponse extends Paginated<ProductSummary> {
  filters?: FacetFilters;
  range?: { start_date: string; end_date: string; days: number };
  range_validity?: {
    pickup_date_valid: boolean;
    return_date_valid: boolean;
    violations: Violation[];
  };
}

export interface OrderListResponse extends Paginated<OrderSummary> {
  summary?: Partial<Record<OrderStatus, number>>;
}

export interface OpeningDay {
  date: string;
  weekday: number;
  is_open: boolean;
  can_pickup: boolean;
  can_return: boolean;
  closure_id: number | null;
  pickup_slots: TimeSlot[];
  return_slots: TimeSlot[];
}

export interface OpeningCalendarResponse {
  timezone: string;
  weekly: { weekday: number; label: string; closed: boolean; open: string | null; close: string | null }[];
  closures: Closure[];
  days: OpeningDay[];
  booking_window: { min_date: string; max_date: string };
}

/* ------------------------------------------------------------------ cart */

export interface CartItem {
  id: number;
  product_id: number;
  quantity: number;
  notes: string | null;
  product: ProductSummary;
  available_quantity: number | null;
  sufficient: boolean | null;
}

export interface Cart {
  id: number;
  status: 'draft';
  pickup_date: string | null;
  pickup_time: string | null;
  return_date: string | null;
  return_time: string | null;
  items: CartItem[];
  items_count: number;
  distinct_products: number;
  check: AvailabilityCheckResponse | null;
  updated_at: string;
}

/* ------------------------------------------------------------------ stats */

export interface StatsOverview {
  scope: 'full' | 'limited';
  range: { from: string; to: string };
  operational: {
    orders_pending: number;
    orders_approved: number;
    orders_picked_up: number;
    orders_overdue: number;
    pickups_today: number;
    returns_today: number;
    returns_next_7_days: number;
  };
  totals?: {
    orders_total: number;
    orders_approved: number;
    orders_rejected: number;
    orders_cancelled: number;
    orders_no_show: number;
    orders_returned_late: number;
    approval_rate: number;
    late_rate: number;
    items_loaned: number;
    unique_students: number;
    avg_loan_days: number;
    avg_approval_hours: number;
  };
  inventory?: {
    products_total: number;
    units_total: number;
    units_available: number;
    units_maintenance: number;
    units_missing: number;
    units_retired: number;
    units_on_loan_now: number;
    utilization_now: number;
  };
}

export interface LoansOverTimeResponse {
  granularity: StatsGranularity;
  metric: 'orders' | 'items';
  series: {
    bucket: string;
    bucket_start: string;
    bucket_end?: string;
    submitted: number;
    approved: number;
    rejected: number;
    cancelled: number;
    returned: number;
    returned_late: number;
  }[];
  totals: Record<string, number>;
}

export interface TopProductsResponse {
  metric: string;
  data: {
    product_id: number;
    name: string;
    slug: string;
    brand: string | null;
    category: { id: number; name: string };
    image_url: string | null;
    orders_count: number;
    quantity_total: number;
    loan_days_total: number;
    units_total: number;
    utilization: number;
  }[];
}

export interface ByCategoryResponse {
  data: {
    category_id: number;
    name: string;
    slug: string;
    orders_count: number;
    quantity_total: number;
    loan_days_total: number;
    products_count: number;
    units_total: number;
    share: number;
    utilization: number;
  }[];
  totals: { orders_count: number; quantity_total: number; loan_days_total: number };
}

export interface LateReturnsResponse extends Paginated<{
  order_id: number;
  code: string;
  status: OrderStatus;
  user: UserRef;
  return_date: string;
  returned_at: string | null;
  late_days: number;
  items_count: number;
}> {
  summary: {
    late_orders: number;
    late_days_total: number;
    avg_late_days: number;
    students_involved: number;
    currently_overdue: number;
  };
}

export interface MyActivityResponse {
  user_id: number;
  range: { from: string; to: string };
  counts: Record<string, number>;
  series: { bucket: string; bucket_start: string; actions: number }[];
  recent_events: OrderEvent[];
}

/* ------------------------------------------------------------------ misc */

export interface EnumEntry {
  value: string;
  label: string;
  is_terminal?: boolean;
  locks_stock?: boolean;
}

export type MetaEnums = Record<string, EnumEntry[]>;

export interface HealthResponse {
  status: string;
  app: string;
  version: string;
  environment: string;
  database: { driver: string; connected: boolean; migrations_applied: number };
  ldap_mode: 'fake' | 'real';
  server_time: string;
  timezone: string;
}

export type PublicSettings = Record<string, unknown>;

export interface StaffCalendarResponse {
  range: { from: string; to: string };
  days: {
    date: string;
    is_open: boolean;
    closure_id: number | null;
    pickups: CalendarOrderRef[];
    returns: CalendarOrderRef[];
    overdue: CalendarOrderRef[];
  }[];
  totals: { pickups: number; returns: number; overdue: number };
}

export interface CalendarOrderRef {
  order_id: number;
  code: string;
  time: string | null;
  user_display_name: string;
  items_count: number;
  status: OrderStatus;
}

export interface AuditLogEntry {
  id: number;
  action: string;
  entity_type: string;
  entity_id: string;
  user: UserRef | null;
  changes: { before: Record<string, unknown> | null; after: Record<string, unknown> | null } | null;
  ip: string | null;
  created_at: string;
}

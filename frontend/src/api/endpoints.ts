import { API_PREFIX, apiFetch, buildQuery, type QueryValue } from './client';
import type {
  AuditLogEntry,
  AvailabilityCheckResponse,
  AvailabilityDatesResponse,
  ByCategoryResponse,
  Cart,
  Category,
  Closure,
  Collection,
  HealthResponse,
  IcalFeed,
  LateReturnsResponse,
  LoansOverTimeResponse,
  LoginResponse,
  MeResponse,
  MetaEnums,
  MyActivityResponse,
  OpeningCalendarResponse,
  Order,
  OrderEvent,
  OrderListResponse,
  Paginated,
  PendingRegulation,
  Product,
  ProductAvailabilityResponse,
  ProductListResponse,
  ProductLog,
  ProductUnit,
  PublicSettings,
  RecommendedProduct,
  Regulation,
  Setting,
  SettingsResponse,
  StaffCalendarResponse,
  StatsOverview,
  SubstituteProduct,
  TopProductsResponse,
  User,
} from '@/types/api';

type Q = Record<string, QueryValue>;
const p = (path: string) => `${API_PREFIX}${path}`;

/* ------------------------------------------------------------------- system */

export const getHealth = () => apiFetch<HealthResponse>(p('/health'));
export const getEnums = () => apiFetch<MetaEnums>(p('/meta/enums'));
export const getPublicSettings = () => apiFetch<PublicSettings>(p('/settings/public'));

/* --------------------------------------------------------------------- auth */

export const login = (username: string, password: string) =>
  apiFetch<LoginResponse>(p('/auth/login'), { method: 'POST', body: { username, password } });

export const logout = (refresh_token: string | null) =>
  apiFetch<void>(p('/auth/logout'), { method: 'POST', body: { refresh_token } });

export const getMe = () => apiFetch<MeResponse>(p('/auth/me'));

export const updateMe = (body: { phone?: string | null; course?: string | null }) =>
  apiFetch<{ user: User }>(p('/auth/me'), { method: 'PATCH', body });

/* ------------------------------------------------------------------ catalog */

export const getCategories = (query: Q = {}) =>
  apiFetch<Collection<Category>>(p('/categories') + buildQuery(query));

export const getCategory = (idOrSlug: string | number) =>
  apiFetch<Category>(p(`/categories/${idOrSlug}`));

export const createCategory = (body: Partial<Category>) =>
  apiFetch<Category>(p('/categories'), { method: 'POST', body });

export const updateCategory = (id: number, body: Partial<Category>) =>
  apiFetch<Category>(p(`/categories/${id}`), { method: 'PUT', body });

export const deleteCategory = (id: number) =>
  apiFetch<void>(p(`/categories/${id}`), { method: 'DELETE' });

export const getProducts = (query: Q = {}) =>
  apiFetch<ProductListResponse>(p('/products') + buildQuery(query));

export const getProduct = (idOrSlug: string | number, query: Q = {}) =>
  apiFetch<Product>(p(`/products/${idOrSlug}`) + buildQuery(query));

export const createProduct = (body: Record<string, unknown>) =>
  apiFetch<Product>(p('/products'), { method: 'POST', body });

export const updateProduct = (id: number, body: Record<string, unknown>) =>
  apiFetch<Product>(p(`/products/${id}`), { method: 'PUT', body });

export const deleteProduct = (id: number) =>
  apiFetch<void>(p(`/products/${id}`), { method: 'DELETE' });

export const getProductUnits = (id: number) =>
  apiFetch<Collection<ProductUnit>>(p(`/products/${id}/units`));

export const createProductUnits = (id: number, body: Record<string, unknown>) =>
  apiFetch<Collection<ProductUnit>>(p(`/products/${id}/units`), { method: 'POST', body });

export const updateUnit = (unitId: number, body: Record<string, unknown>) =>
  apiFetch<ProductUnit>(p(`/units/${unitId}`), { method: 'PUT', body });

export const deleteUnit = (unitId: number) =>
  apiFetch<void>(p(`/units/${unitId}`), { method: 'DELETE' });

export const getProductLogs = (id: number, query: Q = {}) =>
  apiFetch<Paginated<ProductLog>>(p(`/products/${id}/logs`) + buildQuery(query));

export const createProductLog = (id: number, body: Record<string, unknown>) =>
  apiFetch<ProductLog>(p(`/products/${id}/logs`), { method: 'POST', body });

export const setRecommended = (id: number, items: Record<string, unknown>[]) =>
  apiFetch<Collection<RecommendedProduct>>(p(`/products/${id}/recommended`), {
    method: 'PUT',
    body: { items },
  });

export const setSubstitutes = (id: number, items: { product_id: number; priority: number }[]) =>
  apiFetch<Collection<SubstituteProduct>>(p(`/products/${id}/substitutes`), {
    method: 'PUT',
    body: { items },
  });

export const getProductAvailability = (id: number, from: string, to: string) =>
  apiFetch<ProductAvailabilityResponse>(
    p(`/products/${id}/availability`) + buildQuery({ from, to }),
  );

export const getBrands = () =>
  apiFetch<Collection<{ name: string; products_count: number }>>(p('/brands'));

/* ------------------------------------------------------------- availability */

export const getAvailableProducts = (query: Q) =>
  apiFetch<ProductListResponse>(p('/availability/products') + buildQuery(query));

export const postAvailabilityDates = (body: {
  items: { product_id: number; quantity: number }[];
  from?: string | null;
  to?: string | null;
  duration_days?: number | null;
  exclude_order_id?: number | null;
}) => apiFetch<AvailabilityDatesResponse>(p('/availability/dates'), { method: 'POST', body });

export const postAvailabilityCheck = (body: Record<string, unknown>) =>
  apiFetch<AvailabilityCheckResponse>(p('/availability/check'), { method: 'POST', body });

export const getOpeningCalendar = (query: Q = {}) =>
  apiFetch<OpeningCalendarResponse>(p('/calendar/opening') + buildQuery(query));

/* --------------------------------------------------------------------- cart */

export const getCart = () => apiFetch<Cart>(p('/cart'));

export const addCartItem = (body: { product_id: number; quantity: number; notes?: string | null }) =>
  apiFetch<Cart>(p('/cart/items'), { method: 'POST', body });

export const patchCartItem = (
  itemId: number,
  body: { quantity?: number; notes?: string | null },
) => apiFetch<Cart>(p(`/cart/items/${itemId}`), { method: 'PATCH', body });

export const deleteCartItem = (itemId: number) =>
  apiFetch<Cart>(p(`/cart/items/${itemId}`), { method: 'DELETE' });

export const swapCartItem = (itemId: number, product_id: number) =>
  apiFetch<Cart>(p(`/cart/items/${itemId}/swap`), { method: 'POST', body: { product_id } });

export const putCartDates = (body: {
  pickup_date?: string | null;
  pickup_time?: string | null;
  return_date?: string | null;
  return_time?: string | null;
}) => apiFetch<Cart>(p('/cart/dates'), { method: 'PUT', body });

export const clearCart = () => apiFetch<Cart>(p('/cart'), { method: 'DELETE' });

/* ------------------------------------------------------------------- orders */

export const createOrder = (body: Record<string, unknown>) =>
  apiFetch<Order>(p('/orders'), { method: 'POST', body });

export const getOrders = (query: Q = {}) =>
  apiFetch<OrderListResponse>(p('/orders') + buildQuery(query));

export const getOrder = (id: number) => apiFetch<Order>(p(`/orders/${id}`));

export const getOrderEvents = (id: number) =>
  apiFetch<Collection<OrderEvent>>(p(`/orders/${id}/events`));

export const updateOrder = (id: number, body: Record<string, unknown>) =>
  apiFetch<Order>(p(`/orders/${id}`), { method: 'PUT', body });

export const orderAction = (id: number, action: string, body: Record<string, unknown> = {}) =>
  apiFetch<Order>(p(`/orders/${id}/${action}`), { method: 'POST', body });

export const getStaffCalendar = (query: Q) =>
  apiFetch<StaffCalendarResponse>(p('/orders/calendar') + buildQuery(query));

/* --------------------------------------------------------------- iCal feed */

/** Current subscription URL; the backend mints the token on first read. */
export const getIcalFeed = () => apiFetch<IcalFeed>(p('/me/ical'));

/** New token — the previous URL stops resolving immediately. */
export const rotateIcalFeed = () => apiFetch<IcalFeed>(p('/me/ical/rotate'), { method: 'POST' });

/* -------------------------------------------------------------- regulations */

export const getRegulations = (query: Q = {}) =>
  apiFetch<Paginated<Regulation>>(p('/regulations') + buildQuery(query));

export const getRegulation = (idOrSlug: string | number) =>
  apiFetch<Regulation>(p(`/regulations/${idOrSlug}`));

export const createRegulation = (body: Record<string, unknown>) =>
  apiFetch<Regulation>(p('/regulations'), { method: 'POST', body });

export const updateRegulation = (id: number, body: Record<string, unknown>) =>
  apiFetch<Regulation>(p(`/regulations/${id}`), { method: 'PUT', body });

export const publishRegulation = (id: number, body: { bump_version?: boolean; note?: string }) =>
  apiFetch<Regulation>(p(`/regulations/${id}/publish`), { method: 'POST', body });

export const deleteRegulation = (id: number) =>
  apiFetch<void>(p(`/regulations/${id}`), { method: 'DELETE' });

export const getPendingRegulations = () =>
  apiFetch<Collection<PendingRegulation>>(p('/me/regulations/pending'));

export const acceptRegulation = (id: number, version: number, order_id: number | null = null) =>
  apiFetch<{
    accepted: boolean;
    regulation_id: number;
    version: number;
    accepted_at: string;
    pending_regulations: PendingRegulation[];
  }>(p(`/me/regulations/${id}/accept`), { method: 'POST', body: { version, order_id } });

/* ----------------------------------------------------- settings & closures */

export const getSettings = (query: Q = {}) =>
  apiFetch<SettingsResponse>(p('/settings') + buildQuery(query));

export const putSettings = (settings: Record<string, unknown>) =>
  apiFetch<SettingsResponse>(p('/settings'), { method: 'PUT', body: { settings } });

export const putSetting = (key: string, value: unknown) =>
  apiFetch<Setting>(p(`/settings/${key}`), { method: 'PUT', body: { value } });

export const testLdap = (body: Record<string, unknown>) =>
  apiFetch<{ ok: boolean; message: string; latency_ms: number; entries_found: number; mode: string }>(
    p('/settings/ldap/test'),
    { method: 'POST', body },
  );

export const getClosures = (query: Q = {}) =>
  apiFetch<Paginated<Closure>>(p('/closures') + buildQuery(query));

export const createClosure = (body: Record<string, unknown>) =>
  apiFetch<Closure>(p('/closures'), { method: 'POST', body });

export const updateClosure = (id: number, body: Record<string, unknown>) =>
  apiFetch<Closure>(p(`/closures/${id}`), { method: 'PUT', body });

export const deleteClosure = (id: number) =>
  apiFetch<void>(p(`/closures/${id}`), { method: 'DELETE' });

/* -------------------------------------------------------------------- users */

export const getUsers = (query: Q = {}) => apiFetch<Paginated<User>>(p('/users') + buildQuery(query));
export const getUser = (id: number) => apiFetch<User>(p(`/users/${id}`));
export const updateUser = (id: number, body: Record<string, unknown>) =>
  apiFetch<User>(p(`/users/${id}`), { method: 'PUT', body });
export const getUserOrders = (id: number, query: Q = {}) =>
  apiFetch<OrderListResponse>(p(`/users/${id}/orders`) + buildQuery(query));

/* -------------------------------------------------------------------- stats */

export const getStatsOverview = (query: Q = {}) =>
  apiFetch<StatsOverview>(p('/stats/overview') + buildQuery(query));
export const getLoansOverTime = (query: Q = {}) =>
  apiFetch<LoansOverTimeResponse>(p('/stats/loans-over-time') + buildQuery(query));
export const getTopProducts = (query: Q = {}) =>
  apiFetch<TopProductsResponse>(p('/stats/top-products') + buildQuery(query));
export const getStatsByCategory = (query: Q = {}) =>
  apiFetch<ByCategoryResponse>(p('/stats/by-category') + buildQuery(query));
export const getLateReturns = (query: Q = {}) =>
  apiFetch<LateReturnsResponse>(p('/stats/late-returns') + buildQuery(query));
export const getMyActivity = (query: Q = {}) =>
  apiFetch<MyActivityResponse>(p('/stats/my-activity') + buildQuery(query));

/* ---------------------------------------------------------------- staff logs */

export const getAllLogs = (query: Q = {}) =>
  apiFetch<Paginated<ProductLog> & { summary?: Record<string, number> }>(
    p('/logs') + buildQuery(query),
  );

export const getAuditLogs = (query: Q = {}) =>
  apiFetch<Paginated<AuditLogEntry>>(p('/audit-logs') + buildQuery(query));

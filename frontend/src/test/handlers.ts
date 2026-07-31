import { http, HttpResponse, type HttpHandler } from 'msw';
import * as f from './fixtures';
import type { Cart, MeResponse, PendingRegulation, Role } from '@/types/api';

/**
 * Mutable msw state — tests tweak it through `mockState` before rendering.
 */
export const mockState = {
  role: 'student' as Role | null,
  me: null as MeResponse | null,
  cart: null as Cart | null,
  check: f.availabilityCheckOk,
  ldapMode: 'fake' as 'fake' | 'real',
  refreshCalls: 0,
  loginCalls: 0,
  pendingRegulations: [] as PendingRegulation[],
  acceptedRegulationIds: [] as number[],
  icalToken: 'a'.repeat(64),
  icalRotateCalls: 0,
};

const DEFAULT_ICAL_TOKEN = 'a'.repeat(64);

export function resetMockState(): void {
  mockState.role = 'student';
  mockState.me = null;
  mockState.cart = null;
  mockState.check = f.availabilityCheckOk;
  mockState.ldapMode = 'fake';
  mockState.refreshCalls = 0;
  mockState.loginCalls = 0;
  mockState.pendingRegulations = [];
  mockState.acceptedRegulationIds = [];
  mockState.icalToken = DEFAULT_ICAL_TOKEN;
  mockState.icalRotateCalls = 0;
}

function currentMe(): MeResponse {
  if (mockState.me) return { ...mockState.me, pending_regulations: mockState.pendingRegulations };
  return f.makeMe(mockState.role ?? 'student', {
    pending_regulations: mockState.pendingRegulations,
  });
}

function currentCart(): Cart {
  return mockState.cart ?? f.emptyCart;
}

const p = (path: string) => `/api/v1${path}`;

export const handlers: HttpHandler[] = [
  /* --------------------------------------------------------------- system */
  http.get(p('/health'), () =>
    HttpResponse.json({
      status: 'ok',
      app: 'vlab',
      version: '1.0.0',
      environment: 'local',
      database: { driver: 'sqlite', connected: true, migrations_applied: 20 },
      ldap_mode: mockState.ldapMode,
      server_time: '2026-07-31T09:00:00Z',
      timezone: 'Europe/Rome',
    }),
  ),
  http.get(p('/meta/enums'), () => HttpResponse.json(f.metaEnums)),
  http.get(p('/settings/public'), () => HttpResponse.json(f.publicSettings)),

  /* ----------------------------------------------------------------- auth */
  http.post(p('/auth/login'), async ({ request }) => {
    mockState.loginCalls += 1;
    const body = (await request.json()) as { username: string; password: string };
    if (body.password !== 'password') {
      return HttpResponse.json(
        {
          error: {
            code: 'invalid_credentials',
            message: 'Credenziali non valide.',
            details: null,
            trace_id: 'aabbccdd',
          },
        },
        { status: 401 },
      );
    }
    const role: Role =
      body.username === 'tecnico1'
        ? 'technician'
        : body.username === 'borsista1'
          ? 'assistant'
          : body.username === 'admin1'
            ? 'admin'
            : 'student';
    mockState.role = role;
    return HttpResponse.json(f.makeLogin(role));
  }),

  http.post(p('/auth/refresh'), async () => {
    mockState.refreshCalls += 1;
    if (mockState.role === null) {
      return HttpResponse.json(
        {
          error: {
            code: 'refresh_invalid',
            message: 'Sessione scaduta.',
            details: null,
            trace_id: 'deadbeef',
          },
        },
        { status: 401 },
      );
    }
    return HttpResponse.json(f.makeLogin(mockState.role));
  }),

  http.post(p('/auth/logout'), () => new HttpResponse(null, { status: 204 })),

  http.get(p('/auth/me'), () => {
    if (mockState.role === null) {
      return HttpResponse.json(
        {
          error: {
            code: 'unauthenticated',
            message: 'Autenticazione richiesta.',
            details: null,
            trace_id: '11223344',
          },
        },
        { status: 401 },
      );
    }
    return HttpResponse.json(currentMe());
  }),

  http.patch(p('/auth/me'), () => HttpResponse.json({ user: f.studentUser })),

  /* -------------------------------------------------------------- catalog */
  http.get(p('/categories'), () => HttpResponse.json({ data: f.categories, meta: null })),

  http.get(p('/products'), ({ request }) => {
    const url = new URL(request.url);
    const q = url.searchParams.get('q');
    const categorySlug = url.searchParams.get('category_slug');
    let data = f.productSummaries;
    if (q) data = data.filter((item) => item.name.toLowerCase().includes(q.toLowerCase()));
    if (categorySlug) data = data.filter((item) => item.category.slug === categorySlug);
    return HttpResponse.json({
      data,
      meta: { page: 1, per_page: 24, total: data.length, total_pages: 1 },
      filters: {
        categories: f.categories.map((c) => ({
          id: c.id,
          name: c.name,
          slug: c.slug,
          count: c.products_count,
        })),
        brands: [
          { name: 'Meta', count: 1 },
          { name: 'Rode', count: 1 },
          { name: 'Sony', count: 1 },
        ],
      },
    });
  }),

  http.get(p('/availability/products'), ({ request }) => {
    const url = new URL(request.url);
    const includeUnavailable = url.searchParams.get('include_unavailable') === 'true';
    const data = f.productSummaries
      .map((item) => ({
        ...item,
        available_quantity: item.status === 'available' ? 3 : 0,
        capacity: item.units_total,
        bottleneck_date: null,
      }))
      .filter((item) => includeUnavailable || item.available_quantity > 0);
    return HttpResponse.json({
      data,
      meta: { page: 1, per_page: 24, total: data.length, total_pages: 1 },
      range: {
        start_date: url.searchParams.get('start_date') ?? '',
        end_date: url.searchParams.get('end_date') ?? '',
        days: 3,
      },
      range_validity: { pickup_date_valid: true, return_date_valid: true, violations: [] },
      filters: { categories: [], brands: [] },
    });
  }),

  http.get(p('/products/:idOrSlug/availability'), () =>
    HttpResponse.json({
      product_id: 128,
      capacity: 6,
      range: { from: '2026-08-01', to: '2026-08-14' },
      days: f.availabilityDates.days.map((day) => ({
        date: day.date,
        available: day.per_product[0]?.available ?? 0,
        reserved: 2,
        is_open: day.is_open,
        can_pickup: day.can_pickup,
        can_return: day.can_return,
        closure_id: null,
      })),
    }),
  ),

  http.get(p('/products/:id/units'), () =>
    HttpResponse.json({ data: f.productUnits, meta: null }),
  ),

  http.get(p('/products/:id/logs'), () =>
    HttpResponse.json({
      data: f.productLogs,
      meta: { page: 1, per_page: 8, total: f.productLogs.length, total_pages: 1 },
    }),
  ),

  http.post(p('/products/:id/logs'), () => HttpResponse.json(f.productLogs[0], { status: 201 })),

  http.get(p('/products/:idOrSlug'), ({ params }) => {
    const key = String(params['idOrSlug']);
    if (key === f.maintenanceProduct.slug || key === String(f.maintenanceProduct.id)) {
      return HttpResponse.json(f.maintenanceProduct);
    }
    return HttpResponse.json(f.vrHeadsetProduct);
  }),

  http.post(p('/products'), () => HttpResponse.json(f.vrHeadsetProduct, { status: 201 })),
  http.put(p('/products/:id'), () => HttpResponse.json(f.vrHeadsetProduct)),
  http.delete(p('/products/:id'), () => new HttpResponse(null, { status: 204 })),

  http.put(p('/products/:id/substitutes'), async ({ request }) => {
    const body = (await request.json()) as { items: { product_id: number; priority: number }[] };
    const data = (body.items ?? [])
      .map((item) => ({
        priority: item.priority,
        product: f.productSummaryPool.find((summary) => summary.id === item.product_id),
      }))
      .filter((entry) => entry.product !== undefined);
    return HttpResponse.json({ data, meta: null });
  }),

  http.get(p('/brands'), () =>
    HttpResponse.json({
      data: [
        { name: 'Meta', products_count: 1 },
        { name: 'Rode', products_count: 1 },
      ],
      meta: null,
    }),
  ),

  /* --------------------------------------------------------- availability */
  http.post(p('/availability/dates'), () => HttpResponse.json(f.availabilityDates)),
  http.post(p('/availability/check'), () => HttpResponse.json(mockState.check)),
  http.get(p('/calendar/opening'), () =>
    HttpResponse.json({
      timezone: 'Europe/Rome',
      weekly: [
        { weekday: 0, label: 'Domenica', closed: true, open: null, close: null },
        { weekday: 1, label: 'Lunedì', closed: false, open: '09:00', close: '17:00' },
      ],
      closures: f.closures,
      days: [],
      booking_window: { min_date: '2026-08-01', max_date: '2026-10-30' },
    }),
  ),

  /* ----------------------------------------------------------------- cart */
  http.get(p('/cart'), () => HttpResponse.json(currentCart())),
  http.post(p('/cart/items'), async ({ request }) => {
    const body = (await request.json()) as { product_id: number; quantity: number };
    const base = currentCart();
    const next: Cart = {
      ...base,
      items: [
        ...base.items,
        {
          id: 900 + body.product_id,
          product_id: body.product_id,
          quantity: body.quantity,
          notes: null,
          product: f.productSummaries.find((item) => item.id === body.product_id) ?? f.vrHeadsetSummary,
          available_quantity: null,
          sufficient: null,
        },
      ],
      items_count: base.items_count + body.quantity,
      distinct_products: base.distinct_products + 1,
    };
    mockState.cart = next;
    return HttpResponse.json(next);
  }),
  http.patch(p('/cart/items/:itemId'), async ({ params, request }) => {
    const body = (await request.json()) as { quantity?: number };
    const base = currentCart();
    const itemId = Number(params['itemId']);
    const next: Cart = {
      ...base,
      items: base.items.map((item) =>
        item.id === itemId ? { ...item, quantity: body.quantity ?? item.quantity } : item,
      ),
    };
    next.items_count = next.items.reduce((sum, item) => sum + item.quantity, 0);
    mockState.cart = next;
    return HttpResponse.json(next);
  }),
  http.post(p('/cart/items/:itemId/swap'), async ({ params, request }) => {
    const body = (await request.json()) as { product_id: number };
    const base = currentCart();
    const itemId = Number(params['itemId']);
    const substitute =
      f.productSummaryPool.find((summary) => summary.id === body.product_id) ?? f.vrHeadsetSummary;
    const next: Cart = {
      ...base,
      items: base.items.map((item) =>
        item.id === itemId
          ? {
              ...item,
              product_id: substitute.id,
              product: substitute,
              available_quantity: 3,
              sufficient: true,
            }
          : item,
      ),
      check: base.check ? { ...base.check, ...f.availabilityCheckOk } : base.check,
    };
    mockState.cart = next;
    return HttpResponse.json(next);
  }),
  http.delete(p('/cart/items/:itemId'), ({ params }) => {
    const base = currentCart();
    const itemId = Number(params['itemId']);
    const items = base.items.filter((item) => item.id !== itemId);
    const next: Cart = {
      ...base,
      items,
      items_count: items.reduce((sum, item) => sum + item.quantity, 0),
      distinct_products: items.length,
    };
    mockState.cart = next;
    return HttpResponse.json(next);
  }),
  http.put(p('/cart/dates'), async ({ request }) => {
    const body = (await request.json()) as Record<string, string | null>;
    const next: Cart = { ...currentCart(), ...body } as Cart;
    mockState.cart = next;
    return HttpResponse.json(next);
  }),
  http.delete(p('/cart'), () => {
    mockState.cart = f.emptyCart;
    return HttpResponse.json(f.emptyCart);
  }),

  /* --------------------------------------------------------------- orders */
  http.post(p('/orders'), () => HttpResponse.json(f.makeOrder(), { status: 201 })),
  http.post(p('/orders/manual'), () =>
    HttpResponse.json(
      f.makeStaffOrder({
        id: 120,
        code: 'VL-2026-0120',
        status: 'approved',
        status_label: 'Approvato',
        allowed_actions: ['pickup', 'cancel', 'mark_no_show'],
        pending_regulations: [],
      }),
      { status: 201 },
    ),
  ),
  http.get(p('/orders/calendar'), () =>
    HttpResponse.json({
      range: { from: '2026-08-01', to: '2026-08-31' },
      days: [],
      totals: { pickups: 12, returns: 11, overdue: 1 },
    }),
  ),
  http.get(p('/orders'), ({ request }) => {
    const url = new URL(request.url);
    const status = url.searchParams.get('status');
    const data = status
      ? f.orderSummaries.filter((order) => status.split(',').includes(order.status))
      : f.orderSummaries;
    return HttpResponse.json({
      data,
      meta: { page: 1, per_page: 10, total: data.length, total_pages: 1 },
      summary: { pending: 1, approved: 1, picked_up: 0, overdue: 0 },
    });
  }),
  http.get(p('/orders/:id/events'), () => HttpResponse.json({ data: f.orderEvents, meta: null })),
  http.get(p('/orders/:id'), ({ params }) => {
    const id = Number(params['id']);
    if (mockState.role !== null && mockState.role !== 'student') {
      return HttpResponse.json(f.makeStaffOrder({ id }));
    }
    return HttpResponse.json(f.makeOrder({ id }));
  }),
  http.post(p('/orders/:id/approve'), ({ params }) =>
    HttpResponse.json(
      f.makeStaffOrder({
        id: Number(params['id']),
        status: 'approved',
        status_label: 'Approvato',
        allowed_actions: ['pickup', 'cancel', 'mark_no_show'],
      }),
    ),
  ),
  http.post(p('/orders/:id/reject'), ({ params }) =>
    HttpResponse.json(
      f.makeStaffOrder({
        id: Number(params['id']),
        status: 'rejected',
        status_label: 'Respinto',
        allowed_actions: [],
      }),
    ),
  ),
  http.post(p('/orders/:id/cancel'), ({ params }) =>
    HttpResponse.json(
      f.makeOrder({
        id: Number(params['id']),
        status: 'cancelled',
        status_label: 'Annullato',
        allowed_actions: [],
      }),
    ),
  ),
  http.post(p('/orders/:id/pickup'), ({ params }) =>
    HttpResponse.json(
      f.makeStaffOrder({
        id: Number(params['id']),
        status: 'picked_up',
        status_label: 'Ritirato',
        allowed_actions: ['return'],
      }),
    ),
  ),
  http.post(p('/orders/:id/return'), ({ params }) =>
    HttpResponse.json(
      f.makeStaffOrder({
        id: Number(params['id']),
        status: 'returned',
        status_label: 'Restituito',
        allowed_actions: [],
      }),
    ),
  ),
  http.post(p('/orders/:id/no-show'), ({ params }) =>
    HttpResponse.json(f.makeStaffOrder({ id: Number(params['id']), status: 'no_show' })),
  ),
  http.post(p('/orders/:id/notes'), ({ params }) =>
    HttpResponse.json(f.makeStaffOrder({ id: Number(params['id']) })),
  ),

  /* ---------------------------------------------------------- regulations */
  http.get(p('/me/regulations/pending'), () =>
    HttpResponse.json({ data: mockState.pendingRegulations, meta: null }),
  ),
  http.post(p('/me/regulations/:id/accept'), ({ params }) => {
    const id = Number(params['id']);
    mockState.acceptedRegulationIds.push(id);
    mockState.pendingRegulations = mockState.pendingRegulations.filter((reg) => reg.id !== id);
    return HttpResponse.json({
      accepted: true,
      regulation_id: id,
      version: 3,
      accepted_at: '2026-07-31T09:10:00Z',
      pending_regulations: mockState.pendingRegulations,
    });
  }),

  /* ------------------------------------------------------------ iCal feed */
  http.get(p('/me/ical'), () =>
    HttpResponse.json({
      token: mockState.icalToken,
      feed_url: `http://localhost:8081/api/v1/ical/${mockState.icalToken}.ics`,
      generated_at: '2026-07-30T09:00:00Z',
    }),
  ),
  http.post(p('/me/ical/rotate'), () => {
    mockState.icalRotateCalls += 1;
    mockState.icalToken = `rotated${'b'.repeat(58)}`;
    return HttpResponse.json({
      token: mockState.icalToken,
      feed_url: `http://localhost:8081/api/v1/ical/${mockState.icalToken}.ics`,
      generated_at: '2026-07-31T09:00:00Z',
    });
  }),
  http.get(p('/regulations'), () =>
    HttpResponse.json({
      data: [f.globalRegulation, f.vrRegulation],
      meta: { page: 1, per_page: 100, total: 2, total_pages: 1 },
    }),
  ),
  http.get(p('/regulations/:idOrSlug'), ({ params }) => {
    const key = String(params['idOrSlug']);
    if (key === '1' || key === 'regolamento-generale') return HttpResponse.json(f.globalRegulation);
    return HttpResponse.json(f.vrRegulation);
  }),
  http.post(p('/regulations'), () => HttpResponse.json(f.vrRegulation, { status: 201 })),
  http.put(p('/regulations/:id'), () => HttpResponse.json(f.vrRegulation)),
  http.post(p('/regulations/:id/publish'), () =>
    HttpResponse.json({ ...f.vrRegulation, version: 3 }),
  ),

  /* ----------------------------------------------- settings/closures/users */
  http.get(p('/settings'), () =>
    HttpResponse.json({ data: f.settings, meta: null, groups: f.settingGroups }),
  ),
  http.put(p('/settings'), () =>
    HttpResponse.json({ data: f.settings, meta: null, groups: f.settingGroups }),
  ),
  http.post(p('/settings/ldap/test'), () =>
    HttpResponse.json({
      ok: true,
      message: 'Connessione riuscita, 1 utente trovato.',
      latency_ms: 43,
      entries_found: 1,
      mode: 'real',
    }),
  ),
  http.get(p('/closures'), () =>
    HttpResponse.json({
      data: f.closures,
      meta: { page: 1, per_page: 50, total: 1, total_pages: 1 },
    }),
  ),
  http.post(p('/closures'), () => HttpResponse.json(f.closures[0], { status: 201 })),
  http.put(p('/closures/:id'), () => HttpResponse.json(f.closures[0])),
  http.delete(p('/closures/:id'), () => new HttpResponse(null, { status: 204 })),

  http.get(p('/users'), () =>
    HttpResponse.json({
      data: [
        { ...f.studentUser, orders_count: 14, active_orders_count: 1, late_returns_count: 2 },
        { ...f.assistantUser, orders_count: 0, active_orders_count: 0, late_returns_count: 0 },
      ],
      meta: { page: 1, per_page: 25, total: 2, total_pages: 1 },
    }),
  ),
  http.get(p('/users/:id/orders'), () =>
    HttpResponse.json({
      data: f.orderSummaries,
      meta: { page: 1, per_page: 20, total: 2, total_pages: 1 },
    }),
  ),
  http.get(p('/users/:id'), () => HttpResponse.json(f.studentUser)),
  http.put(p('/users/:id'), () => HttpResponse.json(f.studentUser)),

  /* ---------------------------------------------------------------- stats */
  http.get(p('/stats/overview'), () =>
    HttpResponse.json(
      mockState.role === 'assistant' ? f.statsOverviewLimited : f.statsOverviewFull,
    ),
  ),
  http.get(p('/stats/loans-over-time'), () =>
    HttpResponse.json({
      granularity: 'week',
      metric: 'orders',
      series: [
        {
          bucket: '2026-W20',
          bucket_start: '2026-05-11',
          bucket_end: '2026-05-17',
          submitted: 9,
          approved: 7,
          rejected: 1,
          cancelled: 1,
          returned: 6,
          returned_late: 1,
        },
      ],
      totals: { submitted: 128, approved: 96, rejected: 12, cancelled: 14, returned: 84, returned_late: 11 },
    }),
  ),
  http.get(p('/stats/top-products'), () =>
    HttpResponse.json({
      metric: 'orders',
      data: [
        {
          product_id: 128,
          name: 'Visore VR Meta Quest 3 128GB',
          slug: 'visore-vr-meta-quest-3',
          brand: 'Meta',
          category: { id: 7, name: 'Tecnologie Interattive' },
          image_url: null,
          orders_count: 41,
          quantity_total: 48,
          loan_days_total: 173,
          units_total: 6,
          utilization: 0.31,
        },
      ],
    }),
  ),
  http.get(p('/stats/by-category'), () =>
    HttpResponse.json({
      data: [
        {
          category_id: 7,
          name: 'Tecnologie Interattive',
          slug: 'tecnologie-interattive',
          orders_count: 63,
          quantity_total: 88,
          loan_days_total: 310,
          products_count: 34,
          units_total: 96,
          share: 0.24,
          utilization: 0.11,
        },
      ],
      totals: { orders_count: 262, quantity_total: 341, loan_days_total: 1290 },
    }),
  ),
  http.get(p('/stats/late-returns'), () =>
    HttpResponse.json({
      data: [
        {
          order_id: 77,
          code: 'VL-2026-0077',
          status: 'returned_late',
          user: { id: 3, display_name: 'Marco Rossi', ldap_uid: 'student1' },
          return_date: '2026-06-10',
          returned_at: '2026-06-14T10:12:00Z',
          late_days: 4,
          items_count: 2,
        },
      ],
      meta: { page: 1, per_page: 25, total: 1, total_pages: 1 },
      summary: {
        late_orders: 11,
        late_days_total: 29,
        avg_late_days: 2.6,
        students_involved: 8,
        currently_overdue: 1,
      },
    }),
  ),
  http.get(p('/stats/my-activity'), () =>
    HttpResponse.json({
      user_id: 5,
      range: { from: '2026-05-02', to: '2026-07-31' },
      counts: { approved: 41, rejected: 6, pickups: 38, returns: 35 },
      series: [{ bucket: '2026-W20', bucket_start: '2026-05-11', actions: 12 }],
      recent_events: [],
    }),
  ),

  /* ----------------------------------------------------------------- logs */
  http.get(p('/logs'), () =>
    HttpResponse.json({
      data: f.productLogs,
      meta: { page: 1, per_page: 25, total: 1, total_pages: 1 },
      summary: { damage: 14, maintenance: 9, inspection: 31, note: 6, loss: 2, repair: 4, unresolved: 11 },
    }),
  ),
  http.get(p('/audit-logs'), () =>
    HttpResponse.json({
      data: [
        {
          id: 4001,
          action: 'settings.update',
          entity_type: 'Setting',
          entity_id: 'booking.max_loan_days',
          user: { id: 9, display_name: 'Anna Ricci' },
          changes: { before: { value: 7 }, after: { value: 10 } },
          ip: '10.0.0.9',
          created_at: '2026-07-30T16:00:00Z',
        },
      ],
      meta: { page: 1, per_page: 30, total: 1, total_pages: 1 },
    }),
  ),
];

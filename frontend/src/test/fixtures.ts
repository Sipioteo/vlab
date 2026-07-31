/**
 * Typed fixtures mirroring SPEC §7.4 canonical representations EXACTLY.
 * These are the frontend team's contract sample data — field names are frozen.
 * Catalog flavour is taken from the real data/catalog.json.
 */
import type {
  AvailabilityCheckResponse,
  AvailabilityDatesResponse,
  Cart,
  Category,
  Closure,
  LoginResponse,
  MeResponse,
  MetaEnums,
  Order,
  OrderEvent,
  OrderSummary,
  Permissions,
  Product,
  ProductLog,
  ProductSummary,
  ProductUnit,
  PublicSettings,
  Regulation,
  Role,
  Setting,
  SettingGroupInfo,
  StatsOverview,
  SuggestedSubstitute,
  User,
} from '@/types/api';

/* --------------------------------------------------------------- permissions */

export const PERMISSIONS_BY_ROLE: Record<Role, Permissions> = {
  student: {
    'products.manage': false,
    'orders.manage': false,
    'orders.create': true,
    'logs.create': false,
    'settings.manage': false,
    'settings.view': false,
    'stats.view_full': false,
    'stats.view_limited': false,
    'users.manage': false,
    'users.view': false,
    'regulations.manage': false,
    'regulations.delete': false,
    'closures.manage': false,
    'orders.reopen': false,
    'orders.edit_full': false,
    'audit.view': false,
  },
  assistant: {
    'products.manage': false,
    'orders.manage': true,
    'orders.create': false,
    'logs.create': true,
    'settings.manage': false,
    'settings.view': true,
    'stats.view_full': false,
    'stats.view_limited': true,
    'users.manage': false,
    'users.view': true,
    'regulations.manage': false,
    'regulations.delete': false,
    'closures.manage': false,
    'orders.reopen': false,
    'orders.edit_full': false,
    'audit.view': false,
  },
  technician: {
    'products.manage': true,
    'orders.manage': true,
    'orders.create': false,
    'logs.create': true,
    'settings.manage': false,
    'settings.view': true,
    'stats.view_full': true,
    'stats.view_limited': true,
    'users.manage': false,
    'users.view': true,
    'regulations.manage': true,
    'regulations.delete': false,
    'closures.manage': true,
    'orders.reopen': false,
    'orders.edit_full': false,
    'audit.view': false,
  },
  admin: {
    'products.manage': true,
    'orders.manage': true,
    'orders.create': false,
    'logs.create': true,
    'settings.manage': true,
    'settings.view': true,
    'stats.view_full': true,
    'stats.view_limited': true,
    'users.manage': true,
    'users.view': true,
    'regulations.manage': true,
    'regulations.delete': true,
    'closures.manage': true,
    'orders.reopen': true,
    'orders.edit_full': true,
    'audit.view': true,
  },
};

/* --------------------------------------------------------------------- users */

export const studentUser: User = {
  id: 3,
  ldap_uid: 'student1',
  email: 'student1@studenti.polito.it',
  first_name: 'Marco',
  last_name: 'Rossi',
  display_name: 'Marco Rossi',
  role: 'student',
  role_label: 'Studente',
  matricola: 's123456',
  course: 'Ingegneria del Cinema e dei Mezzi di Comunicazione',
  phone: null,
  is_active: true,
  last_login_at: '2026-07-31T08:12:03Z',
  created_at: '2026-02-01T09:00:00Z',
};

export const technicianUser: User = {
  id: 5,
  ldap_uid: 'tecnico1',
  email: 'luca.ferrero@polito.it',
  first_name: 'Luca',
  last_name: 'Ferrero',
  display_name: 'Luca Ferrero',
  role: 'technician',
  role_label: 'Tecnico',
  role_locked: false,
  matricola: null,
  course: null,
  phone: null,
  is_active: true,
  last_login_at: '2026-07-31T07:40:00Z',
  created_at: '2025-09-01T09:00:00Z',
};

export const assistantUser: User = {
  id: 7,
  ldap_uid: 'borsista1',
  email: 'giulia.conti@studenti.polito.it',
  first_name: 'Giulia',
  last_name: 'Conti',
  display_name: 'Giulia Conti',
  role: 'assistant',
  role_label: 'Borsista',
  role_locked: false,
  matricola: 's654321',
  course: null,
  phone: null,
  is_active: true,
  last_login_at: '2026-07-30T15:00:00Z',
  created_at: '2026-01-15T09:00:00Z',
};

export const adminUser: User = {
  id: 9,
  ldap_uid: 'admin1',
  email: 'anna.ricci@polito.it',
  first_name: 'Anna',
  last_name: 'Ricci',
  display_name: 'Anna Ricci',
  role: 'admin',
  role_label: 'Amministratore',
  role_locked: true,
  matricola: null,
  course: null,
  phone: null,
  is_active: true,
  last_login_at: '2026-07-31T06:00:00Z',
  created_at: '2025-01-01T09:00:00Z',
};

export const USERS_BY_ROLE: Record<Role, User> = {
  student: studentUser,
  technician: technicianUser,
  assistant: assistantUser,
  admin: adminUser,
};

export function makeMe(role: Role, overrides: Partial<MeResponse> = {}): MeResponse {
  return {
    user: USERS_BY_ROLE[role],
    permissions: PERMISSIONS_BY_ROLE[role],
    pending_regulations: [],
    cart_items_count: role === 'student' ? 2 : 0,
    active_orders_count: role === 'student' ? 1 : 0,
    ...overrides,
  };
}

export function makeLogin(role: Role, overrides: Partial<LoginResponse> = {}): LoginResponse {
  return {
    access_token: `access-token-${role}`,
    token_type: 'Bearer',
    expires_in: 28800,
    expires_at: '2026-07-31T17:00:00Z',
    refresh_token: `refresh-token-${role}`,
    refresh_expires_at: '2026-08-14T09:00:00Z',
    user: USERS_BY_ROLE[role],
    pending_regulations: [],
    ...overrides,
  };
}

/* ---------------------------------------------------------------- categories */

export const categories: Category[] = [
  {
    id: 1,
    slug: 'audio',
    name: 'Audio',
    description: 'Microfoni, registratori, mixer.',
    icon: 'audio',
    image_url: null,
    parent_id: null,
    position: 10,
    is_active: true,
    products_count: 42,
  },
  {
    id: 2,
    slug: 'video',
    name: 'Video',
    description: 'Telecamere, ottiche, monitor.',
    icon: 'camera',
    image_url: null,
    parent_id: null,
    position: 20,
    is_active: true,
    products_count: 55,
  },
  {
    id: 3,
    slug: 'luci-accessori-fondali',
    name: 'Luci - Accessori - Fondali',
    description: 'Illuminazione da set e fondali.',
    icon: 'light',
    image_url: null,
    parent_id: null,
    position: 30,
    is_active: true,
    products_count: 28,
  },
  {
    id: 7,
    slug: 'tecnologie-interattive',
    name: 'Tecnologie Interattive',
    description: 'Visori VR, sensori, schede Arduino.',
    icon: 'vr',
    image_url: null,
    parent_id: null,
    position: 70,
    is_active: true,
    products_count: 34,
  },
];

const categoryRef = (id: number) => {
  const category = categories.find((c) => c.id === id) ?? categories[0]!;
  return { id: category.id, slug: category.slug, name: category.name };
};

/* ------------------------------------------------------------------ products */

export const vrHeadsetSummary: ProductSummary = {
  id: 128,
  slug: 'visore-vr-meta-quest-3',
  name: 'Visore VR Meta Quest 3 128GB',
  brand: 'Meta',
  model: 'Quest 3',
  category: categoryRef(7),
  image_url: 'https://prestitimultimedia.polito.it/foto/Meta_Quest3.jpg',
  status: 'available',
  loan_mode: 'takeaway',
  requires_training: true,
  units_total: 6,
  units_available: 5,
  has_required_regulations: true,
  is_featured: true,
};

export const micSummary: ProductSummary = {
  id: 133,
  slug: 'antivento-microfono-rode-ws7',
  name: 'Antivento Microfono Rode WS7 Large Deluxe',
  brand: 'Rode',
  model: 'WS7 Large Deluxe',
  category: categoryRef(1),
  image_url: 'https://prestitimultimedia.polito.it/foto/Rode_ws7_deluxe_windshield.jpg',
  status: 'available',
  loan_mode: 'takeaway',
  requires_training: false,
  units_total: 4,
  units_available: 4,
  has_required_regulations: false,
  is_featured: false,
};

export const cameraSummary: ProductSummary = {
  id: 140,
  slug: 'videocamera-sony-fx3',
  name: 'Videocamera Sony FX3 Full Frame',
  brand: 'Sony',
  model: 'FX3',
  category: categoryRef(2),
  image_url: 'https://prestitimultimedia.polito.it/foto/Sony_fx3.jpg',
  status: 'available',
  loan_mode: 'takeaway',
  requires_training: true,
  units_total: 3,
  units_available: 2,
  has_required_regulations: false,
  is_featured: true,
};

export const maintenanceProductSummary: ProductSummary = {
  id: 155,
  slug: 'asta-giraffa-proel-rsm180',
  name: 'Asta a giraffa per microfono Proel RSM180',
  brand: 'Proel',
  model: 'RSM180',
  category: categoryRef(1),
  image_url: 'https://prestitimultimedia.polito.it/foto/Proel_rsm_180_asta.jpg',
  status: 'maintenance',
  loan_mode: 'on_site_only',
  requires_training: false,
  units_total: 1,
  units_available: 0,
  has_required_regulations: false,
  is_featured: false,
};

export const quest512Summary: ProductSummary = {
  id: 129,
  slug: 'visore-oculus-meta-quest-3-512gb',
  name: 'Visore Oculus Meta Quest 3 512gb',
  brand: 'Meta',
  model: 'Quest 3 512GB',
  category: categoryRef(7),
  image_url: 'https://prestitimultimedia.polito.it/foto/Meta_Quest3_512.jpg',
  status: 'available',
  loan_mode: 'takeaway',
  requires_training: true,
  units_total: 17,
  units_available: 15,
  has_required_regulations: false,
  is_featured: false,
};

export const quest64Summary: ProductSummary = {
  id: 130,
  slug: 'visore-oculus-quest-all-in-one-64gb',
  name: 'Visore Oculus Quest All in one 64GB',
  brand: 'Meta',
  model: 'Quest 64GB',
  category: categoryRef(7),
  image_url: null,
  status: 'available',
  loan_mode: 'takeaway',
  requires_training: true,
  units_total: 7,
  units_available: 7,
  has_required_regulations: false,
  is_featured: false,
};

export const productSummaries: ProductSummary[] = [
  vrHeadsetSummary,
  cameraSummary,
  micSummary,
  maintenanceProductSummary,
];

/** Every summary the handlers can resolve by id (catalog + substitutes). */
export const productSummaryPool: ProductSummary[] = [
  ...productSummaries,
  quest512Summary,
  quest64Summary,
];

export const productUnits: ProductUnit[] = [
  {
    id: 512,
    product_id: 128,
    label: '01',
    serial_number: 'QST3-9981223',
    asset_code: 'INV-2024-00417',
    purchase_date: '2024-03-15',
    inspection_date: '2026-01-20',
    next_inspection_date: '2027-01-20',
    status: 'available',
    status_label: 'Prestabile',
    condition_note: null,
    location: 'Armadio B / ripiano 2',
    current_order: null,
    created_at: '2024-03-16T08:00:00Z',
  },
  {
    id: 513,
    product_id: 128,
    label: '02',
    serial_number: 'QST3-9981224',
    asset_code: 'INV-2024-00418',
    purchase_date: '2024-03-15',
    inspection_date: '2026-01-20',
    next_inspection_date: '2027-01-20',
    status: 'available',
    status_label: 'Prestabile',
    condition_note: null,
    location: 'Armadio B / ripiano 2',
    current_order: null,
    created_at: '2024-03-16T08:00:00Z',
  },
];

export const productLogs: ProductLog[] = [
  {
    id: 900,
    product_id: 128,
    product_unit_id: 512,
    unit_label: '01',
    order_id: null,
    order_code: null,
    type: 'damage',
    type_label: 'Danno',
    severity: 'warning',
    title: 'Molla del cinturino persa',
    body: 'Manca la molla di regolazione destra.',
    occurred_at: '2026-06-12T15:40:00Z',
    resolved_at: null,
    is_public: true,
    user: null,
    created_at: '2026-06-12T15:41:11Z',
  },
];

export const vrHeadsetProduct: Product = {
  ...vrHeadsetSummary,
  description: 'Visore standalone per esperienze di realtà virtuale e mixed reality.',
  specs: [
    { label: 'Risoluzione', value: '2064x2208 per occhio' },
    { label: 'Memoria', value: '128 GB' },
  ],
  images: [
    {
      id: 41,
      url: 'https://prestitimultimedia.polito.it/foto/Meta_Quest3.jpg',
      alt: 'Vista frontale',
      position: 0,
    },
  ],
  min_loan_days: null,
  max_loan_days: 3,
  replacement_value_note: '€ 550 ca.',
  source_notes: 'Catalogo DAUIN 2024',
  position: 10,
  recommended_products: [{ relation: 'accessory', position: 0, product: micSummary }],
  substitutes: [
    { priority: 1, product: quest512Summary },
    { priority: 2, product: quest64Summary },
  ],
  regulations: [
    {
      id: 4,
      slug: 'avvertenze-vr',
      title: 'Avvertenze uso visori VR',
      scope: 'category',
      version: 2,
      requires_acceptance: true,
    },
  ],
  recent_logs: productLogs,
  created_at: '2026-01-10T10:00:00Z',
  updated_at: '2026-07-02T14:31:00Z',
};

export const maintenanceProduct: Product = {
  ...maintenanceProductSummary,
  description: 'Asta a giraffa con base tripoide.',
  specs: [],
  images: [],
  min_loan_days: null,
  max_loan_days: null,
  replacement_value_note: null,
  source_notes: null,
  position: 20,
  recommended_products: [],
  substitutes: [],
  regulations: [],
  recent_logs: [],
  created_at: '2026-01-10T10:00:00Z',
  updated_at: '2026-07-02T14:31:00Z',
};

/* ---------------------------------------------------------------------- cart */

export const emptyCart: Cart = {
  id: 91,
  status: 'draft',
  pickup_date: null,
  pickup_time: null,
  return_date: null,
  return_time: null,
  items: [],
  items_count: 0,
  distinct_products: 0,
  check: null,
  updated_at: '2026-07-31T08:59:00Z',
};

export const availabilityCheckOk: AvailabilityCheckResponse = {
  ok: true,
  can_submit: true,
  exceeds_limits: false,
  violations: [],
  duration_days: 4,
  availability: [{ product_id: 128, requested: 1, available: 4, capacity: 6, sufficient: true }],
  required_regulations: [],
  pickup_slots: [
    { start: '09:00', end: '09:30' },
    { start: '09:30', end: '10:00' },
  ],
  return_slots: [{ start: '15:30', end: '16:00' }],
  quota: {
    orders_this_month: 2,
    max_orders_per_month: 4,
    orders_this_year: 9,
    max_orders_per_year: null,
    active_orders: 1,
    max_active_orders: 2,
  },
};

export const availabilityCheckSoft: AvailabilityCheckResponse = {
  ...availabilityCheckOk,
  ok: false,
  can_submit: true,
  exceeds_limits: true,
  duration_days: 12,
  violations: [
    {
      code: 'max_loan_days_exceeded',
      severity: 'soft',
      message: 'La durata richiesta (12 giorni) supera il limite di 7 giorni.',
      limit: 7,
      actual: 12,
      product_ids: [],
    },
  ],
};

export const availabilityCheckHard: AvailabilityCheckResponse = {
  ...availabilityCheckOk,
  ok: false,
  can_submit: false,
  exceeds_limits: false,
  violations: [
    {
      code: 'insufficient_availability',
      severity: 'hard',
      message: 'Il visore VR non è disponibile nelle date scelte.',
      limit: null,
      actual: null,
      product_ids: [128],
    },
  ],
};

/** Substitute suggestions for the VR headset (128), priority-ordered. */
export const vrSuggestedSubstitutes: SuggestedSubstitute[] = [
  {
    product_id: quest512Summary.id,
    name: quest512Summary.name,
    slug: quest512Summary.slug,
    image_url: quest512Summary.image_url,
    available_quantity: 3,
    priority: 1,
  },
  {
    product_id: quest64Summary.id,
    name: quest64Summary.name,
    slug: quest64Summary.slug,
    image_url: quest64Summary.image_url,
    available_quantity: 2,
    priority: 2,
  },
];

/** Item 128 unavailable in the range; its suggested substitutes attached. */
export const availabilityCheckWithSubstitutes: AvailabilityCheckResponse = {
  ...availabilityCheckOk,
  ok: false,
  can_submit: false,
  exceeds_limits: false,
  violations: [
    {
      code: 'insufficient_availability',
      severity: 'hard',
      message: 'Il visore VR non è disponibile nelle date scelte.',
      limit: null,
      actual: null,
      product_ids: [128],
    },
  ],
  availability: [
    {
      product_id: 128,
      requested: 1,
      available: 0,
      capacity: 6,
      sufficient: false,
      suggested_substitutes: vrSuggestedSubstitutes,
    },
    { product_id: 133, requested: 2, available: 4, capacity: 4, sufficient: true },
  ],
};

export const availabilityCheckWithRegulation: AvailabilityCheckResponse = {
  ...availabilityCheckOk,
  required_regulations: [
    {
      id: 4,
      slug: 'avvertenze-vr',
      title: 'Avvertenze uso visori VR',
      version: 2,
      accepted: false,
      scope: 'category',
    },
  ],
};

export const cartWithItems: Cart = {
  id: 91,
  status: 'draft',
  pickup_date: '2026-08-03',
  pickup_time: '09:30',
  return_date: '2026-08-06',
  return_time: '16:00',
  items: [
    {
      id: 812,
      product_id: 128,
      quantity: 1,
      notes: null,
      product: vrHeadsetSummary,
      available_quantity: 4,
      sufficient: true,
    },
    {
      id: 813,
      product_id: 133,
      quantity: 2,
      notes: null,
      product: micSummary,
      available_quantity: 4,
      sufficient: true,
    },
  ],
  items_count: 3,
  distinct_products: 2,
  check: availabilityCheckOk,
  updated_at: '2026-07-31T08:59:00Z',
};

/** Same cart, but the VR headset is unavailable in the chosen range. */
export const cartWithUnavailableItem: Cart = {
  ...cartWithItems,
  items: [
    { ...cartWithItems.items[0]!, available_quantity: 0, sufficient: false },
    cartWithItems.items[1]!,
  ],
  check: availabilityCheckWithSubstitutes,
};

/* -------------------------------------------------------------------- orders */

export const orderEvents: OrderEvent[] = [
  {
    id: 309,
    from_status: 'draft',
    to_status: 'pending',
    action: 'submit',
    action_label: 'Inviata',
    actor: { id: 3, display_name: 'Marco Rossi', role: 'student' },
    actor_type: 'user',
    comment: null,
    meta: null,
    created_at: '2026-07-25T11:02:00Z',
  },
  {
    id: 310,
    from_status: 'pending',
    to_status: 'approved',
    action: 'approve',
    action_label: 'Approvato',
    actor: { id: 5, display_name: 'Luca Ferrero', role: 'technician' },
    actor_type: 'user',
    comment: 'Confermato, ritiro alle 9:30.',
    meta: null,
    created_at: '2026-07-26T09:14:00Z',
  },
];

export const orderSummaries: OrderSummary[] = [
  {
    id: 88,
    code: 'VL-2026-0088',
    status: 'approved',
    status_label: 'Approvato',
    user: { id: 3, display_name: 'Marco Rossi', ldap_uid: 'student1' },
    pickup_date: '2026-08-01',
    pickup_time: '09:30',
    return_date: '2026-08-04',
    return_time: '16:00',
    items_count: 3,
    distinct_products: 2,
    exceeds_limits: false,
    is_late: false,
    late_days: null,
    submitted_at: '2026-07-25T11:02:00Z',
    created_at: '2026-07-25T10:58:00Z',
  },
  {
    id: 89,
    code: 'VL-2026-0089',
    status: 'pending',
    status_label: 'In attesa',
    user: { id: 3, display_name: 'Marco Rossi', ldap_uid: 'student1' },
    pickup_date: '2026-08-10',
    pickup_time: '10:00',
    return_date: '2026-08-12',
    return_time: '16:00',
    items_count: 1,
    distinct_products: 1,
    exceeds_limits: true,
    is_late: false,
    late_days: null,
    submitted_at: '2026-07-28T09:00:00Z',
    created_at: '2026-07-28T08:58:00Z',
  },
];

export function makeOrder(overrides: Partial<Order> = {}): Order {
  const base = orderSummaries[0]!;
  return {
    ...base,
    subject: 'Laboratorio di Ripresa e Montaggio',
    motivation: "Riprese del cortometraggio d'esame.",
    professor: 'Prof.ssa Rossi',
    notes: 'Ritiro previsto in mattinata.',
    rejection_reason: null,
    limit_violations: [],
    picked_up_at: null,
    returned_at: null,
    decided_by: { id: 5, display_name: 'Luca Ferrero' },
    decided_at: '2026-07-26T09:14:00Z',
    handed_over_by: null,
    received_by: null,
    cancelled_at: null,
    items: [
      {
        id: 771,
        product_id: 128,
        quantity: 1,
        notes: null,
        returned_quantity: 0,
        product: vrHeadsetSummary,
        product_name_snapshot: 'Visore VR Meta Quest 3 128GB',
        product_brand_snapshot: 'Meta',
      },
    ],
    events: orderEvents,
    required_regulations: [
      {
        id: 4,
        slug: 'avvertenze-vr',
        title: 'Avvertenze uso visori VR',
        version: 2,
        accepted: true,
        accepted_at: '2026-07-25T11:01:55Z',
      },
    ],
    allowed_actions: ['cancel'],
    updated_at: '2026-07-26T09:14:00Z',
    ...overrides,
  };
}

/** Staff view of a pending order: full transition set + staff_notes. */
export function makeStaffOrder(overrides: Partial<Order> = {}): Order {
  return makeOrder({
    id: 89,
    code: 'VL-2026-0089',
    status: 'pending',
    status_label: 'In attesa',
    staff_notes: 'Studente affidabile.',
    allowed_actions: ['approve', 'reject', 'cancel', 'edit', 'note'],
    decided_by: null,
    decided_at: null,
    items: [
      {
        id: 771,
        product_id: 128,
        quantity: 1,
        notes: null,
        returned_quantity: 0,
        product: vrHeadsetSummary,
        product_name_snapshot: 'Visore VR Meta Quest 3 128GB',
        product_brand_snapshot: 'Meta',
        assigned_units: [],
      },
    ],
    ...overrides,
  });
}

/* --------------------------------------------------------------- regulations */

export const vrRegulation: Regulation = {
  id: 4,
  slug: 'avvertenze-vr',
  title: 'Avvertenze uso visori VR',
  summary: 'Rischi fotosensibilità ed epilessia.',
  scope: 'category',
  content_type: 'markdown',
  body: '# Avvertenze\n\nL’uso dei visori può provocare crisi in soggetti fotosensibili.\n\n- Interrompi l’uso in caso di malessere.\n- Non usare il visore in movimento.',
  file_url: null,
  file_name: null,
  file_size: null,
  version: 2,
  requires_acceptance: true,
  is_active: true,
  published_at: '2026-03-01T10:00:00Z',
  position: 20,
  targets: [{ target_type: 'category', target_id: 7, target_name: 'Tecnologie Interattive' }],
  acceptance: null,
  created_at: '2026-02-20T09:00:00Z',
  updated_at: '2026-03-01T10:00:00Z',
};

export const globalRegulation: Regulation = {
  id: 1,
  slug: 'regolamento-generale',
  title: 'Regolamento generale del laboratorio',
  summary: 'Regole di utilizzo delle attrezzature.',
  scope: 'global',
  content_type: 'markdown',
  body: '# Regolamento generale\n\nLe attrezzature vanno restituite integre e nei tempi previsti.',
  file_url: null,
  file_name: null,
  file_size: null,
  version: 3,
  requires_acceptance: true,
  is_active: true,
  published_at: '2026-01-05T10:00:00Z',
  position: 10,
  targets: [],
  acceptance: null,
  created_at: '2025-12-01T09:00:00Z',
  updated_at: '2026-01-05T10:00:00Z',
};

export const pendingGlobalRegulation = {
  id: 1,
  slug: 'regolamento-generale',
  title: 'Regolamento generale del laboratorio',
  summary: 'Regole di utilizzo delle attrezzature.',
  scope: 'global' as const,
  version: 3,
  content_type: 'markdown' as const,
  file_url: null,
  blocking: true,
};

/* ------------------------------------------------------------------ closures */

export const closures: Closure[] = [
  {
    id: 2,
    title: 'Chiusura estiva',
    description: 'Il laboratorio resta chiuso.',
    start_date: '2026-08-08',
    end_date: '2026-08-23',
    blocks_pickup: true,
    blocks_return: true,
    is_recurring_yearly: false,
    created_at: '2026-05-02T10:00:00Z',
  },
];

/* ------------------------------------------------------------------ settings */

export const settingGroups: SettingGroupInfo[] = [
  { key: 'lab', label_it: 'Laboratorio', position: 10 },
  { key: 'hours', label_it: 'Orari e chiusure', position: 20 },
  { key: 'booking', label_it: 'Prenotazioni e limiti', position: 30 },
];

export const settings: Setting[] = [
  {
    key: 'lab.name',
    value: 'Visionary Lab',
    type: 'string',
    group: 'lab',
    label_it: 'Nome del laboratorio',
    description_it: null,
    is_public: true,
    is_secret: false,
    nullable: false,
    options: null,
    position: 10,
    updated_at: '2026-07-01T12:00:00Z',
  },
  {
    key: 'booking.max_loan_days',
    value: 7,
    type: 'int',
    group: 'booking',
    label_it: 'Durata massima del prestito (giorni)',
    description_it: 'Numero massimo di giorni consecutivi per un prestito standard.',
    is_public: true,
    is_secret: false,
    nullable: false,
    options: null,
    position: 10,
    updated_at: '2026-07-01T12:00:00Z',
  },
  {
    key: 'booking.max_orders_per_month',
    value: 4,
    type: 'int',
    group: 'booking',
    label_it: 'Numero massimo di prestiti al mese',
    description_it: 'null = illimitato',
    is_public: true,
    is_secret: false,
    nullable: true,
    options: null,
    position: 20,
    updated_at: '2026-07-01T12:00:00Z',
  },
  {
    key: 'booking.require_motivation',
    value: true,
    type: 'bool',
    group: 'booking',
    label_it: 'La motivazione è obbligatoria',
    description_it: null,
    is_public: true,
    is_secret: false,
    nullable: false,
    options: null,
    position: 30,
    updated_at: '2026-07-01T12:00:00Z',
  },
  {
    key: 'hours.weekly',
    value: [
      { weekday: 0, closed: true, open: null, close: null },
      { weekday: 1, closed: false, open: '09:00', close: '17:00' },
      { weekday: 2, closed: false, open: '09:00', close: '17:00' },
      { weekday: 3, closed: false, open: '09:00', close: '17:00' },
      { weekday: 4, closed: false, open: '09:00', close: '17:00' },
      { weekday: 5, closed: false, open: '09:00', close: '14:00' },
      { weekday: 6, closed: true, open: null, close: null },
    ],
    type: 'json',
    group: 'hours',
    label_it: 'Orari di apertura per giorno della settimana',
    description_it: null,
    is_public: true,
    is_secret: false,
    nullable: false,
    options: null,
    position: 20,
    updated_at: '2026-07-01T12:00:00Z',
  },
  {
    key: 'hours.pickup_windows',
    value: [
      { weekday: 1, from: '09:00', to: '12:30' },
      { weekday: 2, from: '09:00', to: '12:30' },
    ],
    type: 'json',
    group: 'hours',
    label_it: 'Fasce orarie per il ritiro',
    description_it: null,
    is_public: true,
    is_secret: false,
    nullable: false,
    options: null,
    position: 30,
    updated_at: '2026-07-01T12:00:00Z',
  },
];

export const publicSettings: PublicSettings = {
  'lab.name': 'Visionary Lab',
  'lab.subtitle': 'Politecnico di Torino — Prestito attrezzature',
  'lab.department': 'DAUIN — Ingegneria del Cinema e dei Mezzi di Comunicazione',
  'lab.email': 'visionarylab@polito.it',
  'lab.phone': '+39 011 090 0000',
  'lab.address': 'Corso Duca degli Abruzzi 24, 10129 Torino',
  'lab.room': 'Aula 3I - DAUIN',
  'lab.website_url': 'https://www.polito.it',
  'booking.max_loan_days': 7,
  'booking.max_orders_per_month': 4,
  'booking.max_orders_per_year': null,
  'booking.min_advance_days': 1,
  'booking.max_advance_days': 90,
  'booking.max_items_per_order': 10,
  'booking.max_quantity_per_product_per_order': 2,
  'booking.require_professor': false,
  'booking.require_motivation': true,
  'booking.motivation_min_length': 20,
  'booking.cancellation_deadline_hours': 24,
  'hours.timezone': 'Europe/Rome',
  'hours.slot_duration_minutes': 30,
  'ui.primary_color': '#00284B',
  'ui.accent_color': '#EF7B02',
  'ui.highlight_color': '#00C2CB',
  'ui.items_per_page': 24,
  'ui.catalog_default_view': 'grid',
  'ui.banner_enabled': false,
  'ui.banner_message_it': '',
  'ui.banner_level': 'info',
  'ui.hero_image_url': null,
  'ui.show_unit_codes_to_students': false,
  'ui.allow_anonymous_catalog': true,
  'ui.footer_note_it': '© Politecnico di Torino',
};

/* --------------------------------------------------------------- meta/enums */

export const metaEnums: MetaEnums = {
  order_status: [
    { value: 'draft', label: 'Bozza', is_terminal: false, locks_stock: false },
    { value: 'pending', label: 'In attesa', is_terminal: false, locks_stock: true },
    { value: 'approved', label: 'Approvato', is_terminal: false, locks_stock: true },
    { value: 'rejected', label: 'Respinto', is_terminal: true, locks_stock: false },
    { value: 'cancelled', label: 'Annullato', is_terminal: true, locks_stock: false },
    { value: 'picked_up', label: 'Ritirato', is_terminal: false, locks_stock: true },
    { value: 'overdue', label: 'In ritardo', is_terminal: false, locks_stock: true },
    { value: 'returned', label: 'Restituito', is_terminal: true, locks_stock: false },
    { value: 'returned_late', label: 'Restituito in ritardo', is_terminal: true, locks_stock: false },
    { value: 'no_show', label: 'Non ritirato', is_terminal: true, locks_stock: false },
  ],
  product_status: [
    { value: 'available', label: 'Disponibile' },
    { value: 'maintenance', label: 'In manutenzione' },
    { value: 'retired', label: 'Dismesso' },
  ],
  unit_status: [
    { value: 'available', label: 'Prestabile' },
    { value: 'maintenance', label: 'In manutenzione' },
    { value: 'missing', label: 'Mancante' },
    { value: 'retired', label: 'Dismesso' },
    { value: 'internal_use', label: 'In uso interno' },
  ],
  loan_mode: [
    { value: 'takeaway', label: 'Asportabile' },
    { value: 'on_site_only', label: 'Solo in sede' },
  ],
  log_type: [
    { value: 'damage', label: 'Danno' },
    { value: 'maintenance', label: 'Manutenzione' },
    { value: 'inspection', label: 'Collaudo' },
    { value: 'note', label: 'Nota' },
    { value: 'loss', label: 'Smarrimento' },
    { value: 'repair', label: 'Riparazione' },
  ],
  log_severity: [
    { value: 'info', label: 'Informazione' },
    { value: 'warning', label: 'Attenzione' },
    { value: 'critical', label: 'Critico' },
  ],
  role: [
    { value: 'student', label: 'Studente' },
    { value: 'technician', label: 'Tecnico' },
    { value: 'assistant', label: 'Borsista' },
    { value: 'admin', label: 'Amministratore' },
  ],
  regulation_scope: [
    { value: 'global', label: 'Globale' },
    { value: 'category', label: 'Categoria' },
    { value: 'product', label: 'Prodotto' },
  ],
  recommendation_relation: [
    { value: 'accessory', label: 'Accessorio' },
    { value: 'alternative', label: 'Alternativa' },
    { value: 'required_with', label: 'Necessario insieme' },
  ],
  condition: [
    { value: 'ok', label: 'Integro' },
    { value: 'damaged', label: 'Danneggiato' },
    { value: 'incomplete', label: 'Incompleto' },
    { value: 'missing', label: 'Mancante' },
  ],
};

/* ------------------------------------------------------------- availability */

export const availabilityDates: AvailabilityDatesResponse = {
  range: { from: '2026-08-01', to: '2026-08-14' },
  duration_days: 3,
  days: Array.from({ length: 14 }, (_, i) => {
    const date = `2026-08-${String(i + 1).padStart(2, '0')}`;
    const jsDay = new Date(`${date}T00:00:00`).getDay();
    const isOpen = jsDay !== 0 && jsDay !== 6;
    return {
      date,
      all_available: isOpen && i !== 5,
      is_open: isOpen,
      can_pickup: isOpen,
      can_return: isOpen,
      closure_id: null,
      per_product: [
        { product_id: 128, requested: 1, available: i === 5 ? 0 : 4, sufficient: i !== 5 },
      ],
    };
  }),
  windows: [
    {
      pickup_date: '2026-08-03',
      return_date: '2026-08-05',
      days: 3,
      all_available: true,
      blocking_product_ids: [],
    },
    {
      pickup_date: '2026-08-04',
      return_date: '2026-08-06',
      days: 3,
      all_available: false,
      blocking_product_ids: [128],
    },
  ],
  first_available_window: { pickup_date: '2026-08-03', return_date: '2026-08-05', days: 3 },
  unavailable_products: [],
};

export const statsOverviewFull: StatsOverview = {
  scope: 'full',
  range: { from: '2026-05-02', to: '2026-07-31' },
  operational: {
    orders_pending: 4,
    orders_approved: 7,
    orders_picked_up: 3,
    orders_overdue: 1,
    pickups_today: 2,
    returns_today: 3,
    returns_next_7_days: 9,
  },
  totals: {
    orders_total: 128,
    orders_approved: 96,
    orders_rejected: 12,
    orders_cancelled: 14,
    orders_no_show: 6,
    orders_returned_late: 11,
    approval_rate: 0.75,
    late_rate: 0.114,
    items_loaned: 341,
    unique_students: 74,
    avg_loan_days: 4.2,
    avg_approval_hours: 18.6,
  },
  inventory: {
    products_total: 412,
    units_total: 1187,
    units_available: 1042,
    units_maintenance: 61,
    units_missing: 9,
    units_retired: 75,
    units_on_loan_now: 38,
    utilization_now: 0.036,
  },
};

export const statsOverviewLimited: StatsOverview = {
  scope: 'limited',
  range: { from: '2026-05-02', to: '2026-07-31' },
  operational: statsOverviewFull.operational,
};

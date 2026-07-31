import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import * as f from '@/test/fixtures';

/**
 * Admin full order editing (`orders.edit_full`) — panel gating, availability
 * conflicts and the force override — plus the student-side freeze.
 */

const requests: { method: string; path: string; body: unknown }[] = [];
let stop: (() => void) | null = null;

beforeEach(() => {
  requests.length = 0;
  const handler = async ({ request }: { request: Request }) => {
    let body: unknown = null;
    if (request.method !== 'GET' && request.method !== 'DELETE') {
      try {
        body = await request.clone().json();
      } catch {
        body = null;
      }
    }
    requests.push({ method: request.method, path: new URL(request.url).pathname, body });
  };
  server.events.on('request:start', handler);
  stop = () => server.events.removeListener('request:start', handler);
});
afterEach(() => stop?.());

function find(method: string, path: string) {
  return requests.find((r) => r.method === method && r.path === path);
}

describe('StaffOrderDetailPage — admin full edit', () => {
  it('shows the edit affordance to admins only', async () => {
    const view = renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'admin' });
    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
    expect(screen.getByRole('button', { name: 'Modifica prestito' })).toBeVisible();
    view.unmount();

    for (const role of ['technician', 'assistant'] as const) {
      const v = renderWithProviders(<App />, { route: '/gestione/ordini/89', role });
      await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
      expect(screen.queryByRole('button', { name: 'Modifica prestito' })).toBeNull();
      v.unmount();
    }
  });

  it('opens the panel prefilled and PUTs the edited payload', async () => {
    const user = userEvent.setup();
    server.use(
      http.put('/api/v1/orders/:id', () =>
        HttpResponse.json(f.makeStaffOrder({ subject: 'Materia corretta' })),
      ),
    );
    renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'admin' });

    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
    await user.click(screen.getByRole('button', { name: 'Modifica prestito' }));

    const dialog = await screen.findByRole('dialog');
    const subject = within(dialog).getByLabelText('Materia');
    expect(subject).toHaveValue('Laboratorio di Ripresa e Montaggio');
    await user.clear(subject);
    await user.type(subject, 'Materia corretta');
    await user.click(within(dialog).getByRole('button', { name: 'Salva' }));

    await waitFor(() => {
      const put = find('PUT', '/api/v1/orders/89');
      expect(put).toBeDefined();
      expect(put?.body).toMatchObject({
        subject: 'Materia corretta',
        items: [{ product_id: 128, quantity: 1 }],
      });
      expect((put?.body as Record<string, unknown>)['force']).toBeUndefined();
    });
  });

  it('renders availability conflicts inline and resubmits with force', async () => {
    const user = userEvent.setup();
    let calls = 0;
    server.use(
      http.put('/api/v1/orders/:id', () => {
        calls += 1;
        if (calls === 1) {
          return HttpResponse.json(
            {
              error: {
                code: 'insufficient_availability',
                message: 'La disponibilità non è sufficiente per alcuni prodotti nel periodo selezionato.',
                details: {
                  products: [
                    {
                      product_id: 128,
                      name: 'Visore VR Meta Quest 3 128GB',
                      requested: 1,
                      available: 0,
                    },
                  ],
                },
                trace_id: 'aabbccdd',
              },
            },
            { status: 422 },
          );
        }
        return HttpResponse.json(
          f.makeStaffOrder({
            forced_overbook: true,
            overbooked_products: [
              { product_id: 128, name: 'Visore VR Meta Quest 3 128GB', requested: 1, available: 0 },
            ],
          }),
        );
      }),
    );
    renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'admin' });

    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
    await userEvent.setup().click(screen.getByRole('button', { name: 'Modifica prestito' }));

    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Salva' }));

    // Inline conflict with the product name, requested vs available.
    expect(await within(dialog).findByText('Disponibilità non sufficiente')).toBeVisible();
    expect(
      within(dialog).getByText('Visore VR Meta Quest 3 128GB: richieste 1, disponibili 0.'),
    ).toBeVisible();

    // Force is off by default, with a sober warning once enabled.
    const force = within(dialog).getByRole('switch', {
      name: 'Forza la modifica anche senza disponibilità',
    });
    expect(force).not.toBeChecked();
    await user.click(force);
    expect(
      within(dialog).getByText(/registrare quello che è successo davvero/),
    ).toBeVisible();

    await user.click(within(dialog).getByRole('button', { name: 'Salva' }));
    await waitFor(() => {
      const puts = requests.filter((r) => r.method === 'PUT' && r.path === '/api/v1/orders/89');
      expect(puts).toHaveLength(2);
      expect(puts[1]?.body).toMatchObject({ force: true });
    });
  });
});

describe('OrderDetailPage — student side is frozen', () => {
  it('shows the frozen note on a pending order and no edit affordance', async () => {
    server.use(
      http.get('/api/v1/orders/:id', () =>
        HttpResponse.json(
          f.makeOrder({ id: 41, status: 'pending', status_label: 'In attesa', allowed_actions: ['cancel'] }),
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/ordini/41', role: 'student' });

    await screen.findByRole('heading', { name: /Richiesta/, level: 1 });
    expect(
      screen.getByText(/La richiesta non si può modificare una volta inviata/),
    ).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Modifica prestito' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Cambia date' })).toBeNull();
    // cancel is still there, from allowed_actions
    expect(screen.getByRole('button', { name: 'Annulla richiesta' })).toBeVisible();
  });
});

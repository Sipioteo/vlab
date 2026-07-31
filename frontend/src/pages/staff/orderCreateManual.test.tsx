import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import * as f from '@/test/fixtures';
import { todayIso } from '@/lib/format';

/**
 * Staff manual loan creation (`orders.create_manual`) — button gating on
 * /gestione/ordini, payload correctness, availability conflict + admin force,
 * success navigation and the pending-regulations notice.
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

function posts() {
  return requests.filter((r) => r.method === 'POST' && r.path === '/api/v1/orders/manual');
}

/** Selects Marco Rossi, adds the VR headset and fills the two time slots. */
async function fillMinimalForm(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByRole('heading', { name: 'Nuovo prestito manuale', level: 1 });

  await user.type(screen.getByLabelText('Studente'), 'marco');
  await user.click(await screen.findByRole('button', { name: /Marco Rossi · student1/ }));

  await user.type(screen.getByLabelText('Aggiungi attrezzatura'), 'visore');
  await user.click(await screen.findByRole('button', { name: /Visore VR Meta Quest 3 128GB/ }));

  fireEvent.change(screen.getByLabelText('Ritiro'), { target: { value: '09:30' } });
  fireEvent.change(screen.getByLabelText('Riconsegna'), { target: { value: '16:00' } });
}

describe('StaffOrdersPage — "Nuovo prestito" gating', () => {
  it('shows the button to roles with orders.create_manual only', async () => {
    const view = renderWithProviders(<App />, { route: '/gestione/ordini', role: 'technician' });
    await screen.findByRole('heading', { name: 'Coda delle richieste', level: 1 });
    expect(screen.getByRole('link', { name: 'Nuovo prestito' })).toBeVisible();
    view.unmount();

    const v2 = renderWithProviders(<App />, { route: '/gestione/ordini', role: 'assistant' });
    await screen.findByRole('heading', { name: 'Coda delle richieste', level: 1 });
    expect(screen.queryByRole('link', { name: 'Nuovo prestito' })).toBeNull();
    v2.unmount();
  });
});

describe('StaffOrderCreatePage', () => {
  it('submits the manual creation payload and navigates to the new order', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/ordini/nuovo', role: 'technician' });
    await fillMinimalForm(user);

    // The overbook force override is admin territory (orders.edit_full).
    expect(
      screen.queryByRole('switch', { name: 'Forza la creazione anche senza disponibilità' }),
    ).toBeNull();

    await user.type(screen.getByLabelText(/Materia/), 'Laboratorio di Ripresa');
    await user.click(screen.getByRole('button', { name: 'Crea prestito' }));

    await waitFor(() => {
      expect(posts()).toHaveLength(1);
    });
    expect(posts()[0]?.body).toMatchObject({
      user_id: 3,
      items: [{ product_id: 128, quantity: 1 }],
      start_date: todayIso(),
      end_date: todayIso(),
      pickup_time: '09:30',
      return_time: '16:00',
      subject: 'Laboratorio di Ripresa',
      initial_status: 'approved',
    });
    expect((posts()[0]?.body as Record<string, unknown>)['force']).toBeUndefined();

    // Success toast mentions the printable module, and we land on the detail
    // page of the created order (id 120 from the mock).
    expect(
      await screen.findByText('Prestito creato. Stampa il modulo per la firma.'),
    ).toBeVisible();
    await screen.findByRole('heading', { name: /Richiesta VL-2026/, level: 1 });
    expect(requests.some((r) => r.method === 'GET' && r.path === '/api/v1/orders/120')).toBe(true);
  });

  it('supports the pending initial state', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/ordini/nuovo', role: 'technician' });
    await fillMinimalForm(user);
    await user.selectOptions(screen.getByLabelText('Stato iniziale'), 'pending');
    await user.click(screen.getByRole('button', { name: 'Crea prestito' }));
    await waitFor(() => expect(posts()).toHaveLength(1));
    expect(posts()[0]?.body).toMatchObject({ initial_status: 'pending' });
  });

  it('renders the availability conflict and lets an admin force the creation', async () => {
    const user = userEvent.setup();
    let calls = 0;
    server.use(
      http.post('/api/v1/orders/manual', () => {
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
            id: 120,
            code: 'VL-2026-0120',
            status: 'approved',
            forced_overbook: true,
            overbooked_products: [
              { product_id: 128, name: 'Visore VR Meta Quest 3 128GB', requested: 1, available: 0 },
            ],
          }),
          { status: 201 },
        );
      }),
    );
    renderWithProviders(<App />, { route: '/gestione/ordini/nuovo', role: 'admin' });
    await fillMinimalForm(user);
    await user.click(screen.getByRole('button', { name: 'Crea prestito' }));

    // Inline conflict with product name, requested vs available, sober hint.
    expect(await screen.findByText('Disponibilità non sufficiente')).toBeVisible();
    expect(
      screen.getByText('Visore VR Meta Quest 3 128GB: richieste 1, disponibili 0.'),
    ).toBeVisible();

    const force = screen.getByRole('switch', {
      name: 'Forza la creazione anche senza disponibilità',
    });
    expect(force).not.toBeChecked();
    await user.click(force);
    expect(screen.getByText(/registrare quello che è successo davvero/)).toBeVisible();

    await user.click(screen.getByRole('button', { name: 'Crea prestito' }));
    await waitFor(() => expect(posts()).toHaveLength(2));
    expect(posts()[1]?.body).toMatchObject({ force: true });
    expect(
      await screen.findByText('Prestito creato forzando la disponibilità: risulterà sovraprenotato.'),
    ).toBeVisible();
  });

  it('shows the non-blocking regulation notice when the student has pending regulations', async () => {
    const user = userEvent.setup();
    server.use(
      http.post('/api/v1/orders/manual', () =>
        HttpResponse.json(
          f.makeStaffOrder({
            id: 120,
            code: 'VL-2026-0120',
            status: 'approved',
            pending_regulations: [
              {
                id: 1,
                slug: 'regolamento-generale',
                title: 'Regolamento generale del laboratorio',
                scope: 'global',
                version: 3,
                content_type: 'markdown',
                blocking: true,
              },
            ],
          }),
          { status: 201 },
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/gestione/ordini/nuovo', role: 'technician' });
    await fillMinimalForm(user);
    await user.click(screen.getByRole('button', { name: 'Crea prestito' }));
    expect(
      await screen.findByText(
        'Attenzione: lo studente non ha ancora accettato il regolamento vigente — fallo firmare sul modulo.',
      ),
    ).toBeVisible();
  });
});

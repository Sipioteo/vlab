import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import * as f from '@/test/fixtures';

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

describe('StaffOrdersPage', () => {
  it('renders the queue and approves a row from allowed_actions, updating its status', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/ordini', role: 'technician' });

    expect(await screen.findByRole('heading', { name: 'Coda delle richieste', level: 1 })).toBeVisible();
    const row = (await screen.findByText('VL-2026-0089')).closest('tr') as HTMLElement;
    await user.click(within(row).getByRole('button', { name: /Dettagli/ }));

    const dialog = await screen.findByRole('dialog');
    const approve = await within(dialog).findByRole('button', { name: 'Approva' });
    await user.click(approve);

    await waitFor(() => {
      expect(find('POST', '/api/v1/orders/89/approve')).toBeDefined();
    });
    await waitFor(() => {
      expect(within(row).getByText('Approvato')).toBeVisible();
    });
  });

  it('requires a non-empty reason before enabling the reject submit', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'technician' });

    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
    // Destructive transitions live in the "Altro" overflow (owner request E).
    await user.click(screen.getByRole('button', { name: 'Altro' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Rifiuta' }));

    const dialog = await screen.findByRole('dialog');
    const submit = within(dialog).getByRole('button', { name: 'Rifiuta' });
    expect(submit).toBeDisabled();

    await user.type(within(dialog).getByLabelText('Motivo del rifiuto'), 'Non disponibile.');
    expect(submit).toBeEnabled();

    await user.click(submit);
    await waitFor(() => {
      const post = find('POST', '/api/v1/orders/89/reject');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({ reason: 'Non disponibile.' });
    });
  });
});

describe('StaffOrderDetailPage pickup & return', () => {
  it('keeps the pickup submit disabled until the selected units match the quantity', async () => {
    const user = userEvent.setup();
    server.use(
      http.get('/api/v1/orders/:id', () =>
        HttpResponse.json(
          f.makeStaffOrder({
            status: 'approved',
            status_label: 'Approvato',
            allowed_actions: ['pickup', 'mark_no_show', 'cancel'],
          }),
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'technician' });

    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
    await user.click(screen.getByRole('button', { name: 'Segna come ritirato' }));

    const dialog = await screen.findByRole('dialog');
    const submit = within(dialog).getByRole('button', { name: 'Segna come ritirato' });
    expect(submit).toBeDisabled();
    expect(within(dialog).getByText(/Seleziona esattamente 1 unità/)).toBeVisible();

    const unitBoxes = await within(dialog).findAllByRole('checkbox');
    await user.click(unitBoxes[0]!);
    await waitFor(() => expect(submit).toBeEnabled());

    /* selecting a second unit for a quantity-1 item invalidates it again */
    await user.click(unitBoxes[1]!);
    await waitFor(() => expect(submit).toBeDisabled());

    await user.click(unitBoxes[1]!);
    await waitFor(() => expect(submit).toBeEnabled());
    await user.click(submit);

    await waitFor(() => {
      const post = find('POST', '/api/v1/orders/89/pickup');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({
        assignments: [{ order_item_id: 771, product_unit_ids: [512] }],
      });
    });
  });

  it('sends the return inspection and the damage log in one POST /return body', async () => {
    const user = userEvent.setup();
    server.use(
      http.get('/api/v1/orders/:id', () =>
        HttpResponse.json(
          f.makeStaffOrder({
            status: 'picked_up',
            status_label: 'Ritirato',
            allowed_actions: ['return'],
            items: [
              {
                id: 771,
                product_id: 128,
                quantity: 1,
                notes: null,
                returned_quantity: 0,
                product: f.vrHeadsetSummary,
                product_name_snapshot: 'Visore VR Meta Quest 3 128GB',
                product_brand_snapshot: 'Meta',
                assigned_units: [
                  {
                    id: 21,
                    product_unit_id: 512,
                    unit_label: '01',
                    assigned_at: '2026-08-01T09:35:00Z',
                    returned_at: null,
                    condition_out: 'ok',
                    condition_in: null,
                    note: null,
                  },
                ],
              },
            ],
          }),
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'technician' });

    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
    await user.click(screen.getByRole('button', { name: 'Segna come restituito' }));

    const dialog = await screen.findByRole('dialog');
    await user.selectOptions(within(dialog).getByLabelText('Condizione al rientro'), 'damaged');
    await user.type(
      within(dialog).getByLabelText(/Aggiungi una voce di registro/),
      'Cinturino lento',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Segna come restituito' }));

    await waitFor(() => {
      const post = find('POST', '/api/v1/orders/89/return');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({
        returns: [
          {
            order_item_id: 771,
            returned_quantity: 1,
            units: [{ product_unit_id: 512, condition_in: 'damaged' }],
          },
        ],
        logs: [{ product_id: 128, title: 'Cinturino lento' }],
      });
    });
  });

  it('links to the printable form once the request is approved', async () => {
    server.use(
      http.get('/api/v1/orders/:id', () =>
        HttpResponse.json(
          f.makeStaffOrder({
            status: 'approved',
            status_label: 'Approvato',
            allowed_actions: ['pickup', 'mark_no_show', 'cancel'],
          }),
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'technician' });

    const link = await screen.findByRole('link', { name: 'Stampa il modulo di ritiro' });
    expect(link).toHaveAttribute('href', '/api/v1/orders/89/pdf?token=access-token-technician');
    expect(link).toHaveAttribute('target', '_blank');
  });

  it('does not link to the printable form while the request is pending', async () => {
    renderWithProviders(<App />, { route: '/gestione/ordini/89', role: 'technician' });

    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0089', level: 1 });
    expect(screen.queryByRole('link', { name: 'Stampa il modulo di ritiro' })).toBeNull();
  });
});

describe('ProductFormPage', () => {
  it('posts the full payload including initial_units in create mode', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/prodotti/nuovo', role: 'technician' });

    await screen.findByRole('heading', { name: 'Nuova attrezzatura', level: 1 });
    await user.type(screen.getByLabelText('Nome attrezzatura'), 'Nuovo microfono');
    await user.selectOptions(screen.getByLabelText('Categoria'), '1');
    const unitsInput = screen.getByLabelText('Unità iniziali');
    await user.clear(unitsInput);
    await user.type(unitsInput, '5');

    await user.click(screen.getByRole('button', { name: 'Salva' }));

    await waitFor(() => {
      const post = find('POST', '/api/v1/products');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({
        name: 'Nuovo microfono',
        category_id: 1,
        initial_units: 5,
      });
    });
  });

  it('pre-fills the form and PUTs in edit mode', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/prodotti/128', role: 'technician' });

    await waitFor(() => {
      expect(screen.getByLabelText('Nome attrezzatura')).toHaveValue(
        'Visore VR Meta Quest 3 128GB',
      );
    });
    expect(screen.queryByLabelText('Unità iniziali')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Salva' }));
    await waitFor(() => {
      expect(find('PUT', '/api/v1/products/128')).toBeDefined();
    });
  });
});

describe('SettingsPage', () => {
  it('renders a control per Setting.type, tracks dirty state and PUTs only the changed keys', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/impostazioni', role: 'admin' });

    await screen.findByRole('heading', { name: 'Impostazioni', level: 1 });
    await user.click(screen.getByRole('tab', { name: 'Prenotazioni e limiti' }));

    const maxDays = await screen.findByLabelText('Durata massima del prestito (giorni)');
    expect(maxDays).toHaveValue(7);
    expect(screen.getByRole('switch', { name: 'La motivazione è obbligatoria' })).toBeChecked();

    await user.clear(maxDays);
    await user.type(maxDays, '10');

    const save = await screen.findByRole('button', { name: /modifiche non salvate/ });
    await user.click(save);

    await waitFor(() => {
      const put = find('PUT', '/api/v1/settings');
      expect(put).toBeDefined();
      expect(put?.body).toEqual({ settings: { 'booking.max_loan_days': 10 } });
    });
  });

  it('treats null as "infinito" for nullable numeric settings', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/impostazioni', role: 'admin' });

    await screen.findByRole('heading', { name: 'Impostazioni', level: 1 });
    await user.click(screen.getByRole('tab', { name: 'Prenotazioni e limiti' }));

    const monthly = await screen.findByLabelText('Numero massimo di prestiti al mese');
    expect(monthly).toHaveValue(4);

    const infinite = screen.getAllByRole('switch', { name: 'Illimitato' })[0]!;
    expect(infinite).not.toBeChecked();
    await user.click(infinite);

    await waitFor(() => expect(monthly).toBeDisabled());
    await user.click(screen.getByRole('button', { name: /modifiche non salvate/ }));

    await waitFor(() => {
      const put = find('PUT', '/api/v1/settings');
      expect(put?.body).toEqual({ settings: { 'booking.max_orders_per_month': null } });
    });
  });

  it('is read-only for a technician and editable for an admin', async () => {
    const user = userEvent.setup();
    const view = renderWithProviders(<App />, {
      route: '/gestione/impostazioni',
      role: 'technician',
    });

    await screen.findByRole('heading', { name: 'Impostazioni', level: 1 });
    expect(screen.getByText('Puoi leggere le impostazioni, non cambiarle.')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Salva' })).toBeNull();
    expect(await screen.findByLabelText('Nome del laboratorio')).toBeDisabled();

    view.unmount();

    renderWithProviders(<App />, { route: '/gestione/impostazioni', role: 'admin' });
    await screen.findByRole('heading', { name: 'Impostazioni', level: 1 });
    expect(screen.queryByText('Puoi leggere le impostazioni, non cambiarle.')).toBeNull();
    const labName = await screen.findByLabelText('Nome del laboratorio');
    expect(labName).toBeEnabled();
    await user.type(labName, '!');
    expect(await screen.findByRole('button', { name: /modifiche non salvate/ })).toBeVisible();
  });

  it('renders the weekday hours editor for the hours.weekly json setting', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/gestione/impostazioni', role: 'admin' });

    await screen.findByRole('heading', { name: 'Impostazioni', level: 1 });
    await user.click(screen.getByRole('tab', { name: 'Orari e chiusure' }));

    expect((await screen.findAllByText('Lunedì')).length).toBeGreaterThan(0);
    expect(screen.getAllByRole('switch', { name: 'Apertura' }).length).toBeGreaterThan(0);
    expect(screen.getAllByRole('switch', { name: 'Chiuso' }).length).toBeGreaterThan(0);
  });
});

describe('StatsPage', () => {
  it('renders the full dashboard for a technician', async () => {
    renderWithProviders(<App />, { route: '/gestione/statistiche', role: 'technician' });

    expect(await screen.findByRole('heading', { name: 'Statistiche', level: 1 })).toBeVisible();
    expect(await screen.findByText('Tasso di approvazione')).toBeVisible();
    expect(await screen.findByText('Attrezzature più richieste')).toBeVisible();

    await waitFor(() => {
      expect(find('GET', '/api/v1/stats/loans-over-time')).toBeDefined();
    });
  });

  it('renders the limited variant for an assistant without requesting the forbidden endpoints', async () => {
    renderWithProviders(<App />, { route: '/gestione/statistiche', role: 'assistant' });

    expect(await screen.findByRole('heading', { name: 'Statistiche', level: 1 })).toBeVisible();
    expect(
      await screen.findByText('Come borsista vedi la sezione operativa, i ritardi e la tua attività.'),
    ).toBeVisible();
    expect((await screen.findAllByText('Riconsegne in ritardo')).length).toBeGreaterThan(0);

    expect(screen.queryByText('Tasso di approvazione')).toBeNull();
    expect(screen.queryByText('Attrezzature più richieste')).toBeNull();
    expect(find('GET', '/api/v1/stats/loans-over-time')).toBeUndefined();
    expect(find('GET', '/api/v1/stats/top-products')).toBeUndefined();
    expect(find('GET', '/api/v1/stats/by-category')).toBeUndefined();
  });
});

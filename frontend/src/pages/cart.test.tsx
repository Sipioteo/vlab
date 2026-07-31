import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { mockState } from '@/test/handlers';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import * as f from '@/test/fixtures';

interface RequestRecord {
  method: string;
  path: string;
  body: unknown;
}

const requests: RequestRecord[] = [];
let stop: (() => void) | null = null;

beforeEach(() => {
  requests.length = 0;
  const handler = async ({ request }: { request: Request }) => {
    const url = new URL(request.url);
    let body: unknown = null;
    if (request.method !== 'GET' && request.method !== 'DELETE') {
      try {
        body = await request.clone().json();
      } catch {
        body = null;
      }
    }
    requests.push({ method: request.method, path: url.pathname, body });
  };
  server.events.on('request:start', handler);
  stop = () => server.events.removeListener('request:start', handler);
  mockState.cart = structuredClone(f.cartWithItems);
});

afterEach(() => {
  stop?.();
});

function lastRequest(method: string, path: string): RequestRecord | undefined {
  return [...requests].reverse().find((r) => r.method === method && r.path === path);
}


async function fillCheckout(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByRole('heading', { name: 'Invia la richiesta di prestito', level: 1 });
  await user.type(screen.getByLabelText('Materia'), 'Laboratorio di Ripresa');
  await user.type(
    screen.getByLabelText(/Motivazione/),
    'Riprese del cortometraggio finale del corso.',
  );
}

async function clickSubmit(user: ReturnType<typeof userEvent.setup>, label = 'Invia la richiesta') {
  const submit = await screen.findByRole('button', { name: label });
  await waitFor(() => expect(submit).toBeEnabled());
  await user.click(submit);
  return submit;
}

describe('CartPage', () => {
  it('lists the cart items with their quantities', async () => {
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });

    expect(await screen.findByRole('heading', { name: 'Carrello', level: 1 })).toBeVisible();
    expect(await screen.findByText('Visore VR Meta Quest 3 128GB')).toBeVisible();
    expect(screen.getByText('Antivento Microfono Rode WS7 Large Deluxe')).toBeVisible();
    expect(
      screen.getByLabelText('Quantità — Visore VR Meta Quest 3 128GB'),
    ).toHaveValue(1);
  });

  it('changes a quantity through PATCH /cart/items/{id}', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    await user.click(screen.getByRole('button', { name: 'Quantità — Visore VR Meta Quest 3 128GB +' }));

    await waitFor(() => {
      const patch = lastRequest('PATCH', '/api/v1/cart/items/812');
      expect(patch).toBeDefined();
      expect(patch?.body).toMatchObject({ quantity: 2 });
    });
  });

  it('removes an item through DELETE and drops it from the list', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    await user.click(
      screen.getByRole('button', { name: 'Rimuovi — Visore VR Meta Quest 3 128GB' }),
    );

    await waitFor(() => {
      expect(lastRequest('DELETE', '/api/v1/cart/items/812')).toBeDefined();
    });
    await waitFor(() => {
      expect(screen.queryByText('Visore VR Meta Quest 3 128GB')).toBeNull();
    });
  });

  it('writes a range picked in the calendar through PUT /cart/dates', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    await user.click(screen.getByRole('button', { name: /Periodo di prestito/ }));
    await user.click(await screen.findByRole('gridcell', { name: /lunedì 3 agosto 2026/ }));
    await user.click(screen.getByRole('gridcell', { name: /giovedì 6 agosto 2026/ }));

    await waitFor(() => {
      const put = lastRequest('PUT', '/api/v1/cart/dates');
      expect(put).toBeDefined();
      expect(put?.body).toMatchObject({ pickup_date: '2026-08-03', return_date: '2026-08-06' });
    });
  });

  it('renders the time slots returned by the availability check', async () => {
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    expect(await screen.findByRole('button', { name: '09:00–09:30' })).toBeVisible();
    expect(screen.getByRole('button', { name: '15:30–16:00' })).toBeVisible();
    expect(screen.queryByRole('button', { name: '11:00–11:30' })).toBeNull();
  });

  it('shows the empty state when the cart has no items', async () => {
    mockState.cart = f.emptyCart;
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    expect(await screen.findByText('Il carrello è vuoto')).toBeVisible();
  });
});

describe('CartPage — substitute suggestions', () => {
  it('renders the suggestion card for an unavailable item with the top-priority substitute first', async () => {
    mockState.cart = structuredClone(f.cartWithUnavailableItem);
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    const card = await screen.findByTestId('substitute-128');
    expect(within(card).getByText('Non disponibile in queste date.')).toBeVisible();
    // Top substitute = priority 1 (Quest 3 512gb), offered as the one-click swap.
    expect(
      within(card).getByRole('button', {
        name: 'Sostituisci con Visore Oculus Meta Quest 3 512gb',
      }),
    ).toBeVisible();
    expect(within(card).getByText('3 disponibili in queste date')).toBeVisible();
    // The remaining suggestions live behind the "Altre alternative" affordance
    // (collapsed by default, so present but not visible until expanded).
    const toggle = within(card).getByText('Altre alternative');
    expect(toggle).toBeVisible();
    expect(
      within(card).getByRole('button', {
        name: 'Sostituisci con Visore Oculus Quest All in one 64GB',
      }),
    ).toBeInTheDocument();
  });

  it('swaps through POST /cart/items/{id}/swap and updates the cart', async () => {
    const user = userEvent.setup();
    mockState.cart = structuredClone(f.cartWithUnavailableItem);
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    await screen.findByTestId('substitute-128');

    await user.click(
      screen.getByRole('button', { name: 'Sostituisci con Visore Oculus Meta Quest 3 512gb' }),
    );

    await waitFor(() => {
      const post = lastRequest('POST', '/api/v1/cart/items/812/swap');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({ product_id: 129 });
    });
    // The list now shows the substitute; the old product and the card are gone.
    expect(await screen.findByText('Visore Oculus Meta Quest 3 512gb')).toBeVisible();
    await waitFor(() => {
      expect(screen.queryByText('Visore VR Meta Quest 3 128GB')).toBeNull();
      expect(screen.queryByTestId('substitute-128')).toBeNull();
    });
    expect(
      await screen.findByText('Fatto: Visore Oculus Meta Quest 3 512gb è nel carrello.'),
    ).toBeVisible();
  });

  it('shows no suggestion card when every item is available', async () => {
    mockState.cart = structuredClone(f.cartWithItems);
    renderWithProviders(<App />, { route: '/carrello', role: 'student' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    expect(screen.queryByTestId('substitute-128')).toBeNull();
    expect(screen.queryByText(/Sostituisci con/)).toBeNull();
  });
});

describe('CheckoutPage', () => {
  it('renders soft violations as a warning and hard ones as an error', async () => {
    mockState.cart = { ...structuredClone(f.cartWithItems), check: f.availabilityCheckSoft };
    mockState.check = f.availabilityCheckSoft;
    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    const warnings = await screen.findByTestId('limit-warnings');
    const soft = within(warnings).getByText(/La durata richiesta \(12 giorni\)/);
    expect(soft).toBeVisible();
    expect(soft.getAttribute('data-severity')).toBe('soft');
  });

  it('disables the submit button while can_submit is false', async () => {
    mockState.check = f.availabilityCheckHard;
    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    await screen.findByRole('heading', { name: 'Invia la richiesta di prestito', level: 1 });
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Invia la richiesta' })).toBeDisabled();
    });
    const warnings = await screen.findByTestId('limit-warnings');
    expect(within(warnings).getByText(/non è disponibile nelle date scelte/)).toHaveAttribute(
      'data-severity',
      'hard',
    );
  });

  it('labels the button "Invia comunque", opens the confirm dialog and posts acknowledge_exceeds_limits', async () => {
    const user = userEvent.setup();
    mockState.check = f.availabilityCheckSoft;
    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    await fillCheckout(user);
    await clickSubmit(user, 'Invia comunque');

    const dialog = await screen.findByRole('dialog');
    expect(
      within(dialog).getByRole('heading', { name: 'La richiesta è fuori limite' }),
    ).toBeVisible();

    await user.click(within(dialog).getByRole('button', { name: 'Invia comunque' }));

    await waitFor(() => {
      const post = lastRequest('POST', '/api/v1/orders');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({ acknowledge_exceeds_limits: true });
    });
  });

  it('navigates to the order detail and shows the confirmation state after a successful checkout', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    await fillCheckout(user);
    await clickSubmit(user);

    expect(
      await screen.findByRole('heading', { name: 'Richiesta VL-2026-0088', level: 1 }),
    ).toBeVisible();
    expect(screen.getByText('Richiesta confermata')).toBeVisible();
  });

  it('renders the per-product message on 409 insufficient_availability and offers to change the dates', async () => {
    const user = userEvent.setup();
    server.use(
      http.post('/api/v1/orders', () =>
        HttpResponse.json(
          {
            error: {
              code: 'insufficient_availability',
              message: 'Disponibilità non sufficiente.',
              details: { products: [{ product_id: 128, requested: 2, available: 1 }] },
              trace_id: 'aa11bb22',
            },
          },
          { status: 409 },
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    await fillCheckout(user);
    await clickSubmit(user);

    expect(await screen.findByText('Disponibilità non sufficiente')).toBeVisible();
    expect(screen.getByText(/Visore VR Meta Quest 3 128GB: 1\/2/)).toBeVisible();
    expect(screen.getByRole('link', { name: 'Modifica le date' })).toBeVisible();
  });

  it('maps a 422 validation_failed payload onto the matching form fields', async () => {
    const user = userEvent.setup();
    server.use(
      http.post('/api/v1/orders', () =>
        HttpResponse.json(
          {
            error: {
              code: 'validation_failed',
              message: 'I dati inviati non sono validi.',
              details: { subject: ['La materia non è valida.'] },
              trace_id: 'cc33dd44',
            },
          },
          { status: 422 },
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    await fillCheckout(user);
    await clickSubmit(user);

    expect(await screen.findByText('La materia non è valida.')).toBeVisible();
  });

  it('blocks submission until every required regulation is accepted', async () => {
    const user = userEvent.setup();
    mockState.check = f.availabilityCheckWithRegulation;
    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    await screen.findByRole('heading', { name: 'Invia la richiesta di prestito', level: 1 });
    const block = await screen.findByTestId('regulation-4');
    expect(within(block).getByText('Avvertenze uso visori VR')).toBeVisible();

    await user.type(screen.getByLabelText('Materia'), 'Laboratorio di Ripresa');
    await user.type(
      screen.getByLabelText(/Motivazione/),
      'Riprese del cortometraggio finale del corso.',
    );
    await clickSubmit(user);

    expect(
      await screen.findByText('Accetta i regolamenti richiesti: senza, non si va avanti.'),
    ).toBeVisible();
    expect(lastRequest('POST', '/api/v1/orders')).toBeUndefined();

    await user.click(within(block).getByRole('checkbox'));
    await clickSubmit(user);

    await waitFor(() => {
      const post = lastRequest('POST', '/api/v1/orders');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({ accepted_regulation_ids: [4] });
    });
  });

  it('keeps the acceptance checkbox disabled until the regulation body is scrolled to the end', async () => {
    const user = userEvent.setup();
    mockState.check = f.availabilityCheckWithRegulation;

    /* jsdom has no layout: simulate an overflowing scroll container. */
    const scrollHeight = vi
      .spyOn(HTMLElement.prototype, 'scrollHeight', 'get')
      .mockReturnValue(800);
    const clientHeight = vi
      .spyOn(HTMLElement.prototype, 'clientHeight', 'get')
      .mockReturnValue(200);

    renderWithProviders(<App />, { route: '/carrello/checkout', role: 'student' });

    const block = await screen.findByTestId('regulation-4');
    const checkbox = within(block).getByRole('checkbox');
    expect(checkbox).toBeDisabled();
    expect(
      within(block).getByText('Scorri il testo fino in fondo: poi potrai accettarlo.'),
    ).toBeVisible();

    const scroller = block.querySelector('.vl-reg-block__scroll') as HTMLElement;
    Object.defineProperty(scroller, 'scrollTop', { value: 700, writable: true });
    scroller.dispatchEvent(new Event('scroll', { bubbles: true }));

    await waitFor(() => expect(checkbox).toBeEnabled());
    await user.click(checkbox);
    expect(checkbox).toBeChecked();

    scrollHeight.mockRestore();
    clientHeight.mockRestore();
  });
});

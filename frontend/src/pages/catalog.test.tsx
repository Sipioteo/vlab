import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import { mockState } from '@/test/handlers';
import * as f from '@/test/fixtures';

const requests: string[] = [];

function trackRequests() {
  const handler = ({ request }: { request: Request }) => {
    requests.push(request.method + ' ' + new URL(request.url).pathname + new URL(request.url).search);
  };
  server.events.on('request:start', handler);
  return () => server.events.removeListener('request:start', handler);
}

let stopTracking: (() => void) | null = null;

beforeEach(() => {
  requests.length = 0;
  stopTracking = trackRequests();
});
afterEach(() => {
  stopTracking?.();
});

describe('CatalogPage', () => {
  it('renders the grid from the paginated response and shows the total count', async () => {
    renderWithProviders(<App />, { route: '/catalogo' });

    expect(await screen.findByRole('heading', { name: 'Catalogo attrezzature', level: 1 })).toBeVisible();
    expect(await screen.findByText('Visore VR Meta Quest 3 128GB')).toBeVisible();
    expect(await screen.findByText('4 attrezzature trovate')).toBeVisible();
  });

  it('debounces the search box into a single request carrying q', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/catalogo' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    requests.length = 0;
    await user.type(screen.getByRole('searchbox'), 'visore');

    await waitFor(() => {
      expect(requests.filter((r) => r.includes('/products?') && r.includes('q=visore')).length).toBe(1);
    });
    const productCalls = requests.filter((r) => r.includes('/api/v1/products?'));
    expect(productCalls.length).toBe(1);
  });

  it('filters by category and reflects it in the URL', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/catalogo' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    await user.click(screen.getByRole('link', { name: /Tecnologie Interattive/ }));

    expect(await screen.findByRole('heading', { name: 'Tecnologie Interattive', level: 1 })).toBeVisible();
    await waitFor(() => {
      expect(
        requests.some((r) => r.includes('category_slug=tecnologie-interattive')),
      ).toBe(true);
    });
  });

  it('switches to /availability/products when a date range is set and shows availability badges', async () => {
    renderWithProviders(<App />, {
      route: '/catalogo?start_date=2026-08-03&end_date=2026-08-06',
    });

    await screen.findByRole('heading', { name: 'Catalogo attrezzature', level: 1 });

    await waitFor(() => {
      expect(
        requests.some(
          (r) =>
            r.includes('/availability/products') &&
            r.includes('start_date=2026-08-03') &&
            r.includes('end_date=2026-08-06'),
        ),
      ).toBe(true);
    });
    expect(requests.some((r) => r.includes('/api/v1/products?'))).toBe(false);
    expect((await screen.findAllByText('3 disponibili')).length).toBeGreaterThan(0);
  });

  it('renders "Non disponibile" for products with zero availability', async () => {
    renderWithProviders(<App />, {
      route: '/catalogo?start_date=2026-08-03&end_date=2026-08-06&include_unavailable=true',
    });
    expect(await screen.findByText('Non disponibile in queste date')).toBeVisible();
  });

  it('renders the empty state with a reset action', async () => {
    server.use(
      http.get('/api/v1/products', () =>
        HttpResponse.json({
          data: [],
          meta: { page: 1, per_page: 24, total: 0, total_pages: 0 },
          filters: { categories: [], brands: [] },
        }),
      ),
    );
    renderWithProviders(<App />, { route: '/catalogo' });
    expect(await screen.findByText('Nessuna attrezzatura trovata')).toBeVisible();
  });

  it('renders an error state with a retry affordance', async () => {
    server.use(
      http.get('/api/v1/products', () =>
        HttpResponse.json(
          { error: { code: 'server_error', message: 'Errore del server.', details: null, trace_id: 'ff00ff00' } },
          { status: 500 },
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/catalogo' });
    expect(await screen.findByText('Impossibile caricare il catalogo')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Riprova' })).toBeVisible();
  });

  it('labels the filter button with the number of active filters', async () => {
    const { unmount } = renderWithProviders(<App />, { route: '/catalogo' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');
    expect(screen.getByRole('button', { name: 'Filtri' })).toBeVisible();
    unmount();

    renderWithProviders(<App />, {
      route: '/catalogo/tecnologie-interattive?brand=Meta&start_date=2026-08-03&end_date=2026-08-06',
    });
    await screen.findByRole('heading', { level: 1 });
    expect(await screen.findByRole('button', { name: 'Filtri · 3' })).toBeVisible();
  });

  it('opens the filter sheet and closes it from the "Mostra N risultati" button', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/catalogo' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    const toggle = screen.getByRole('button', { name: 'Filtri' });
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await user.click(toggle);

    const sheet = await screen.findByRole('dialog', { name: 'Filtri' });
    expect(toggle).toHaveAttribute('aria-expanded', 'true');

    await user.click(within(sheet).getByRole('button', { name: 'Mostra 4 risultati' }));
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Filtri' })).toBeNull());
  });

  it('picks a range in the sidebar calendar and switches to the availability endpoint', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/catalogo' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    await user.click(screen.getByRole('button', { name: /Periodo di prestito/ }));
    await user.click(await screen.findByRole('gridcell', { name: /lunedì 3 agosto 2026/ }));
    await user.click(screen.getByRole('gridcell', { name: /giovedì 6 agosto 2026/ }));

    await waitFor(() => {
      expect(
        requests.some(
          (r) =>
            r.includes('/availability/products') &&
            r.includes('start_date=2026-08-03') &&
            r.includes('end_date=2026-08-06'),
        ),
      ).toBe(true);
    });
    expect(screen.getByRole('button', { name: /3 ago → 6 ago/ })).toBeVisible();
  });

  it('changes the page parameter through the pagination control', async () => {
    const user = userEvent.setup();
    server.use(
      http.get('/api/v1/products', ({ request }) => {
        const page = Number(new URL(request.url).searchParams.get('page') ?? '1');
        return HttpResponse.json({
          data: [f.vrHeadsetSummary],
          meta: { page, per_page: 24, total: 50, total_pages: 3 },
          filters: { categories: [], brands: [] },
        });
      }),
    );
    renderWithProviders(<App />, { route: '/catalogo' });
    await screen.findByText('Visore VR Meta Quest 3 128GB');

    expect(screen.getByRole('button', { name: /Pagina precedente/ })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: /Pagina successiva/ }));

    await waitFor(() => {
      expect(requests.some((r) => r.includes('page=2'))).toBe(true);
    });
  });
});

describe('ProductDetailPage', () => {
  it('renders name, brand, specs and the gallery from the fixture', async () => {
    renderWithProviders(<App />, { route: '/prodotto/visore-vr-meta-quest-3' });

    expect(
      await screen.findByRole('heading', { name: 'Visore VR Meta Quest 3 128GB', level: 1 }),
    ).toBeVisible();
    expect(screen.getAllByText('Meta · Quest 3').length).toBeGreaterThan(0);
    expect(await screen.findByText('Risoluzione')).toBeVisible();
    expect(screen.getByText('2064x2208 per occhio')).toBeVisible();
  });

  it('shows the VR regulation callout for a product with required regulations', async () => {
    renderWithProviders(<App />, { route: '/prodotto/visore-vr-meta-quest-3' });
    expect(await screen.findByText('Regolamento da accettare')).toBeVisible();
    expect(screen.getByRole('link', { name: 'Avvertenze uso visori VR' })).toBeVisible();
  });

  it('lists recommended accessories linking to their product page', async () => {
    renderWithProviders(<App />, { route: '/prodotto/visore-vr-meta-quest-3' });
    const heading = await screen.findByRole('heading', { name: 'Accessori consigliati', level: 2 });
    expect(heading).toBeVisible();
    const link = screen.getByRole('link', { name: 'Antivento Microfono Rode WS7 Large Deluxe' });
    expect(link).toHaveAttribute('href', '/prodotto/antivento-microfono-rode-ws7');
  });

  it('renders public product logs as a timeline', async () => {
    renderWithProviders(<App />, { route: '/prodotto/visore-vr-meta-quest-3' });
    expect(await screen.findByText('Molla del cinturino persa')).toBeVisible();
  });

  it('adds the product to the cart and updates the header badge', async () => {
    const user = userEvent.setup();
    mockState.cart = f.emptyCart;
    renderWithProviders(<App />, { route: '/prodotto/visore-vr-meta-quest-3', role: 'student' });

    const buttons = await screen.findAllByRole('button', { name: /Aggiungi al carrello/ });
    await user.click(buttons[0]!);

    await waitFor(() => {
      expect(requests.some((r) => r.startsWith('POST /api/v1/cart/items'))).toBe(true);
    });
    const badge = await screen.findByTestId('cart-badge');
    await waitFor(() => expect(within(badge).getByText('1')).toBeVisible());
  });

  it('disables add-to-cart for a product in maintenance and explains why', async () => {
    renderWithProviders(<App />, {
      route: '/prodotto/asta-giraffa-proel-rsm180',
      role: 'student',
    });

    const button = await screen.findByRole('button', { name: /Non prenotabile: In manutenzione/ });
    expect(button).toBeDisabled();
  });
});

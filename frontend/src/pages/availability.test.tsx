import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { mockState } from '@/test/handlers';
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

async function seedSelection(user: ReturnType<typeof userEvent.setup>) {
  const search = await screen.findByRole('searchbox');
  await user.type(search, 'visore');
  const option = await screen.findByRole('button', { name: /Visore VR Meta Quest 3 128GB/ });
  await user.click(option);
}

describe('AvailabilityFinderPage (products → dates)', () => {
  it('posts the selection and duration to /availability/dates', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/disponibilita' });

    await screen.findByRole('heading', { name: 'Verifica disponibilità', level: 1 });
    await seedSelection(user);

    await user.click(screen.getByRole('button', { name: 'Trova le date disponibili' }));

    await waitFor(() => {
      const post = requests.find((r) => r.path === '/api/v1/availability/dates');
      expect(post).toBeDefined();
      expect(post?.body).toMatchObject({
        items: [{ product_id: 128, quantity: 1 }],
        duration_days: 3,
      });
    });
  });

  it('renders one heat-map cell per day and marks closed days distinctly', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/disponibilita' });
    await screen.findByRole('heading', { name: 'Verifica disponibilità', level: 1 });
    await seedSelection(user);
    await user.click(screen.getByRole('button', { name: 'Trova le date disponibili' }));

    const heatmap = await screen.findByTestId('availability-heatmap');
    const cells = heatmap.querySelectorAll('[data-state]');
    expect(cells.length).toBe(f.availabilityDates.days.length);
    expect(heatmap.querySelectorAll('[data-state="closed"]').length).toBeGreaterThan(0);
    expect(heatmap.querySelectorAll('[data-state="ok"]').length).toBeGreaterThan(0);
  });

  it('lists available windows and writes the chosen one to the cart before navigating', async () => {
    const user = userEvent.setup();
    mockState.cart = f.emptyCart;
    renderWithProviders(<App />, { route: '/disponibilita', role: 'student' });
    await screen.findByRole('heading', { name: 'Verifica disponibilità', level: 1 });
    await seedSelection(user);
    await user.click(screen.getByRole('button', { name: 'Trova le date disponibili' }));

    const chooseButtons = await screen.findAllByRole('button', { name: 'Scegli queste date' });
    expect(chooseButtons.length).toBe(1);
    await user.click(chooseButtons[0]!);

    await waitFor(() => {
      const put = requests.find((r) => r.path === '/api/v1/cart/dates');
      expect(put).toBeDefined();
      expect(put?.body).toMatchObject({ pickup_date: '2026-08-03', return_date: '2026-08-05' });
    });
    expect(await screen.findByRole('heading', { name: 'Carrello', level: 1 })).toBeVisible();
  });

  it('names the products blocking an unavailable window', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/disponibilita' });
    await screen.findByRole('heading', { name: 'Verifica disponibilità', level: 1 });
    await seedSelection(user);
    await user.click(screen.getByRole('button', { name: 'Trova le date disponibili' }));

    expect(
      await screen.findByText(/Bloccata da: Visore VR Meta Quest 3 128GB/),
    ).toBeVisible();
  });

  it('renders the "nessuna finestra" empty state when first_available_window is null', async () => {
    const user = userEvent.setup();
    server.use(
      http.post('/api/v1/availability/dates', () =>
        HttpResponse.json({
          ...f.availabilityDates,
          windows: [],
          first_available_window: null,
        }),
      ),
    );
    renderWithProviders(<App />, { route: '/disponibilita' });
    await screen.findByRole('heading', { name: 'Verifica disponibilità', level: 1 });
    await seedSelection(user);
    await user.click(screen.getByRole('button', { name: 'Trova le date disponibili' }));

    expect(
      await screen.findByText('Niente di libero in questo periodo'),
    ).toBeVisible();
  });

  it('seeds the selection from the cart for a logged-in student', async () => {
    mockState.cart = structuredClone(f.cartWithItems);
    renderWithProviders(<App />, { route: '/disponibilita', role: 'student' });

    const selection = await screen.findByRole('heading', {
      name: 'Attrezzature selezionate',
      level: 2,
    });
    const card = selection.closest('section') as HTMLElement;
    await waitFor(() => {
      expect(within(card).getByText('Visore VR Meta Quest 3 128GB')).toBeVisible();
    });
    expect(within(card).getByText('Antivento Microfono Rode WS7 Large Deluxe')).toBeVisible();
  });
});

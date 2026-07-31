import { describe, it, expect } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import { OrderActions } from '@/components/domain';
import * as f from '@/test/fixtures';
import type { OrderAction } from '@/types/api';

describe('MyOrdersPage', () => {
  it('lists the orders with Italian status labels from /meta/enums', async () => {
    renderWithProviders(<App />, { route: '/ordini', role: 'student' });

    expect(await screen.findByRole('heading', { name: 'Le mie richieste', level: 1 })).toBeVisible();
    expect(await screen.findByText('VL-2026-0088')).toBeVisible();
    expect(screen.getAllByText('Approvato').length).toBeGreaterThan(0);
    expect(screen.getAllByText('In attesa').length).toBeGreaterThan(0);
  });

  it('filters by status chip', async () => {
    const user = userEvent.setup();
    renderWithProviders(<App />, { route: '/ordini', role: 'student' });
    await screen.findByText('VL-2026-0088');

    await user.click(screen.getByRole('button', { name: /In attesa/ }));

    await waitFor(() => {
      expect(screen.queryByText('VL-2026-0088')).toBeNull();
    });
    expect(screen.getByText('VL-2026-0089')).toBeVisible();
  });

  it('shows the empty state when there are no orders', async () => {
    server.use(
      http.get('/api/v1/orders', () =>
        HttpResponse.json({ data: [], meta: { page: 1, per_page: 10, total: 0, total_pages: 0 } }),
      ),
    );
    renderWithProviders(<App />, { route: '/ordini', role: 'student' });
    expect(await screen.findByText('Nessuna richiesta')).toBeVisible();
  });
});

describe('OrderDetailPage', () => {
  it('renders the event timeline in chronological order', async () => {
    renderWithProviders(<App />, { route: '/ordini/88', role: 'student' });

    const timeline = await screen.findByTestId('order-timeline');
    const entries = within(timeline).getAllByRole('listitem');
    expect(entries[0]).toHaveTextContent('Inviata');
    expect(entries[1]).toHaveTextContent('Approvato');
  });

  it('shows a clear "confermato" state for an approved order', async () => {
    renderWithProviders(<App />, { route: '/ordini/88', role: 'student' });
    expect(await screen.findByText('Richiesta confermata')).toBeVisible();
    expect(screen.getByText(/Ci vediamo in laboratorio il 01\/08\/2026 alle 09:30/)).toBeVisible();
  });

  it('opens a confirm dialog and posts to /cancel', async () => {
    const user = userEvent.setup();
    const calls: string[] = [];
    server.events.on('request:start', ({ request }) => {
      calls.push(`${request.method} ${new URL(request.url).pathname}`);
    });

    renderWithProviders(<App />, { route: '/ordini/88', role: 'student' });
    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0088', level: 1 });

    await user.click(screen.getByRole('button', { name: 'Annulla richiesta' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Annulla richiesta' }));

    await waitFor(() => {
      expect(calls).toContain('POST /api/v1/orders/88/cancel');
    });
  });

  it('displays the rejection reason prominently', async () => {
    server.use(
      http.get('/api/v1/orders/:id', () =>
        HttpResponse.json(
          f.makeOrder({
            status: 'rejected',
            status_label: 'Respinto',
            rejection_reason: 'Attrezzatura già impegnata in quel periodo.',
            allowed_actions: [],
          }),
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/ordini/88', role: 'student' });

    expect(await screen.findByText('Motivo del rifiuto')).toBeVisible();
    expect(screen.getByText('Attrezzatura già impegnata in quel periodo.')).toBeVisible();
  });

  it('renders without crashing when the student payload omits staff_notes', async () => {
    renderWithProviders(<App />, { route: '/ordini/88', role: 'student' });
    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0088', level: 1 });
    expect(screen.queryByText('Note interne')).toBeNull();
  });

  it('offers the printable form for an approved order, opening it in a new tab', async () => {
    renderWithProviders(<App />, { route: '/ordini/88', role: 'student' });

    const link = await screen.findByRole('link', { name: 'Stampa il modulo di ritiro' });
    expect(link).toHaveAttribute('href', '/api/v1/orders/88/pdf?token=access-token-student');
    expect(link).toHaveAttribute('target', '_blank');
  });

  it('hides the printable form while the request is still pending', async () => {
    server.use(
      http.get('/api/v1/orders/:id', () =>
        HttpResponse.json(
          f.makeOrder({ status: 'pending', status_label: 'In attesa', allowed_actions: ['cancel'] }),
        ),
      ),
    );
    renderWithProviders(<App />, { route: '/ordini/88', role: 'student' });

    await screen.findByRole('heading', { name: 'Richiesta VL-2026-0088', level: 1 });
    expect(screen.queryByRole('link', { name: 'Stampa il modulo di ritiro' })).toBeNull();
  });
});

describe('OrderActions', () => {
  const CASES: { name: string; actions: OrderAction[]; expected: string[] }[] = [
    { name: 'student on a pending order', actions: ['cancel'], expected: ['Annulla richiesta'] },
    {
      name: 'staff on a pending order',
      actions: ['approve', 'reject', 'cancel'],
      expected: ['Approva', 'Rifiuta', 'Annulla richiesta'],
    },
    {
      name: 'staff on an approved order',
      actions: ['pickup', 'mark_no_show', 'cancel'],
      expected: ['Segna come ritirato', 'Segna come non ritirato', 'Annulla richiesta'],
    },
    { name: 'staff on a picked-up order', actions: ['return'], expected: ['Segna come restituito'] },
    { name: 'admin on a terminal order', actions: ['reopen'], expected: ['Riapri'] },
    { name: 'nobody on a closed order', actions: [], expected: [] },
  ];

  it.each(CASES)('renders exactly the buttons in allowed_actions — $name', ({ actions, expected }) => {
    const { unmount } = render(<OrderActions actions={actions} onAction={() => {}} />);

    if (expected.length === 0) {
      expect(screen.queryByTestId('order-actions')).toBeNull();
    } else {
      const container = screen.getByTestId('order-actions');
      const buttons = within(container).getAllByRole('button').map((b) => b.textContent);
      expect(buttons).toEqual(expected);
    }
    unmount();
  });
});

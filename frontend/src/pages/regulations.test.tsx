import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { mockState } from '@/test/handlers';
import { renderWithProviders } from '@/test/utils';
import { App } from '@/App';
import { IcalFeedCard } from '@/components/IcalFeedCard';
import { RegulationsPage } from '@/pages/RegulationsPage';
import { pendingGlobalRegulation, globalRegulation, vrRegulation } from '@/test/fixtures';

/* ------------------------------------------------------------ gate dialog */

describe('blocking regulation gate', () => {
  function renderGated(route = '/catalogo') {
    mockState.pendingRegulations = [pendingGlobalRegulation];
    return renderWithProviders(<App />, { route, role: 'student' });
  }

  it('renders a proper modal dialog with the regulation body', async () => {
    renderGated();

    const dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    // Labelled by its own heading, not by a guessed string.
    const labelId = dialog.getAttribute('aria-labelledby')!;
    expect(document.getElementById(labelId)).toHaveTextContent('Prima di continuare');
    // The markdown body of the pending regulation is rendered, scrollable.
    await within(dialog).findByText(/Le attrezzature vanno restituite/);
    expect(within(dialog).getByRole('region', { name: globalRegulation.title })).toBeVisible();
  });

  it('leaves the app inert behind it', async () => {
    renderGated();
    await screen.findByRole('dialog');

    const shell = document.querySelector('.vl-approot')!;
    expect(shell).toHaveAttribute('inert');
    expect(shell).toHaveAttribute('aria-hidden', 'true');
    expect(shell).toHaveAttribute('data-blocked', 'true');
    // The catalogue really is still mounted underneath — just unreachable.
    // (`getByRole` skips aria-hidden subtrees, which is precisely the point.)
    expect(shell.querySelector('h1')).not.toBeNull();
    expect(screen.queryByRole('heading', { name: 'Catalogo attrezzature' })).toBeNull();
  });

  it('cannot be dismissed with Escape', async () => {
    const user = userEvent.setup();
    renderGated();
    const dialog = await screen.findByRole('dialog');

    await user.keyboard('{Escape}');
    expect(screen.getByRole('dialog')).toBe(dialog);
  });

  it('enables confirm only when every regulation is checked', async () => {
    const user = userEvent.setup();
    mockState.pendingRegulations = [
      pendingGlobalRegulation,
      { ...pendingGlobalRegulation, id: 9, slug: 'codice-etico', title: 'Codice etico' },
    ];
    renderWithProviders(<App />, { route: '/', role: 'student' });

    const dialog = await screen.findByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Conferma e prosegui' });
    expect(confirm).toBeDisabled();

    const boxes = await within(dialog).findAllByRole('checkbox');
    expect(boxes).toHaveLength(2);

    await user.click(boxes[0]!);
    expect(confirm).toBeDisabled();

    await user.click(boxes[1]!);
    await waitFor(() => expect(confirm).toBeEnabled());
  });

  it('accepts every pending regulation and disappears on success', async () => {
    const user = userEvent.setup();
    renderGated('/');

    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('checkbox'));
    await user.click(within(dialog).getByRole('button', { name: 'Conferma e prosegui' }));

    await waitFor(() => expect(mockState.acceptedRegulationIds).toEqual([pendingGlobalRegulation.id]));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    // And the shell is interactive again.
    expect(document.querySelector('.vl-approot')).not.toHaveAttribute('inert');
  });

  it('keeps blocking when the acceptance call fails', async () => {
    const user = userEvent.setup();
    server.use(
      http.post('/api/v1/me/regulations/:id/accept', () =>
        HttpResponse.json(
          {
            error: {
              code: 'conflict',
              message: 'Il regolamento è stato aggiornato, ricarica la pagina.',
              details: null,
              trace_id: 'abcd1234',
            },
          },
          { status: 409 },
        ),
      ),
    );
    renderGated('/');

    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('checkbox'));
    await user.click(within(dialog).getByRole('button', { name: 'Conferma e prosegui' }));

    expect(await screen.findByText(/è stato aggiornato/)).toBeVisible();
    expect(screen.getByRole('dialog')).toBeVisible();
  });
});

/* ---------------------------------------------------- regulations index */

describe('regulations list acceptance state', () => {
  it('shows the acceptance date for accepted documents', async () => {
    server.use(
      http.get('/api/v1/regulations', () =>
        HttpResponse.json({
          data: [
            {
              ...globalRegulation,
              acceptance: { accepted: true, version: 3, accepted_at: '2026-03-04T10:00:00Z' },
            },
          ],
          meta: { page: 1, per_page: 100, total: 1, total_pages: 1 },
        }),
      ),
    );
    renderWithProviders(<RegulationsPage />, { role: 'student' });

    expect(await screen.findByText('Accettato il 04/03/2026')).toBeVisible();
    expect(screen.queryByRole('button', { name: /Accetta ora/ })).toBeNull();
  });

  it('offers a manual accept button for unaccepted ones and updates after accepting', async () => {
    const user = userEvent.setup();
    let accepted = false;
    server.use(
      http.get('/api/v1/regulations', () =>
        HttpResponse.json({
          data: [
            {
              ...globalRegulation,
              acceptance: accepted
                ? { accepted: true, version: 3, accepted_at: '2026-07-31T09:10:00Z' }
                : { accepted: false, version: 3, accepted_at: null },
            },
          ],
          meta: { page: 1, per_page: 100, total: 1, total_pages: 1 },
        }),
      ),
      http.post('/api/v1/me/regulations/:id/accept', ({ params }) => {
        accepted = true;
        mockState.acceptedRegulationIds.push(Number(params['id']));
        return HttpResponse.json({
          accepted: true,
          regulation_id: Number(params['id']),
          version: 3,
          accepted_at: '2026-07-31T09:10:00Z',
          pending_regulations: [],
        });
      }),
    );
    renderWithProviders(<RegulationsPage />, { role: 'student' });

    expect(await screen.findByText('Da accettare')).toBeVisible();
    await user.click(await screen.findByRole('button', { name: /Accetta ora/ }));

    await waitFor(() => expect(mockState.acceptedRegulationIds).toEqual([globalRegulation.id]));
    expect(await screen.findByText(/Accettato il/)).toBeVisible();
  });

  it('does not offer acceptance to anonymous visitors', async () => {
    server.use(
      http.get('/api/v1/regulations', () =>
        HttpResponse.json({
          data: [{ ...globalRegulation, acceptance: null }, vrRegulation],
          meta: { page: 1, per_page: 100, total: 2, total_pages: 1 },
        }),
      ),
    );
    renderWithProviders(<RegulationsPage />, { role: null });

    expect(await screen.findByText(globalRegulation.title)).toBeVisible();
    expect(screen.queryByRole('button', { name: /Accetta ora/ })).toBeNull();
  });
});

/* ------------------------------------------------------------- iCal block */

describe('iCal feed block', () => {
  it('renders the feed URL read-only with a copy button', async () => {
    renderWithProviders(<IcalFeedCard />, { role: 'student' });

    const input = (await screen.findByLabelText(/Indirizzo del calendario/)) as HTMLInputElement;
    expect(input).toHaveAttribute('readonly');
    expect(input.value).toContain(`/api/v1/ical/${'a'.repeat(64)}.ics`);
    expect(screen.getByRole('button', { name: /Copia il link/ })).toBeVisible();
    expect(screen.getByText(/Altri calendari › Da URL/)).toBeVisible();
  });

  it('copies the URL to the clipboard', async () => {
    const user = userEvent.setup();
    // userEvent installs its own clipboard stub; wrap it so we can watch it.
    const writeText = vi.spyOn(navigator.clipboard, 'writeText');
    renderWithProviders(<IcalFeedCard />, { role: 'student' });

    await screen.findByLabelText(/Indirizzo del calendario/);
    await user.click(screen.getByRole('button', { name: /Copia il link/ }));

    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
    expect(String(writeText.mock.calls[0]![0])).toContain('/api/v1/ical/');
    expect(await screen.findByText('Link copiato.')).toBeVisible();
  });

  it('warns before rotating and swaps the URL on confirm', async () => {
    const user = userEvent.setup();
    renderWithProviders(<IcalFeedCard />, { role: 'student' });

    const input = (await screen.findByLabelText(/Indirizzo del calendario/)) as HTMLInputElement;
    const before = input.value;

    await user.click(screen.getByRole('button', { name: /Rigenera link/ }));

    const confirm = await screen.findByRole('dialog');
    expect(within(confirm).getByText(/Il vecchio link smetterà di funzionare/)).toBeVisible();
    expect(mockState.icalRotateCalls).toBe(0);

    await user.click(within(confirm).getByRole('button', { name: 'Rigenera link' }));

    await waitFor(() => expect(mockState.icalRotateCalls).toBe(1));
    await waitFor(() => expect(input.value).not.toBe(before));
    expect(input.value).toContain('rotated');
    expect(await screen.findByText('Nuovo link generato.')).toBeVisible();
  });

  it('can be dismissed without rotating', async () => {
    const user = userEvent.setup();
    renderWithProviders(<IcalFeedCard />, { role: 'student' });

    await screen.findByLabelText(/Indirizzo del calendario/);
    await user.click(screen.getByRole('button', { name: /Rigenera link/ }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Annulla' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(mockState.icalRotateCalls).toBe(0);
  });

  it('uses the staff wording on the lab calendar page', async () => {
    renderWithProviders(<IcalFeedCard staff />, { role: 'technician' });
    expect(await screen.findByText(/tutti i ritiri e le riconsegne del laboratorio/)).toBeVisible();
  });
});

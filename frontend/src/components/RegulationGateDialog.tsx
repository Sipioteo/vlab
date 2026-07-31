import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useMutation, useQueries, useQuery } from '@tanstack/react-query';
import * as api from '@/api/endpoints';
import { useAuth } from '@/auth/AuthProvider';
import { useToast } from '@/components/Toast';
import { RegulationAcceptBlock } from '@/components/domain';
import { Button, Skeleton } from '@/components/ui';
import { t } from '@/i18n/it';
import type { PendingRegulation } from '@/types/api';

const FOCUSABLE =
  'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** A pending regulation blocks the whole SPA when it is global (SPEC §5.5). */
export function isBlockingRegulation(reg: PendingRegulation): boolean {
  return reg.blocking ?? reg.scope === 'global';
}

/**
 * The mandatory-regulation gate.
 *
 * Not a route — a route is something the user has to *find*, and that was
 * exactly the bug: the interstitial existed but nothing ever sent anyone to it.
 * This is a modal the app puts in front of you the moment you are in, with the
 * rest of the shell marked `inert` behind it. There is no Esc, no backdrop
 * click, no close button: the only ways out are accepting or logging out.
 */
export function RegulationGateDialog() {
  const { pendingRegulations, setPendingRegulations, refresh, logout } = useAuth();
  const { push, pushError } = useToast();
  const [accepted, setAccepted] = useState<Record<number, boolean>>({});
  const dialogRef = useRef<HTMLDivElement>(null);
  const titleId = useId();
  const descId = useId();

  /**
   * The session payload is the source of truth for *whether* we block; this
   * query re-reads the canonical list so a stale login response cannot leave
   * the dialog showing a regulation that is already accepted.
   */
  const pendingQuery = useQuery({
    queryKey: ['pending-regulations'],
    queryFn: api.getPendingRegulations,
    initialData:
      pendingRegulations.length > 0 ? { data: pendingRegulations, meta: null } : undefined,
  });

  /**
   * The dedicated endpoint wins, but if it comes back empty while the session
   * still says we are blocked we fall back to the session list rather than
   * render an empty dialog with a dead confirm button. Accepting is idempotent,
   * so the worst case is one redundant POST.
   */
  const pending = useMemo(() => {
    const fromQuery = (pendingQuery.data?.data ?? []).filter(isBlockingRegulation);
    if (fromQuery.length > 0) return fromQuery;
    return pendingRegulations.filter(isBlockingRegulation);
  }, [pendingQuery.data, pendingRegulations]);

  // Bodies are not in the pending payload — fetch each document to render it.
  const details = useQueries({
    queries: pending.map((reg) => ({
      queryKey: ['regulation', reg.id],
      queryFn: () => api.getRegulation(reg.id),
    })),
  });

  const acceptAll = useMutation({
    mutationFn: async () => {
      let latest: PendingRegulation[] = [];
      for (const reg of pending) {
        const res = await api.acceptRegulation(reg.id, reg.version);
        latest = res.pending_regulations;
      }
      return latest;
    },
    onSuccess: async (latest) => {
      setPendingRegulations(latest);
      push(t('regulations.accepted2'), 'success');
      // Re-read the session so the gate closes on the server's word, not ours.
      await refresh();
      await pendingQuery.refetch();
    },
    onError: async (error) => {
      pushError(error);
      // A 409 means someone published a new version mid-read: resync.
      await pendingQuery.refetch();
      setAccepted({});
    },
  });

  /* ---------------------------------------------------------------- focus */

  useEffect(() => {
    const node = dialogRef.current;
    if (!node) return;
    const previous = document.activeElement as HTMLElement | null;
    (node.querySelector<HTMLElement>(FOCUSABLE) ?? node).focus();
    return () => previous?.focus?.();
  }, []);

  // Body scroll lock: the page behind must not move while the gate is up.
  useEffect(() => {
    if (typeof document === 'undefined') return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previous;
    };
  }, []);

  const onKeyDown = useCallback((event: React.KeyboardEvent) => {
    // Esc is deliberately swallowed: this dialog is not dismissible.
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (event.key !== 'Tab') return;
    const node = dialogRef.current;
    if (!node) return;
    const items = Array.from(node.querySelectorAll<HTMLElement>(FOCUSABLE));
    if (items.length === 0) {
      event.preventDefault();
      return;
    }
    const first = items[0]!;
    const last = items[items.length - 1]!;
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }, []);

  /* --------------------------------------------------------------- render */

  const doneCount = pending.filter((reg) => accepted[reg.id]).length;
  const allChecked = pending.length > 0 && doneCount === pending.length;
  const loading = pendingQuery.isLoading && pending.length === 0;

  return (
    <div className="vl-gate-backdrop" data-testid="regulation-gate">
      <div
        className="vl-gate"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={descId}
        ref={dialogRef}
        tabIndex={-1}
        onKeyDown={onKeyDown}
      >
        <div className="vl-gate__head">
          <p className="vl-eyebrow">{t('nav.regulations')}</p>
          <h2 id={titleId}>{t('regulations.gateTitle')}</h2>
          <p id={descId} className="vl-lead">
            {pending.length > 1 ? t('regulations.gateLead') : t('regulations.gateOneLead')}
          </p>
        </div>

        <div className="vl-gate__body">
          {loading ? (
            <Skeleton height={280} radius={6} />
          ) : (
            <div className="vl-stack">
              {pending.map((reg, index) => {
                const detail = details[index]?.data;
                return (
                  <RegulationAcceptBlock
                    key={reg.id}
                    regulation={{
                      id: reg.id,
                      slug: reg.slug,
                      title: reg.title,
                      summary: reg.summary ?? detail?.summary ?? null,
                      version: reg.version,
                      body: detail?.body ?? null,
                      content_type: reg.content_type,
                      file_url: reg.file_url ?? null,
                    }}
                    checked={Boolean(accepted[reg.id])}
                    onChange={(value) =>
                      setAccepted((prev) => ({ ...prev, [reg.id]: value }))
                    }
                    disabled={acceptAll.isPending}
                  />
                );
              })}
            </div>
          )}
        </div>

        <div className="vl-gate__foot">
          <p className="vl-gate__progress" aria-live="polite">
            {pending.length > 1
              ? t('regulations.gateProgress', { done: doneCount, total: pending.length })
              : allChecked
                ? ''
                : t('regulations.gateHint')}
          </p>
          <div className="vl-row" style={{ gap: 'var(--sp-3)' }}>
            <Button variant="ghost" onClick={() => void logout()} disabled={acceptAll.isPending}>
              {t('regulations.gateLogout')}
            </Button>
            <Button
              variant="primary"
              size="lg"
              disabled={!allChecked}
              loading={acceptAll.isPending}
              onClick={() => acceptAll.mutate()}
            >
              {t('regulations.acceptAll')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

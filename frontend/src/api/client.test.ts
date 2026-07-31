import { describe, it, expect, vi } from 'vitest';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import {
  ApiError,
  apiFetch,
  getAccessToken,
  registerAuthLostHandler,
  resetRefreshState,
  setAccessToken,
  setRefreshToken,
} from './client';

const ERROR_ENVELOPE = (code: string, message: string, details: unknown = null) => ({
  error: { code, message, details, trace_id: 'abcd1234' },
});

describe('apiFetch', () => {
  it('attaches the Authorization header when a token exists and omits it otherwise', async () => {
    const seen: (string | null)[] = [];
    server.use(
      http.get('/api/v1/ping', ({ request }) => {
        seen.push(request.headers.get('Authorization'));
        return HttpResponse.json({ ok: true });
      }),
    );

    await apiFetch('/api/v1/ping');
    setAccessToken('tok-123');
    await apiFetch('/api/v1/ping');

    expect(seen[0]).toBeNull();
    expect(seen[1]).toBe('Bearer tok-123');
  });

  it('performs one refresh and retries the original request on 401 token_expired', async () => {
    setAccessToken('stale');
    setRefreshToken('refresh-token-student');
    let refreshCount = 0;
    let attempts = 0;

    server.use(
      http.post('/api/v1/auth/refresh', () => {
        refreshCount += 1;
        return HttpResponse.json({
          access_token: 'fresh',
          token_type: 'Bearer',
          expires_in: 100,
          expires_at: '2026-07-31T17:00:00Z',
          refresh_token: 'refresh-2',
          refresh_expires_at: '2026-08-14T09:00:00Z',
          user: null,
          pending_regulations: [],
        });
      }),
      http.get('/api/v1/protected', () => {
        attempts += 1;
        if (attempts === 1) {
          return HttpResponse.json(ERROR_ENVELOPE('token_expired', 'Token scaduto.'), { status: 401 });
        }
        return HttpResponse.json({ ok: true });
      }),
    );

    const result = await apiFetch<{ ok: boolean }>('/api/v1/protected');
    expect(result.ok).toBe(true);
    expect(refreshCount).toBe(1);
    expect(attempts).toBe(2);
    expect(getAccessToken()).toBe('fresh');
  });

  it('de-duplicates concurrent refreshes into exactly one call', async () => {
    setAccessToken('stale');
    setRefreshToken('refresh-token-student');
    resetRefreshState();
    let refreshCount = 0;
    const attempts = new Map<string, number>();

    server.use(
      http.post('/api/v1/auth/refresh', async () => {
        refreshCount += 1;
        await new Promise((resolve) => setTimeout(resolve, 20));
        return HttpResponse.json({
          access_token: 'fresh',
          token_type: 'Bearer',
          expires_in: 100,
          expires_at: '2026-07-31T17:00:00Z',
          refresh_token: 'refresh-2',
          refresh_expires_at: '2026-08-14T09:00:00Z',
          user: null,
          pending_regulations: [],
        });
      }),
      http.get('/api/v1/a', () => {
        const n = (attempts.get('a') ?? 0) + 1;
        attempts.set('a', n);
        return n === 1
          ? HttpResponse.json(ERROR_ENVELOPE('token_expired', 'Token scaduto.'), { status: 401 })
          : HttpResponse.json({ ok: true });
      }),
      http.get('/api/v1/b', () => {
        const n = (attempts.get('b') ?? 0) + 1;
        attempts.set('b', n);
        return n === 1
          ? HttpResponse.json(ERROR_ENVELOPE('token_expired', 'Token scaduto.'), { status: 401 })
          : HttpResponse.json({ ok: true });
      }),
    );

    await Promise.all([apiFetch('/api/v1/a'), apiFetch('/api/v1/b')]);
    expect(refreshCount).toBe(1);
  });

  it('clears the session and notifies the auth-lost handler when the refresh fails', async () => {
    setAccessToken('stale');
    setRefreshToken('bad-refresh');
    const onLost = vi.fn();
    registerAuthLostHandler(onLost);

    server.use(
      http.post('/api/v1/auth/refresh', () =>
        HttpResponse.json(ERROR_ENVELOPE('refresh_invalid', 'Sessione scaduta.'), { status: 401 }),
      ),
      http.get('/api/v1/protected', () =>
        HttpResponse.json(ERROR_ENVELOPE('token_expired', 'Token scaduto.'), { status: 401 }),
      ),
    );

    await expect(apiFetch('/api/v1/protected')).rejects.toBeInstanceOf(ApiError);
    expect(onLost).toHaveBeenCalledTimes(1);
    expect(getAccessToken()).toBeNull();
    expect(localStorage.getItem('vlab.refresh_token')).toBeNull();
    registerAuthLostHandler(null);
  });

  it('throws ApiError carrying code, message and details on non-2xx', async () => {
    server.use(
      http.post('/api/v1/orders', () =>
        HttpResponse.json(
          ERROR_ENVELOPE('validation_failed', 'I dati inviati non sono validi.', {
            pickup_date: ['Il campo pickup_date è obbligatorio.'],
          }),
          { status: 422 },
        ),
      ),
    );

    try {
      await apiFetch('/api/v1/orders', { method: 'POST', body: {} });
      expect.unreachable('should have thrown');
    } catch (error) {
      expect(error).toBeInstanceOf(ApiError);
      const apiError = error as ApiError;
      expect(apiError.status).toBe(422);
      expect(apiError.code).toBe('validation_failed');
      expect(apiError.message).toBe('I dati inviati non sono validi.');
      expect(apiError.fieldErrors['pickup_date']).toEqual(['Il campo pickup_date è obbligatorio.']);
      expect(apiError.traceId).toBe('abcd1234');
    }
  });
});

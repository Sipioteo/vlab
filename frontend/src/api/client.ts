import type { ApiErrorBody, LoginResponse } from '@/types/api';

const BASE = (import.meta.env?.VITE_API_BASE_URL as string | undefined) ?? '';

export const REFRESH_STORAGE_KEY = 'vlab.refresh_token';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly details: Record<string, unknown> | null;
  readonly traceId: string | null;

  constructor(
    status: number,
    code: string,
    message: string,
    details: Record<string, unknown> | null = null,
    traceId: string | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
    this.traceId = traceId;
  }

  /** field -> messages, when the backend sent a validation_failed payload */
  get fieldErrors(): Record<string, string[]> {
    const out: Record<string, string[]> = {};
    if (!this.details) return out;
    for (const [k, v] of Object.entries(this.details)) {
      if (Array.isArray(v) && v.every((x) => typeof x === 'string')) out[k] = v as string[];
    }
    return out;
  }
}

/* ----------------------------------------------------------- token registry */

let accessToken: string | null = null;
let onAuthLost: (() => void) | null = null;

export function setAccessToken(token: string | null): void {
  accessToken = token;
}
export function getAccessToken(): string | null {
  return accessToken;
}
export function setRefreshToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(REFRESH_STORAGE_KEY, token);
    else localStorage.removeItem(REFRESH_STORAGE_KEY);
  } catch {
    /* storage unavailable (private mode) — tokens stay in memory only */
  }
}
export function getRefreshToken(): string | null {
  try {
    return localStorage.getItem(REFRESH_STORAGE_KEY);
  } catch {
    return null;
  }
}
export function registerAuthLostHandler(fn: (() => void) | null): void {
  onAuthLost = fn;
}

/* ------------------------------------------------------------- refresh flow */

const REFRESHABLE_CODES = new Set(['token_expired', 'token_stale']);
let refreshPromise: Promise<LoginResponse> | null = null;

/** De-duplicated single-flight refresh (SPEC §11.2.4). */
export function refreshSession(): Promise<LoginResponse> {
  if (refreshPromise) return refreshPromise;
  const stored = getRefreshToken();
  if (!stored) {
    return Promise.reject(new ApiError(401, 'refresh_invalid', 'Sessione scaduta.'));
  }
  refreshPromise = rawFetch<LoginResponse>('/api/v1/auth/refresh', {
    method: 'POST',
    body: { refresh_token: stored },
  })
    .then((res) => {
      setAccessToken(res.access_token);
      setRefreshToken(res.refresh_token);
      return res;
    })
    .finally(() => {
      refreshPromise = null;
    });
  return refreshPromise;
}

export function resetRefreshState(): void {
  refreshPromise = null;
}

/* ------------------------------------------------------------------- fetcher */

export interface ApiFetchInit extends Omit<RequestInit, 'body'> {
  body?: unknown;
  /** internal: prevents infinite refresh loops */
  _retry?: boolean;
}

async function rawFetch<T>(path: string, init: ApiFetchInit = {}): Promise<T> {
  const { body, headers, _retry, ...rest } = init;
  void _retry;
  const finalHeaders = new Headers(headers as HeadersInit | undefined);
  finalHeaders.set('Accept', 'application/json');
  if (accessToken) finalHeaders.set('Authorization', `Bearer ${accessToken}`);

  let payload: BodyInit | undefined;
  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined && body !== null) {
    payload = JSON.stringify(body);
    finalHeaders.set('Content-Type', 'application/json');
  }

  const response = await fetch(`${BASE}${path}`, {
    ...rest,
    headers: finalHeaders,
    body: payload,
  });

  if (response.status === 204) return undefined as T;

  const text = await response.text();
  let parsed: unknown = null;
  if (text) {
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = null;
    }
  }

  if (!response.ok) {
    const envelope = parsed as ApiErrorBody | null;
    const err = envelope?.error;
    throw new ApiError(
      response.status,
      err?.code ?? 'server_error',
      err?.message ?? 'Si è verificato un errore imprevisto.',
      err?.details ?? null,
      err?.trace_id ?? null,
    );
  }

  return parsed as T;
}

export async function apiFetch<T>(path: string, init: ApiFetchInit = {}): Promise<T> {
  try {
    return await rawFetch<T>(path, init);
  } catch (error) {
    if (
      error instanceof ApiError &&
      error.status === 401 &&
      REFRESHABLE_CODES.has(error.code) &&
      !init._retry
    ) {
      try {
        await refreshSession();
      } catch {
        setAccessToken(null);
        setRefreshToken(null);
        onAuthLost?.();
        throw error;
      }
      return rawFetch<T>(path, { ...init, _retry: true });
    }
    throw error;
  }
}

/* ------------------------------------------------------------------ helpers */

export type QueryValue = string | number | boolean | null | undefined;

export function buildQuery(params: Record<string, QueryValue>): string {
  const sp = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue;
    sp.set(key, String(value));
  }
  const qs = sp.toString();
  return qs ? `?${qs}` : '';
}

export const API_PREFIX = '/api/v1';

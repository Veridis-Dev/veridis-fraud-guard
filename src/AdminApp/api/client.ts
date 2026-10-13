import { __ } from '@wordpress/i18n';

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code = '',
    public readonly field = '',
  ) {
    super(message);
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  formData?: FormData;
  signal?: AbortSignal;
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const config = window.veridisFraudGuard;
  if (!config?.restUrl || !config.nonce) {
    throw new ApiError(__('Fraud Guard could not start. Reload this page.', 'veridis-fraud-guard'), 0);
  }

  const url = new URL(config.restUrl);
  const relative = new URL(path, 'https://veridis.invalid/');

  if (url.searchParams.has('rest_route')) {
    url.searchParams.set(
      'rest_route',
      `${url.searchParams.get('rest_route')?.replace(/\/$/, '') ?? ''}${relative.pathname}`,
    );
  } else {
    url.pathname = `${url.pathname.replace(/\/$/, '')}${relative.pathname}`;
  }
  relative.searchParams.forEach((value, key) => url.searchParams.set(key, value));

  const response = await fetch(url, {
    method: options.method ?? 'GET',
    credentials: 'same-origin',
    signal: options.signal,
    headers: {
      Accept: 'application/json',
      ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      'X-WP-Nonce': config.nonce,
    },
    body: options.formData ?? (options.body === undefined ? undefined : JSON.stringify(options.body)),
  });

  const refreshedNonce = response.headers.get('X-WP-Nonce');
  if (refreshedNonce) config.nonce = refreshedNonce;

  if (!response.ok) {
    let serverError: { code?: string; message?: string; data?: { field?: string } } = {};
    try {
      serverError = (await response.clone().json()) as typeof serverError;
    } catch {
      // A stable localized fallback is used below.
    }

    const message = response.status === 401 || response.status === 403
      ? __('Your session expired or you do not have access. Reload the page and try again.', 'veridis-fraud-guard')
      : String(serverError.message ?? '') || __('Fraud Guard could not complete the request. Please try again.', 'veridis-fraud-guard');
    throw new ApiError(message, response.status, String(serverError.code ?? ''), String(serverError.data?.field ?? ''));
  }

  try {
    return (await response.json()) as T;
  } catch {
    throw new ApiError(__('The server returned an unexpected response.', 'veridis-fraud-guard'), response.status);
  }
}

export function apiGet<T>(path: string, signal?: AbortSignal): Promise<T> {
  return apiRequest<T>(path, { signal });
}

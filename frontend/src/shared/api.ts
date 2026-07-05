/**
 * Cliente del API impay/v1: cookie auth de WP + nonce en cada request.
 * Los datos de arranque los imprime PHP en #impay-boot.
 */

export interface BootData {
  restUrl: string;
  nonce: string;
  adminUrl?: string;
  userName?: string;
  gateways: string[];
  wompi?: { public_key: string; base_url: string };
  version?: string;
}

let cachedBoot: BootData | null = null;

export function boot(): BootData {
  if (cachedBoot) return cachedBoot;

  const el = document.getElementById('impay-boot');
  const parsed = el?.textContent ? (JSON.parse(el.textContent) as BootData) : null;

  cachedBoot = parsed ?? { restUrl: '/wp-json/impay/v1/', nonce: '', gateways: [] };
  return cachedBoot;
}

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly code: string,
    public readonly status: number,
    public readonly errors?: Record<string, string>,
  ) {
    super(message);
  }
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const { restUrl, nonce } = boot();

  const response = await fetch(restUrl + path.replace(/^\//, ''), {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const contentType = response.headers.get('content-type') ?? '';

  if (!contentType.includes('application/json')) {
    if (!response.ok) {
      throw new ApiError('Error inesperado del servidor.', 'impay_error', response.status);
    }

    return (await response.text()) as unknown as T;
  }

  const data = (await response.json()) as Record<string, unknown>;

  if (!response.ok) {
    throw new ApiError(
      typeof data.message === 'string' ? data.message : 'Error inesperado.',
      typeof data.code === 'string' ? data.code : 'impay_error',
      response.status,
      data.errors as Record<string, string> | undefined,
    );
  }

  return data as T;
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
};

/**
 * Tokenización de Wompi en el navegador: los datos de tarjeta van
 * DIRECTO a la API de Wompi con la llave pública (jamás a este sitio).
 * Nequi: se solicita un token y el cliente lo aprueba en su app.
 */

export interface WompiConfig {
  public_key: string;
  base_url: string;
}

export interface WompiFormState {
  method: 'CARD' | 'NEQUI';
  number: string;
  expMonth: string;
  expYear: string;
  cvc: string;
  holder: string;
  phone: string;
  acceptance: boolean;
}

export const emptyWompiForm: WompiFormState = {
  method: 'CARD',
  number: '',
  expMonth: '',
  expYear: '',
  cvc: '',
  holder: '',
  phone: '',
  acceptance: false,
};

async function wompiPost(config: WompiConfig, path: string, body: Record<string, string>): Promise<Record<string, unknown>> {
  const response = await fetch(`${config.base_url}${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${config.public_key}`,
    },
    body: JSON.stringify(body),
  });

  const json = (await response.json().catch(() => ({}))) as { data?: Record<string, unknown>; error?: { reason?: string } };

  if (!response.ok || !json.data) {
    throw new Error(json.error?.reason ?? 'Wompi rechazó los datos del medio de pago. Revísalos e intenta de nuevo.');
  }

  return json.data;
}

async function wompiGet(config: WompiConfig, path: string): Promise<Record<string, unknown>> {
  const response = await fetch(`${config.base_url}${path}`, {
    headers: { Authorization: `Bearer ${config.public_key}` },
  });

  const json = (await response.json().catch(() => ({}))) as { data?: Record<string, unknown> };

  return json.data ?? {};
}

const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

/**
 * Devuelve el token de un solo uso para crear la fuente de pago en el
 * servidor. Para Nequi espera (máx. ~2 min) a que el cliente apruebe
 * la suscripción en su app.
 */
export async function tokenizeWompi(
  config: WompiConfig,
  form: WompiFormState,
  onStatus: (message: string) => void,
): Promise<{ token: string; type: 'CARD' | 'NEQUI' }> {
  if (form.method === 'CARD') {
    if (!form.number.trim() || !form.expMonth.trim() || !form.expYear.trim() || !form.cvc.trim() || !form.holder.trim()) {
      throw new Error('Completa todos los datos de la tarjeta.');
    }

    onStatus('Validando tarjeta…');

    const data = await wompiPost(config, '/tokens/cards', {
      number: form.number.replace(/\s/g, ''),
      cvc: form.cvc.trim(),
      exp_month: form.expMonth.trim().padStart(2, '0'),
      exp_year: form.expYear.trim().slice(-2),
      card_holder: form.holder.trim(),
    });

    const token = typeof data.id === 'string' ? data.id : '';

    if (!token) throw new Error('Wompi no devolvió el token de la tarjeta.');

    return { token, type: 'CARD' };
  }

  const phone = form.phone.replace(/\D/g, '');

  if (phone.length !== 10) {
    throw new Error('Ingresa tu número Nequi de 10 dígitos.');
  }

  onStatus('Enviando solicitud a tu app Nequi…');

  const created = await wompiPost(config, '/tokens/nequi', { phone_number: phone });
  const token = typeof created.token === 'string' ? created.token : typeof created.id === 'string' ? created.id : '';

  if (!token) throw new Error('Wompi no devolvió el token de Nequi.');

  // El cliente debe aprobar la suscripción en su app (push de Nequi).
  onStatus('Abre tu app Nequi y acepta la suscripción…');

  for (let attempt = 0; attempt < 40; attempt++) {
    await wait(3000);

    const status = await wompiGet(config, `/tokens/nequi/${encodeURIComponent(token)}`);

    if (status.status === 'APPROVED') {
      return { token, type: 'NEQUI' };
    }

    if (status.status === 'DECLINED' || status.status === 'REJECTED') {
      throw new Error('La solicitud fue rechazada en Nequi. Intenta de nuevo.');
    }
  }

  throw new Error('No recibimos la aprobación en Nequi a tiempo. Intenta de nuevo.');
}

/** Campos de tokenización (tarjeta o Nequi) para suscripciones con Wompi. */
export function WompiFields({
  value,
  onChange,
  inputClass,
  status,
}: {
  value: WompiFormState;
  onChange: (next: WompiFormState) => void;
  inputClass: string;
  status: string;
}) {
  const set = (patch: Partial<WompiFormState>) => onChange({ ...value, ...patch });

  return (
    <div className="impay-space-y-3 impay-rounded-control impay-border impay-border-line impay-bg-canvas impay-p-4">
      <div className="impay-grid impay-grid-cols-2 impay-gap-2">
        {(['CARD', 'NEQUI'] as const).map((method) => (
          <button
            key={method}
            type="button"
            onClick={() => set({ method })}
            className={`impay-rounded-control impay-border impay-px-3 impay-py-2 impay-text-sm impay-font-medium ${
              value.method === method
                ? 'impay-border-accent impay-bg-accent-soft impay-text-accent'
                : 'impay-border-line impay-bg-white impay-text-muted'
            }`}
          >
            {method === 'CARD' ? 'Tarjeta' : 'Nequi'}
          </button>
        ))}
      </div>

      {value.method === 'CARD' ? (
        <>
          <label className="impay-block impay-text-sm">
            <span className="impay-mb-1 impay-block impay-font-medium">Número de tarjeta</span>
            <input
              className={inputClass}
              inputMode="numeric"
              autoComplete="cc-number"
              placeholder="4242 4242 4242 4242"
              value={value.number}
              onChange={(e) => set({ number: e.target.value })}
            />
          </label>
          <div className="impay-grid impay-grid-cols-3 impay-gap-2">
            <label className="impay-block impay-text-sm">
              <span className="impay-mb-1 impay-block impay-font-medium">Mes</span>
              <input className={inputClass} inputMode="numeric" placeholder="12" maxLength={2} value={value.expMonth} onChange={(e) => set({ expMonth: e.target.value })} />
            </label>
            <label className="impay-block impay-text-sm">
              <span className="impay-mb-1 impay-block impay-font-medium">Año</span>
              <input className={inputClass} inputMode="numeric" placeholder="28" maxLength={4} value={value.expYear} onChange={(e) => set({ expYear: e.target.value })} />
            </label>
            <label className="impay-block impay-text-sm">
              <span className="impay-mb-1 impay-block impay-font-medium">CVC</span>
              <input className={inputClass} inputMode="numeric" placeholder="123" maxLength={4} value={value.cvc} onChange={(e) => set({ cvc: e.target.value })} />
            </label>
          </div>
          <label className="impay-block impay-text-sm">
            <span className="impay-mb-1 impay-block impay-font-medium">Nombre en la tarjeta</span>
            <input className={inputClass} autoComplete="cc-name" value={value.holder} onChange={(e) => set({ holder: e.target.value })} />
          </label>
        </>
      ) : (
        <label className="impay-block impay-text-sm">
          <span className="impay-mb-1 impay-block impay-font-medium">Número Nequi</span>
          <input
            className={inputClass}
            inputMode="tel"
            placeholder="3001234567"
            maxLength={10}
            value={value.phone}
            onChange={(e) => set({ phone: e.target.value })}
          />
          <span className="impay-mt-1 impay-block impay-text-xs impay-text-muted">
            Te llegará una notificación en tu app Nequi para aprobar la suscripción.
          </span>
        </label>
      )}

      <label className="impay-flex impay-items-start impay-gap-2 impay-text-xs impay-text-muted">
        <input
          type="checkbox"
          className="impay-mt-0.5"
          checked={value.acceptance}
          onChange={(e) => set({ acceptance: e.target.checked })}
        />
        <span>
          Acepto los{' '}
          <a href="https://wompi.com/es/co/terminos-y-condiciones/" target="_blank" rel="noreferrer" className="impay-underline">
            términos de Wompi
          </a>{' '}
          y autorizo los cobros recurrentes de esta suscripción.
        </span>
      </label>

      {status && <p className="impay-text-xs impay-font-medium impay-text-accent">{status}</p>}

      <p className="impay-text-xs impay-text-muted">
        🔒 Tus datos van cifrados directamente a Wompi (Bancolombia). No los almacenamos.
      </p>
    </div>
  );
}

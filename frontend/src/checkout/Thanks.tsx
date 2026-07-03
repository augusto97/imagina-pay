import { useEffect, useRef, useState } from 'react';
import { api, boot } from '@shared/api';

/**
 * /gracias?order={uuid}: polling cada 3s (máx 2 min) al estado del order.
 */

type Status = 'pending' | 'paid' | 'failed' | 'timeout';

interface ThanksBoot {
  order?: string;
  portalUrl?: string;
}

const POLL_INTERVAL = 3000;
const MAX_POLLS = 40; // 2 minutos.

export function ThanksPage() {
  const { order, portalUrl } = boot() as unknown as ThanksBoot;
  const [status, setStatus] = useState<Status>('pending');
  const [productName, setProductName] = useState('');
  const polls = useRef(0);

  useEffect(() => {
    if (!order) {
      setStatus('failed');
      return;
    }

    let cancelled = false;

    const poll = async () => {
      polls.current += 1;

      try {
        const result = await api.get<{ status: string; product_name: string }>(`orders/${order}/status`);

        if (cancelled) return;

        setProductName(result.product_name);

        if (result.status === 'paid') {
          setStatus('paid');
          return;
        }

        if (['failed', 'cancelled', 'expired'].includes(result.status)) {
          setStatus('failed');
          return;
        }
      } catch {
        // Error transitorio: seguir intentando dentro de la ventana.
      }

      if (polls.current >= MAX_POLLS) {
        setStatus('timeout');
        return;
      }

      window.setTimeout(() => void poll(), POLL_INTERVAL);
    };

    void poll();

    return () => {
      cancelled = true;
    };
  }, [order]);

  return (
    <div className="impay-mx-auto impay-flex impay-min-h-[60vh] impay-max-w-md impay-flex-col impay-items-center impay-justify-center impay-px-4 impay-py-16 impay-text-center">
      {status === 'pending' && (
        <>
          <div className="impay-h-10 impay-w-10 impay-animate-spin impay-rounded-full impay-border-2 impay-border-line impay-border-t-accent" />
          <h1 className="impay-mt-6 impay-text-xl impay-font-semibold impay-tracking-tight">Confirmando tu pago…</h1>
          <p className="impay-mt-2 impay-text-sm impay-text-muted">
            Esto puede tardar unos segundos. No cierres esta página.
          </p>
        </>
      )}

      {status === 'paid' && (
        <>
          <div className="impay-flex impay-h-16 impay-w-16 impay-items-center impay-justify-center impay-rounded-full impay-bg-emerald-50 impay-text-3xl impay-text-ok impay-animate-[bounce_0.6s_ease-out_1]">
            ✓
          </div>
          <h1 className="impay-mt-6 impay-text-xl impay-font-semibold impay-tracking-tight">¡Pago confirmado!</h1>
          <p className="impay-mt-2 impay-text-sm impay-text-muted">
            {productName ? `Tu compra de ${productName} está lista. ` : ''}
            Acabamos de enviarte un email con los detalles y tus accesos.
          </p>
          <a
            href={portalUrl ?? '/mi-cuenta/'}
            className="impay-mt-6 impay-rounded-control impay-bg-accent impay-px-6 impay-py-3 impay-text-sm impay-font-semibold impay-text-white hover:impay-bg-accent-hover"
          >
            Ir a mi cuenta
          </a>
        </>
      )}

      {status === 'failed' && (
        <>
          <div className="impay-flex impay-h-16 impay-w-16 impay-items-center impay-justify-center impay-rounded-full impay-bg-red-50 impay-text-3xl impay-text-bad">
            ✕
          </div>
          <h1 className="impay-mt-6 impay-text-xl impay-font-semibold impay-tracking-tight">El pago no se completó</h1>
          <p className="impay-mt-2 impay-text-sm impay-text-muted">
            No te preocupes: no se realizó ningún cobro. Puedes intentarlo de nuevo cuando quieras.
          </p>
          <button
            onClick={() => window.history.back()}
            className="impay-mt-6 impay-rounded-control impay-border impay-border-line impay-bg-white impay-px-6 impay-py-3 impay-text-sm impay-font-medium hover:impay-bg-canvas"
          >
            Reintentar el pago
          </button>
        </>
      )}

      {status === 'timeout' && (
        <>
          <h1 className="impay-text-xl impay-font-semibold impay-tracking-tight">Seguimos procesando tu pago</h1>
          <p className="impay-mt-2 impay-text-sm impay-text-muted">
            Tu pago está siendo confirmado por la pasarela. Te enviaremos un email apenas se acredite — también
            puedes revisar el estado en tu cuenta.
          </p>
          <a
            href={portalUrl ?? '/mi-cuenta/'}
            className="impay-mt-6 impay-rounded-control impay-border impay-border-line impay-bg-white impay-px-6 impay-py-3 impay-text-sm impay-font-medium hover:impay-bg-canvas"
          >
            Ir a mi cuenta
          </a>
        </>
      )}
    </div>
  );
}

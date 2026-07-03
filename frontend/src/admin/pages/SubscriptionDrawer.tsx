import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError } from '@shared/api';
import { date, dateTime } from '@shared/format';
import type { Payment, Subscription } from '@shared/types';
import { Badge, Button, Spinner } from '@shared/ui/primitives';
import { Drawer } from '@shared/ui/layout';
import { toast } from '@shared/ui/toast';

interface Detail {
  subscription: Subscription;
  payments: Payment[];
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="impay-flex impay-justify-between impay-py-2 impay-text-sm">
      <span className="impay-text-muted">{label}</span>
      <span className="impay-text-right">{value}</span>
    </div>
  );
}

export function SubscriptionDrawer({ uuid, onClose }: { uuid: string | null; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [extendDays, setExtendDays] = useState('30');

  const { data, isLoading } = useQuery({
    queryKey: ['subscription', uuid],
    queryFn: () => api.get<Detail>(`admin/subscriptions/${uuid}`),
    enabled: uuid !== null,
  });

  const action = useMutation({
    mutationFn: ({ name, body }: { name: string; body?: unknown }) =>
      api.post(`admin/subscriptions/${uuid}/${name}`, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['subscription', uuid] });
      void queryClient.invalidateQueries({ queryKey: ['subscriptions'] });
      toast('Acción aplicada.');
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  const subscription = data?.subscription;
  const isMp = subscription?.gateway === 'mercadopago';
  const isFinal = subscription?.status === 'cancelled';

  return (
    <Drawer open={uuid !== null} title="Detalle de suscripción" onClose={onClose} wide>
      {isLoading || !subscription ? (
        <Spinner />
      ) : (
        <div className="impay-space-y-6">
          <div>
            <div className="impay-flex impay-items-center impay-justify-between">
              <h3 className="impay-font-semibold">{subscription.product?.name ?? 'Producto'}</h3>
              <Badge status={subscription.status} label={subscription.status_label} />
            </div>
            <p className="impay-mt-1 impay-text-sm impay-text-muted">
              {subscription.customer?.full_name} · {subscription.customer?.email}
            </p>
          </div>

          <div className="impay-divide-y impay-divide-line impay-rounded-card impay-border impay-border-line impay-px-4">
            <Row label="Pasarela" value={isMp ? 'Mercado Pago' : 'PayPal'} />
            <Row label="Ref. pasarela" value={subscription.gateway_sub_id ?? 'Suscripción lógica'} />
            <Row
              label="Periodo actual"
              value={
                subscription.current_period_end
                  ? `${date(subscription.current_period_start)} → ${date(subscription.current_period_end)}`
                  : '—'
              }
            />
            <Row label="Pagos fallidos" value={subscription.failed_payments} />
            {subscription.license_key && (
              <Row
                label="Licencia"
                value={
                  <button
                    className="impay-inline-flex impay-items-center impay-gap-1.5 impay-font-mono impay-text-xs hover:impay-text-accent"
                    onClick={() => {
                      void navigator.clipboard.writeText(subscription.license_key ?? '');
                      toast('Licencia copiada.');
                    }}
                  >
                    {subscription.license_key} <Copy size={13} />
                  </button>
                }
              />
            )}
          </div>

          {!isFinal && (
            <div className="impay-space-y-3">
              <h4 className="impay-text-sm impay-font-semibold">Acciones</h4>
              <div className="impay-flex impay-flex-wrap impay-gap-2">
                {subscription.status !== 'expired' && (
                  <>
                    <Button
                      variant="danger"
                      onClick={() => action.mutate({ name: 'cancel', body: { at_period_end: true } })}
                    >
                      Cancelar al vencer
                    </Button>
                    <Button variant="danger" onClick={() => action.mutate({ name: 'cancel', body: { at_period_end: false } })}>
                      Cancelar ya
                    </Button>
                  </>
                )}
                {isMp && subscription.status === 'active' && (
                  <Button variant="secondary" onClick={() => action.mutate({ name: 'pause' })}>
                    Pausar
                  </Button>
                )}
                {isMp && subscription.status === 'paused' && (
                  <Button variant="secondary" onClick={() => action.mutate({ name: 'resume' })}>
                    Reanudar
                  </Button>
                )}
              </div>
              <div className="impay-flex impay-items-center impay-gap-2">
                <input
                  className="impay-h-9 impay-w-20 impay-rounded-control impay-border impay-border-line impay-px-2 impay-text-sm"
                  value={extendDays}
                  onChange={(e) => setExtendDays(e.target.value)}
                  inputMode="numeric"
                />
                <Button
                  variant="secondary"
                  onClick={() => action.mutate({ name: 'extend', body: { days: Number(extendDays) } })}
                >
                  Extender días
                </Button>
              </div>
            </div>
          )}

          <div>
            <h4 className="impay-mb-2 impay-text-sm impay-font-semibold">Pagos</h4>
            {data.payments.length === 0 ? (
              <p className="impay-text-sm impay-text-muted">Sin pagos registrados.</p>
            ) : (
              <div className="impay-space-y-2">
                {data.payments.map((payment) => (
                  <div
                    key={payment.uuid}
                    className="impay-flex impay-items-center impay-justify-between impay-rounded-control impay-border impay-border-line impay-px-3 impay-py-2 impay-text-sm"
                  >
                    <div>
                      <span className="impay-tabular impay-font-medium">{payment.formatted}</span>
                      <span className="impay-ml-2 impay-text-xs impay-text-muted">
                        {payment.method ?? payment.gateway} · {dateTime(payment.paid_at ?? payment.created_at)}
                      </span>
                    </div>
                    <Badge status={payment.status} label={payment.status} />
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </Drawer>
  );
}

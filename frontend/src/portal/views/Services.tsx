import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError } from '@shared/api';
import { date } from '@shared/format';
import type { Subscription } from '@shared/types';
import { Badge, Button, Card, EmptyState, Spinner } from '@shared/ui/primitives';
import { toast } from '@shared/ui/toast';

interface ServiceItem extends Subscription {
  renewal_link: { url: string; status: string } | null;
}

export function ServicesView() {
  const queryClient = useQueryClient();
  const [cancelling, setCancelling] = useState<ServiceItem | null>(null);
  const [reason, setReason] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['me-subscriptions'],
    queryFn: () => api.get<{ items: ServiceItem[] }>('me/subscriptions'),
  });

  const cancel = useMutation({
    mutationFn: (subscription: ServiceItem) =>
      api.post(`me/subscriptions/${subscription.uuid}/cancel`, { reason }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['me-subscriptions'] });
      setCancelling(null);
      setReason('');
      toast('Tu suscripción se cancelará al final del periodo actual.');
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  if (isLoading) return <Spinner />;

  const items = data?.items ?? [];

  if (items.length === 0) {
    return (
      <Card>
        <EmptyState title="Aún no tienes servicios" hint="Cuando realices una compra, tus servicios aparecerán aquí." />
      </Card>
    );
  }

  return (
    <div className="impay-space-y-4">
      {items.map((subscription) => (
        <Card key={subscription.uuid} className="impay-p-5">
          {subscription.renewal_link && subscription.renewal_link.status === 'open' && (
            <div className="impay-mb-4 impay-flex impay-items-center impay-justify-between impay-rounded-control impay-border impay-border-warn/30 impay-bg-amber-50 impay-px-4 impay-py-3">
              <p className="impay-text-sm impay-font-medium impay-text-warn">
                Tu servicio está próximo a vencer. Renueva para no perder continuidad.
              </p>
              <a
                href={subscription.renewal_link.url}
                className="impay-rounded-control impay-bg-warn impay-px-4 impay-py-2 impay-text-sm impay-font-semibold impay-text-white hover:impay-opacity-90"
              >
                Renovar ahora
              </a>
            </div>
          )}

          <div className="impay-flex impay-items-start impay-justify-between">
            <div>
              <h3 className="impay-font-semibold impay-tracking-tight">{subscription.product?.name ?? 'Servicio'}</h3>
              <p className="impay-mt-1 impay-text-sm impay-text-muted">
                {subscription.current_period_end
                  ? subscription.gateway_sub_id || subscription.payment_method
                    ? `Se renueva el ${date(subscription.current_period_end)}`
                    : `Vence el ${date(subscription.current_period_end)}`
                  : ''}
                {subscription.cancel_at_period_end && ' · se cancelará al vencer'}
                {subscription.payment_method && (
                  <span className="impay-block impay-text-xs">
                    {subscription.payment_method.type === 'NEQUI' ? 'Nequi' : subscription.payment_method.brand ?? 'Tarjeta'}
                    {subscription.payment_method.last_four ? ` •••• ${subscription.payment_method.last_four}` : ''}
                  </span>
                )}
              </p>
            </div>
            <Badge status={subscription.status} label={subscription.status_label} />
          </div>

          {subscription.license_key && (
            <button
              className="impay-mt-4 impay-inline-flex impay-items-center impay-gap-2 impay-rounded-control impay-border impay-border-line impay-bg-canvas impay-px-3 impay-py-2 impay-font-mono impay-text-xs hover:impay-border-accent"
              onClick={() => {
                void navigator.clipboard.writeText(subscription.license_key ?? '');
                toast('Licencia copiada al portapapeles.');
              }}
            >
              {subscription.license_key} <Copy size={13} />
            </button>
          )}

          {['active', 'past_due'].includes(subscription.status) && !subscription.cancel_at_period_end && (
            <div className="impay-mt-4 impay-border-t impay-border-line impay-pt-4">
              {cancelling?.uuid === subscription.uuid ? (
                <div className="impay-space-y-3">
                  <p className="impay-text-sm">
                    ¿Seguro que quieres cancelar? Tu servicio seguirá activo hasta el{' '}
                    <strong>{date(subscription.current_period_end)}</strong> y no se realizarán más cobros.
                  </p>
                  <input
                    className="impay-h-10 impay-w-full impay-rounded-control impay-border impay-border-line impay-px-3 impay-text-sm"
                    placeholder="¿Nos cuentas el motivo? (opcional)"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                  />
                  <div className="impay-flex impay-gap-2">
                    <Button variant="danger" onClick={() => cancel.mutate(subscription)} disabled={cancel.isPending}>
                      Confirmar cancelación
                    </Button>
                    <Button variant="secondary" onClick={() => setCancelling(null)}>
                      Volver
                    </Button>
                  </div>
                </div>
              ) : (
                <button
                  onClick={() => setCancelling(subscription)}
                  className="impay-text-xs impay-text-muted hover:impay-text-bad"
                >
                  Cancelar suscripción
                </button>
              )}
            </div>
          )}
        </Card>
      ))}
    </div>
  );
}

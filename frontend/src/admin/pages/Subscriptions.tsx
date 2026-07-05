import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { api, boot } from '@shared/api';
import { date, gatewayLabel } from '@shared/format';
import type { Paginated, Subscription } from '@shared/types';
import { Badge, Card, EmptyState, Input, Select, Spinner } from '@shared/ui/primitives';
import { DataTable, Pagination } from '@shared/ui/layout';
import { PageHeader } from '../App';
import { SubscriptionDrawer } from './SubscriptionDrawer';

const PER_PAGE = 20;

export function SubscriptionsPage() {
  const [filters, setFilters] = useState({ status: '', gateway: '', search: '' });
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<string | null>(null);

  const query = new URLSearchParams({
    page: String(page),
    per_page: String(PER_PAGE),
    ...(filters.status && { status: filters.status }),
    ...(filters.gateway && { gateway: filters.gateway }),
    ...(filters.search && { search: filters.search }),
  });

  const { data, isLoading } = useQuery({
    queryKey: ['subscriptions', filters, page],
    queryFn: () => api.get<Paginated<Subscription>>(`admin/subscriptions?${query.toString()}`),
  });

  const update = (patch: Partial<typeof filters>) => {
    setFilters((current) => ({ ...current, ...patch }));
    setPage(1);
  };

  return (
    <div>
      <PageHeader title="Suscripciones" />

      <div className="impay-mb-4 impay-flex impay-gap-3">
        <div className="impay-relative impay-w-72">
          <Search size={15} className="impay-absolute impay-left-3 impay-top-3 impay-text-muted" />
          <Input
            className="impay-pl-9"
            placeholder="Buscar por cliente o email…"
            value={filters.search}
            onChange={(e) => update({ search: e.target.value })}
          />
        </div>
        <Select value={filters.status} onChange={(e) => update({ status: e.target.value })}>
          <option value="">Todos los estados</option>
          <option value="active">Activa</option>
          <option value="pending">Pendiente</option>
          <option value="past_due">Pago vencido</option>
          <option value="paused">Pausada</option>
          <option value="cancelled">Cancelada</option>
          <option value="expired">Vencida</option>
        </Select>
        <Select value={filters.gateway} onChange={(e) => update({ gateway: e.target.value })}>
          <option value="">Todas las pasarelas</option>
          {boot().gateways.map((gateway) => (
            <option key={gateway} value={gateway}>
              {gatewayLabel(gateway)}
            </option>
          ))}
        </Select>
      </div>

      <Card>
        {isLoading ? (
          <Spinner />
        ) : (data?.items.length ?? 0) === 0 ? (
          <EmptyState title="Sin suscripciones" hint="Las suscripciones de tus clientes aparecerán aquí." />
        ) : (
          <>
            <DataTable head={['Cliente', 'Producto', 'Estado', 'Pasarela', 'Periodo actual', '']}>
              {data?.items.map((subscription) => (
                <tr
                  key={subscription.uuid}
                  className="hover:impay-bg-canvas impay-cursor-pointer"
                  onClick={() => setSelected(subscription.uuid)}
                >
                  <td className="impay-px-4 impay-py-3">
                    <p className="impay-font-medium">{subscription.customer?.full_name ?? '—'}</p>
                    <p className="impay-text-xs impay-text-muted">{subscription.customer?.email}</p>
                  </td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">{subscription.product?.name ?? '—'}</td>
                  <td className="impay-px-4 impay-py-3">
                    <Badge status={subscription.status} label={subscription.status_label} />
                    {subscription.cancel_at_period_end && (
                      <span className="impay-ml-2 impay-text-xs impay-text-muted">cancela al vencer</span>
                    )}
                  </td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">
                    {gatewayLabel(subscription.gateway)}
                    {subscription.gateway_sub_id === null && ' · lógica'}
                  </td>
                  <td className="impay-tabular impay-px-4 impay-py-3">
                    {subscription.current_period_end ? `hasta ${date(subscription.current_period_end)}` : '—'}
                  </td>
                  <td className="impay-px-4 impay-py-3 impay-text-right impay-text-xs impay-text-accent">Ver</td>
                </tr>
              ))}
            </DataTable>
            <Pagination page={page} total={data?.total ?? 0} perPage={PER_PAGE} onPage={setPage} />
          </>
        )}
      </Card>

      <SubscriptionDrawer uuid={selected} onClose={() => setSelected(null)} />
    </div>
  );
}

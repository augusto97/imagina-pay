import { useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { api, boot } from '@shared/api';
import { dateTime, gatewayLabel } from '@shared/format';
import type { CurrencyAmount, Payment } from '@shared/types';
import { Badge, Button, Card, EmptyState, Input, Select, Spinner } from '@shared/ui/primitives';
import { DataTable, Pagination } from '@shared/ui/layout';
import { PageHeader } from '../App';

const PER_PAGE = 20;

interface PaymentsResponse {
  items: Payment[];
  total: number;
  sums: CurrencyAmount[];
}

export function PaymentsPage() {
  const [filters, setFilters] = useState({ status: '', gateway: '', from: '', to: '' });
  const [page, setPage] = useState(1);

  const params = new URLSearchParams({
    page: String(page),
    per_page: String(PER_PAGE),
    ...(filters.status && { status: filters.status }),
    ...(filters.gateway && { gateway: filters.gateway }),
    ...(filters.from && { from: filters.from }),
    ...(filters.to && { to: filters.to }),
  });

  const { data, isLoading } = useQuery({
    queryKey: ['payments', filters, page],
    queryFn: () => api.get<PaymentsResponse>(`admin/payments?${params.toString()}`),
  });

  const update = (patch: Partial<typeof filters>) => {
    setFilters((current) => ({ ...current, ...patch }));
    setPage(1);
  };

  const exportUrl = () => {
    const { restUrl, nonce } = boot();
    const exportParams = new URLSearchParams({
      _wpnonce: nonce,
      ...(filters.from && { from: filters.from }),
      ...(filters.to && { to: filters.to }),
    });

    return `${restUrl}admin/export/payments.csv?${exportParams.toString()}`;
  };

  return (
    <div>
      <PageHeader
        title="Pagos"
        actions={
          <a href={exportUrl()} download>
            <Button variant="secondary">
              <Download size={15} /> Exportar CSV
            </Button>
          </a>
        }
      />

      <div className="impay-mb-4 impay-flex impay-flex-wrap impay-items-center impay-gap-3">
        <Select value={filters.status} onChange={(e) => update({ status: e.target.value })}>
          <option value="">Todos los estados</option>
          <option value="approved">Aprobado</option>
          <option value="pending">Pendiente</option>
          <option value="rejected">Rechazado</option>
          <option value="refunded">Reembolsado</option>
          <option value="charged_back">Contracargo</option>
        </Select>
        <Select value={filters.gateway} onChange={(e) => update({ gateway: e.target.value })}>
          <option value="">Todas las pasarelas</option>
          <option value="mercadopago">Mercado Pago</option>
          <option value="paypal">PayPal</option>
          <option value="epayco">ePayco</option>
          <option value="wompi">Wompi</option>
        </Select>
        <Input type="date" className="impay-w-40" value={filters.from} onChange={(e) => update({ from: e.target.value })} />
        <span className="impay-text-sm impay-text-muted">a</span>
        <Input type="date" className="impay-w-40" value={filters.to} onChange={(e) => update({ to: e.target.value })} />

        {data && data.sums.length > 0 && (
          <span className="impay-ml-auto impay-text-sm impay-text-muted">
            Total del rango:{' '}
            <span className="impay-tabular impay-font-semibold impay-text-ink">
              {data.sums.map((sum) => sum.formatted).join(' · ')}
            </span>
          </span>
        )}
      </div>

      <Card>
        {isLoading ? (
          <Spinner />
        ) : (data?.items.length ?? 0) === 0 ? (
          <EmptyState title="Sin pagos en el rango" />
        ) : (
          <>
            <DataTable head={['Monto', 'Cliente', 'Método', 'Pasarela', 'Estado', 'Fecha']}>
              {data?.items.map((payment) => (
                <tr key={payment.uuid}>
                  <td className="impay-tabular impay-px-4 impay-py-3 impay-font-medium">{payment.formatted}</td>
                  <td className="impay-px-4 impay-py-3">
                    <p>{payment.customer?.full_name ?? '—'}</p>
                    <p className="impay-text-xs impay-text-muted">{payment.customer?.email}</p>
                    {(payment.custom_fields ?? []).map((field) => (
                      <p key={field.key} className="impay-text-xs impay-text-muted">
                        <span className="impay-font-medium">{field.label}:</span> {field.value}
                      </p>
                    ))}
                  </td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">{payment.method ?? '—'}</td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">
                    {gatewayLabel(payment.gateway)}
                  </td>
                  <td className="impay-px-4 impay-py-3">
                    <Badge status={payment.status} label={payment.status} />
                  </td>
                  <td className="impay-tabular impay-px-4 impay-py-3 impay-text-muted">
                    {dateTime(payment.paid_at ?? payment.created_at)}
                  </td>
                </tr>
              ))}
            </DataTable>
            <Pagination page={page} total={data?.total ?? 0} perPage={PER_PAGE} onPage={setPage} />
          </>
        )}
      </Card>
    </div>
  );
}

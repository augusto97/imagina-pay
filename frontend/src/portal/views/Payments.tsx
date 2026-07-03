import { useQuery } from '@tanstack/react-query';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { api, boot } from '@shared/api';
import { dateTime } from '@shared/format';
import type { Paginated, Payment } from '@shared/types';
import { Badge, Card, EmptyState, Spinner } from '@shared/ui/primitives';
import { DataTable, Pagination } from '@shared/ui/layout';

export function PaymentsView() {
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['me-payments', page],
    queryFn: () => api.get<Paginated<Payment>>(`me/payments?page=${page}`),
  });

  const receiptUrl = (payment: Payment) =>
    `${boot().restUrl}me/payments/${payment.uuid}/receipt?_wpnonce=${boot().nonce}`;

  if (isLoading) return <Spinner />;

  const items = data?.items ?? [];

  return (
    <Card>
      {items.length === 0 ? (
        <EmptyState title="Sin pagos registrados" hint="Tu historial de pagos aparecerá aquí." />
      ) : (
        <>
          <DataTable head={['Monto', 'Método', 'Estado', 'Fecha', 'Recibo']}>
            {items.map((payment) => (
              <tr key={payment.uuid}>
                <td className="impay-tabular impay-px-4 impay-py-3 impay-font-medium">{payment.formatted}</td>
                <td className="impay-px-4 impay-py-3 impay-text-muted">{payment.method ?? payment.gateway}</td>
                <td className="impay-px-4 impay-py-3">
                  <Badge status={payment.status} label={payment.status} />
                </td>
                <td className="impay-tabular impay-px-4 impay-py-3 impay-text-muted">
                  {dateTime(payment.paid_at ?? payment.created_at)}
                </td>
                <td className="impay-px-4 impay-py-3">
                  {payment.status === 'approved' && (
                    <a
                      href={receiptUrl(payment)}
                      target="_blank"
                      rel="noreferrer"
                      className="impay-inline-flex impay-items-center impay-gap-1 impay-text-xs impay-text-accent hover:impay-underline"
                    >
                      <FileText size={13} /> Ver recibo
                    </a>
                  )}
                </td>
              </tr>
            ))}
          </DataTable>
          <Pagination page={page} total={data?.total ?? 0} perPage={20} onPage={setPage} />
        </>
      )}
    </Card>
  );
}

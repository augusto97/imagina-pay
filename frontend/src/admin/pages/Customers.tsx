import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { api } from '@shared/api';
import { date } from '@shared/format';
import type { Customer, CurrencyAmount, Paginated, Payment, Subscription } from '@shared/types';
import { Badge, Card, EmptyState, Input, Spinner } from '@shared/ui/primitives';
import { DataTable, Drawer, Pagination } from '@shared/ui/layout';
import { PageHeader } from '../App';

const PER_PAGE = 20;

interface CustomerDetail {
  customer: Customer;
  subscriptions: Subscription[];
  payments: Payment[];
  ltv: CurrencyAmount[];
}

export function CustomersPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['customers', search, page],
    queryFn: () =>
      api.get<Paginated<Customer>>(
        `admin/customers?page=${page}&per_page=${PER_PAGE}&search=${encodeURIComponent(search)}`,
      ),
  });

  const detail = useQuery({
    queryKey: ['customer', selected],
    queryFn: () => api.get<CustomerDetail>(`admin/customers/${selected}`),
    enabled: selected !== null,
  });

  return (
    <div>
      <PageHeader title="Clientes" />

      <div className="impay-relative impay-mb-4 impay-w-72">
        <Search size={15} className="impay-absolute impay-left-3 impay-top-3 impay-text-muted" />
        <Input
          className="impay-pl-9"
          placeholder="Buscar por nombre, email o empresa…"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
      </div>

      <Card>
        {isLoading ? (
          <Spinner />
        ) : (data?.items.length ?? 0) === 0 ? (
          <EmptyState title="Sin clientes" hint="Tus clientes aparecerán aquí tras su primera compra." />
        ) : (
          <>
            <DataTable head={['Cliente', 'Documento', 'País', 'Desde', '']}>
              {data?.items.map((customer) => (
                <tr
                  key={customer.uuid}
                  className="hover:impay-bg-canvas impay-cursor-pointer"
                  onClick={() => setSelected(customer.uuid)}
                >
                  <td className="impay-px-4 impay-py-3">
                    <p className="impay-font-medium">{customer.full_name}</p>
                    <p className="impay-text-xs impay-text-muted">
                      {customer.email}
                      {customer.company ? ` · ${customer.company}` : ''}
                    </p>
                  </td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">
                    {customer.tax_id ? `${customer.tax_id_type} ${customer.tax_id}` : '—'}
                  </td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">{customer.country}</td>
                  <td className="impay-tabular impay-px-4 impay-py-3">{date(customer.created_at)}</td>
                  <td className="impay-px-4 impay-py-3 impay-text-right impay-text-xs impay-text-accent">Ver ficha</td>
                </tr>
              ))}
            </DataTable>
            <Pagination page={page} total={data?.total ?? 0} perPage={PER_PAGE} onPage={setPage} />
          </>
        )}
      </Card>

      <Drawer open={selected !== null} title="Ficha del cliente" onClose={() => setSelected(null)} wide>
        {detail.isLoading || !detail.data ? (
          <Spinner />
        ) : (
          <div className="impay-space-y-6">
            <div>
              <h3 className="impay-font-semibold">{detail.data.customer.full_name}</h3>
              <p className="impay-text-sm impay-text-muted">{detail.data.customer.email}</p>
              <div className="impay-mt-3 impay-flex impay-gap-4">
                {detail.data.ltv.map((amount) => (
                  <div key={amount.currency} className="impay-rounded-card impay-bg-accent-soft impay-px-4 impay-py-2">
                    <p className="impay-text-xs impay-text-muted">LTV {amount.currency}</p>
                    <p className="impay-tabular impay-font-semibold impay-text-accent">{amount.formatted}</p>
                  </div>
                ))}
              </div>
            </div>

            <div>
              <h4 className="impay-mb-2 impay-text-sm impay-font-semibold">Suscripciones</h4>
              {detail.data.subscriptions.length === 0 ? (
                <p className="impay-text-sm impay-text-muted">Sin suscripciones.</p>
              ) : (
                <div className="impay-space-y-2">
                  {detail.data.subscriptions.map((subscription) => (
                    <div
                      key={subscription.uuid}
                      className="impay-flex impay-items-center impay-justify-between impay-rounded-control impay-border impay-border-line impay-px-3 impay-py-2 impay-text-sm"
                    >
                      <span>{subscription.product?.name ?? '—'}</span>
                      <Badge status={subscription.status} label={subscription.status_label} />
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div>
              <h4 className="impay-mb-2 impay-text-sm impay-font-semibold">Últimos pagos</h4>
              {detail.data.payments.length === 0 ? (
                <p className="impay-text-sm impay-text-muted">Sin pagos.</p>
              ) : (
                <div className="impay-space-y-2">
                  {detail.data.payments.slice(0, 10).map((payment) => (
                    <div
                      key={payment.uuid}
                      className="impay-flex impay-justify-between impay-rounded-control impay-border impay-border-line impay-px-3 impay-py-2 impay-text-sm"
                    >
                      <span className="impay-tabular impay-font-medium">{payment.formatted}</span>
                      <span className="impay-text-xs impay-text-muted">{date(payment.paid_at ?? payment.created_at)}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </Drawer>
    </div>
  );
}

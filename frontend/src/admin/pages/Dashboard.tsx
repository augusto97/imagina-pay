import { useQuery } from '@tanstack/react-query';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { api } from '@shared/api';
import { date, money } from '@shared/format';
import type { DashboardMetrics } from '@shared/types';
import { Badge, Card, EmptyState, Spinner } from '@shared/ui/primitives';
import { DataTable } from '@shared/ui/layout';
import { PageHeader } from '../App';

function StatCard({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Card className="impay-p-5">
      <p className="impay-text-sm impay-text-muted">{label}</p>
      <p className="impay-tabular impay-mt-1 impay-text-2xl impay-font-semibold impay-tracking-tight">{value}</p>
      {hint && <p className="impay-mt-1 impay-text-xs impay-text-muted">{hint}</p>}
    </Card>
  );
}

function amounts(list: { formatted: string }[]): string {
  return list.length === 0 ? '$ 0' : list.map((item) => item.formatted).join(' · ');
}

export function DashboardPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get<DashboardMetrics>('admin/dashboard/metrics'),
  });

  if (isLoading || !data) return <Spinner />;

  const chartData = buildChartSeries(data.revenue_12m);

  return (
    <div>
      <PageHeader title="Dashboard" />

      <div className="impay-grid impay-grid-cols-4 impay-gap-4 max-lg:impay-grid-cols-2">
        <StatCard label="MRR estimado" value={amounts(data.mrr)} />
        <StatCard label="Suscripciones activas" value={String(data.active_subscriptions)} />
        <StatCard label="Ingresos del mes" value={amounts(data.month_revenue)} />
        <StatCard
          label="Pagos vencidos"
          value={String(data.past_due_subscriptions)}
          hint={data.past_due_subscriptions > 0 ? 'Requieren seguimiento' : 'Todo al día'}
        />
      </div>

      <Card className="impay-mt-6 impay-p-5">
        <h2 className="impay-mb-4 impay-text-sm impay-font-semibold">Ingresos — últimos 12 meses (COP)</h2>
        <div className="impay-h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={chartData} margin={{ top: 4, right: 8, bottom: 0, left: 8 }}>
              <CartesianGrid stroke="#E4E4E7" strokeDasharray="3 3" vertical={false} />
              <XAxis dataKey="month" tick={{ fontSize: 12, fill: '#71717A' }} tickLine={false} axisLine={false} />
              <YAxis
                tick={{ fontSize: 12, fill: '#71717A' }}
                tickLine={false}
                axisLine={false}
                tickFormatter={(value: number) => new Intl.NumberFormat('es-CO', { notation: 'compact' }).format(value)}
              />
              <Tooltip formatter={(value) => money(Number(value) * 100, 'COP')} labelStyle={{ color: '#18181B' }} />
              <Line type="monotone" dataKey="cop" stroke="#4F46E5" strokeWidth={2} dot={false} name="COP" />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Card>

      <div className="impay-mt-6 impay-grid impay-grid-cols-2 impay-gap-4 max-lg:impay-grid-cols-1">
        <Card>
          <h2 className="impay-border-b impay-border-line impay-px-5 impay-py-4 impay-text-sm impay-font-semibold">
            Próximas renovaciones (30 días)
          </h2>
          {data.upcoming_renewals.length === 0 ? (
            <EmptyState title="Sin renovaciones próximas" />
          ) : (
            <DataTable head={['Cliente', 'Producto', 'Vence', 'Estado']}>
              {data.upcoming_renewals.map((subscription) => (
                <tr key={subscription.uuid}>
                  <td className="impay-px-4 impay-py-3">{subscription.customer?.full_name ?? '—'}</td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">{subscription.product?.name ?? '—'}</td>
                  <td className="impay-tabular impay-px-4 impay-py-3">{date(subscription.current_period_end)}</td>
                  <td className="impay-px-4 impay-py-3">
                    <Badge status={subscription.status} label={subscription.status_label} />
                  </td>
                </tr>
              ))}
            </DataTable>
          )}
        </Card>

        <Card>
          <h2 className="impay-border-b impay-border-line impay-px-5 impay-py-4 impay-text-sm impay-font-semibold">
            Tareas de provisión manual
          </h2>
          {data.manual_tasks.length === 0 ? (
            <EmptyState title="Sin tareas pendientes" hint="Las provisiones manuales aparecerán aquí." />
          ) : (
            <DataTable head={['Cliente', 'Producto', 'Creada']}>
              {data.manual_tasks.map((subscription) => (
                <tr key={subscription.uuid}>
                  <td className="impay-px-4 impay-py-3">{subscription.customer?.full_name ?? '—'}</td>
                  <td className="impay-px-4 impay-py-3 impay-text-muted">{subscription.product?.name ?? '—'}</td>
                  <td className="impay-tabular impay-px-4 impay-py-3">{date(subscription.created_at)}</td>
                </tr>
              ))}
            </DataTable>
          )}
        </Card>
      </div>
    </div>
  );
}

function buildChartSeries(rows: DashboardMetrics['revenue_12m']): { month: string; cop: number }[] {
  const byMonth = new Map<string, number>();

  for (const row of rows) {
    if (row.currency === 'COP') {
      byMonth.set(row.month, (byMonth.get(row.month) ?? 0) + row.amount / 100);
    }
  }

  return [...byMonth.entries()].map(([month, cop]) => ({ month, cop }));
}

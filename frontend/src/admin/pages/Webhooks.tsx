import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { api } from '@shared/api';
import { dateTime } from '@shared/format';
import type { Paginated, WebhookEvent } from '@shared/types';
import { Badge, Card, EmptyState, Select, Spinner } from '@shared/ui/primitives';
import { DataTable, Pagination } from '@shared/ui/layout';
import { toast } from '@shared/ui/toast';
import { PageHeader } from '../App';

const PER_PAGE = 20;

interface EventsResponse extends Paginated<WebhookEvent> {
  health: Record<string, string>;
}

interface LogRow {
  id: number;
  level: string;
  channel: string;
  message: string;
  context: string | null;
  created_at: string;
}

export function WebhooksPage() {
  const [tab, setTab] = useState<'events' | 'logs'>('events');

  return (
    <div>
      <PageHeader title="Webhooks & Logs" />

      <div className="impay-mb-4 impay-flex impay-gap-1 impay-rounded-control impay-border impay-border-line impay-bg-white impay-p-1 impay-w-fit">
        {(['events', 'logs'] as const).map((tabKey) => (
          <button
            key={tabKey}
            onClick={() => setTab(tabKey)}
            className={`impay-rounded-[7px] impay-px-4 impay-py-1.5 impay-text-sm impay-font-medium ${
              tab === tabKey ? 'impay-bg-accent-soft impay-text-accent' : 'impay-text-muted hover:impay-text-ink'
            }`}
          >
            {tabKey === 'events' ? 'Eventos de webhook' : 'Logs'}
          </button>
        ))}
      </div>

      {tab === 'events' ? <EventsTab /> : <LogsTab />}
    </div>
  );
}

function EventsTab() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [expanded, setExpanded] = useState<number | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['webhook-events', status, page],
    queryFn: () => api.get<EventsResponse>(`admin/webhook-events?page=${page}&per_page=${PER_PAGE}&status=${status}`),
  });

  const retry = useMutation({
    mutationFn: (id: number) => api.post(`admin/webhook-events/${id}/retry`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['webhook-events'] });
      toast('Evento reprocesado.');
    },
    onError: () => toast('No fue posible reprocesar el evento.', 'error'),
  });

  return (
    <>
      <div className="impay-mb-4 impay-flex impay-items-center impay-gap-3">
        <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
          <option value="">Todos</option>
          <option value="received">Recibido</option>
          <option value="processed">Procesado</option>
          <option value="failed">Fallido</option>
          <option value="skipped">Omitido</option>
        </Select>
        {data?.health && (
          <span className="impay-text-xs impay-text-muted">
            Último evento:{' '}
            {Object.entries(data.health)
              .map(([gateway, at]) => `${gateway} ${dateTime(at)}`)
              .join(' · ') || 'ninguno'}
          </span>
        )}
      </div>

      <Card>
        {isLoading ? (
          <Spinner />
        ) : (data?.items.length ?? 0) === 0 ? (
          <EmptyState title="Sin eventos" hint="Los webhooks de las pasarelas aparecerán aquí." />
        ) : (
          <>
            <DataTable head={['Pasarela', 'Topic', 'Estado', 'Recibido', 'Intentos', '']}>
              {data?.items.map((event) => (
                <>
                  <tr
                    key={event.id}
                    className="hover:impay-bg-canvas impay-cursor-pointer"
                    onClick={() => setExpanded(expanded === event.id ? null : event.id)}
                  >
                    <td className="impay-px-4 impay-py-3">{event.gateway}</td>
                    <td className="impay-px-4 impay-py-3 impay-font-mono impay-text-xs">{event.topic}</td>
                    <td className="impay-px-4 impay-py-3">
                      <Badge status={event.status} label={event.status} />
                    </td>
                    <td className="impay-tabular impay-px-4 impay-py-3 impay-text-muted">{dateTime(event.received_at)}</td>
                    <td className="impay-tabular impay-px-4 impay-py-3 impay-text-muted">{event.attempts}</td>
                    <td className="impay-px-4 impay-py-3 impay-text-right">
                      <button
                        onClick={(clickEvent) => {
                          clickEvent.stopPropagation();
                          retry.mutate(event.id);
                        }}
                        className="impay-inline-flex impay-items-center impay-gap-1 impay-text-xs impay-text-accent hover:impay-underline"
                      >
                        <RotateCcw size={12} /> Retry
                      </button>
                    </td>
                  </tr>
                  {expanded === event.id && (
                    <tr key={`${event.id}-payload`}>
                      <td colSpan={6} className="impay-bg-canvas impay-px-4 impay-py-3">
                        {event.error && <p className="impay-mb-2 impay-text-xs impay-text-bad">{event.error}</p>}
                        <pre className="impay-max-h-64 impay-overflow-auto impay-rounded-control impay-bg-white impay-border impay-border-line impay-p-3 impay-text-xs">
                          {JSON.stringify(event.payload, null, 2)}
                        </pre>
                      </td>
                    </tr>
                  )}
                </>
              ))}
            </DataTable>
            <Pagination page={page} total={data?.total ?? 0} perPage={PER_PAGE} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  );
}

function LogsTab() {
  const [level, setLevel] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['logs', level, page],
    queryFn: () => api.get<Paginated<LogRow>>(`admin/logs?page=${page}&per_page=50&level=${level}`),
  });

  return (
    <>
      <div className="impay-mb-4">
        <Select value={level} onChange={(e) => { setLevel(e.target.value); setPage(1); }}>
          <option value="">Todos los niveles</option>
          <option value="error">Error</option>
          <option value="warning">Warning</option>
          <option value="info">Info</option>
          <option value="debug">Debug</option>
        </Select>
      </div>
      <Card>
        {isLoading ? (
          <Spinner />
        ) : (data?.items.length ?? 0) === 0 ? (
          <EmptyState title="Sin logs" />
        ) : (
          <>
            <DataTable head={['Nivel', 'Canal', 'Mensaje', 'Fecha']}>
              {data?.items.map((log) => (
                <tr key={log.id}>
                  <td className="impay-px-4 impay-py-2.5">
                    <Badge
                      status={log.level === 'error' ? 'failed' : log.level === 'warning' ? 'pending' : 'processed'}
                      label={log.level}
                    />
                  </td>
                  <td className="impay-px-4 impay-py-2.5 impay-text-muted">{log.channel}</td>
                  <td className="impay-px-4 impay-py-2.5">{log.message}</td>
                  <td className="impay-tabular impay-px-4 impay-py-2.5 impay-text-muted">{dateTime(log.created_at)}</td>
                </tr>
              ))}
            </DataTable>
            <Pagination page={page} total={data?.total ?? 0} perPage={50} onPage={setPage} />
          </>
        )}
      </Card>
    </>
  );
}

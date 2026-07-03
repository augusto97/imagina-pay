/**
 * Formato es-CO. Los montos llegan en unidad mínima (centavos).
 */

export function money(amountMinor: number, currency: string): string {
  const units = amountMinor / 100;

  const formatted = new Intl.NumberFormat('es-CO', {
    minimumFractionDigits: currency === 'COP' && amountMinor % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(units);

  return `$ ${formatted} ${currency}`;
}

export function date(value: string | null | undefined): string {
  if (!value) return '—';

  const parsed = new Date(value.replace(' ', 'T') + 'Z');

  return new Intl.DateTimeFormat('es-CO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'America/Bogota',
  }).format(parsed);
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return '—';

  const parsed = new Date(value.replace(' ', 'T') + 'Z');

  return new Intl.DateTimeFormat('es-CO', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'America/Bogota',
  }).format(parsed);
}

export const intervalLabels: Record<string, string> = {
  one_time: 'Pago único',
  month: '/ mes',
  year: '/ año',
};

export const statusLabels: Record<string, string> = {
  pending: 'Pendiente',
  active: 'Activa',
  past_due: 'Pago vencido',
  paused: 'Pausada',
  cancelled: 'Cancelada',
  expired: 'Vencida',
  paid: 'Pagado',
  failed: 'Fallido',
  refunded: 'Reembolsado',
  approved: 'Aprobado',
  rejected: 'Rechazado',
  charged_back: 'Contracargo',
  received: 'Recibido',
  processed: 'Procesado',
  skipped: 'Omitido',
  draft: 'Borrador',
  archived: 'Archivado',
  open: 'Abierto',
};

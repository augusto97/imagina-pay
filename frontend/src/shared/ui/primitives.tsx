import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';

/** Primitivas de UI estilo shadcn: precisas, aireadas, sin ruido. */

function cx(...classes: (string | false | undefined)[]): string {
  return classes.filter(Boolean).join(' ');
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Button({
  variant = 'primary',
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant }) {
  const variants: Record<ButtonVariant, string> = {
    primary: 'impay-bg-accent impay-text-white hover:impay-bg-accent-hover',
    secondary: 'impay-bg-white impay-text-ink impay-border impay-border-line hover:impay-bg-canvas',
    ghost: 'impay-text-muted hover:impay-text-ink hover:impay-bg-canvas',
    danger: 'impay-bg-white impay-text-bad impay-border impay-border-line hover:impay-bg-red-50',
  };

  return (
    <button
      className={cx(
        'impay-inline-flex impay-items-center impay-gap-2 impay-rounded-control impay-px-4 impay-py-2',
        'impay-text-sm impay-font-medium impay-transition-colors disabled:impay-opacity-50 disabled:impay-pointer-events-none',
        'focus-visible:impay-outline-none focus-visible:impay-ring-2 focus-visible:impay-ring-accent/40 focus-visible:impay-ring-offset-1',
        variants[variant],
        className,
      )}
      {...props}
    />
  );
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      className={cx(
        'impay-h-10 impay-w-full impay-rounded-control impay-border impay-border-line impay-bg-white impay-px-3',
        'impay-text-sm impay-text-ink placeholder:impay-text-muted focus:impay-outline-none',
        'focus:impay-ring-2 focus:impay-ring-accent/30 focus:impay-border-accent',
        className,
      )}
      {...props}
    />
  );
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select
      className={cx(
        'impay-h-10 impay-rounded-control impay-border impay-border-line impay-bg-white impay-px-3',
        'impay-text-sm impay-text-ink focus:impay-outline-none focus:impay-ring-2 focus:impay-ring-accent/30',
        className,
      )}
      {...props}
    >
      {children}
    </select>
  );
}

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <div className={cx('impay-bg-white impay-border impay-border-line impay-rounded-card impay-shadow-card', className)}>
      {children}
    </div>
  );
}

const badgeTones: Record<string, string> = {
  active: 'impay-bg-emerald-50 impay-text-ok',
  paid: 'impay-bg-emerald-50 impay-text-ok',
  approved: 'impay-bg-emerald-50 impay-text-ok',
  processed: 'impay-bg-emerald-50 impay-text-ok',
  pending: 'impay-bg-amber-50 impay-text-warn',
  past_due: 'impay-bg-amber-50 impay-text-warn',
  paused: 'impay-bg-amber-50 impay-text-warn',
  received: 'impay-bg-amber-50 impay-text-warn',
  open: 'impay-bg-amber-50 impay-text-warn',
  draft: 'impay-bg-zinc-100 impay-text-muted',
  cancelled: 'impay-bg-zinc-100 impay-text-muted',
  archived: 'impay-bg-zinc-100 impay-text-muted',
  skipped: 'impay-bg-zinc-100 impay-text-muted',
  expired: 'impay-bg-red-50 impay-text-bad',
  failed: 'impay-bg-red-50 impay-text-bad',
  rejected: 'impay-bg-red-50 impay-text-bad',
  refunded: 'impay-bg-zinc-100 impay-text-muted',
  charged_back: 'impay-bg-red-50 impay-text-bad',
};

export function Badge({ status, label }: { status: string; label: string }) {
  return (
    <span
      className={cx(
        'impay-inline-flex impay-items-center impay-rounded-full impay-px-2.5 impay-py-0.5 impay-text-xs impay-font-medium',
        badgeTones[status] ?? 'impay-bg-zinc-100 impay-text-muted',
      )}
    >
      {label}
    </span>
  );
}

export function Spinner() {
  return (
    <div className="impay-flex impay-justify-center impay-py-16">
      <div className="impay-h-6 impay-w-6 impay-animate-spin impay-rounded-full impay-border-2 impay-border-line impay-border-t-accent" />
    </div>
  );
}

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
  return (
    <div className="impay-py-16 impay-text-center">
      <p className="impay-text-sm impay-font-medium impay-text-ink">{title}</p>
      {hint && <p className="impay-mt-1 impay-text-sm impay-text-muted">{hint}</p>}
    </div>
  );
}

export function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <label className="impay-block">
      <span className="impay-mb-1.5 impay-block impay-text-sm impay-font-medium impay-text-ink">{label}</span>
      {children}
      {error && <span className="impay-mt-1 impay-block impay-text-xs impay-text-bad">{error}</span>}
    </label>
  );
}

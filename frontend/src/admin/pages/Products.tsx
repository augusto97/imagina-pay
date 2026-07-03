import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, ExternalLink, Plus } from 'lucide-react';
import { useState } from 'react';
import { api, ApiError } from '@shared/api';
import { intervalLabels, statusLabels } from '@shared/format';
import type { Product } from '@shared/types';
import { Badge, Button, Card, EmptyState, Spinner } from '@shared/ui/primitives';
import { toast } from '@shared/ui/toast';
import { PageHeader } from '../App';
import { ProductDrawer } from './ProductDrawer';

const typeLabels: Record<string, string> = {
  one_time: 'Pago único',
  subscription: 'Suscripción',
  annual_hybrid: 'Anual híbrido',
};

export function ProductsPage() {
  const queryClient = useQueryClient();
  // Se guarda el uuid (no el objeto): el drawer siempre lee el producto
  // fresco del cache y refleja al instante los precios recién creados.
  const [editing, setEditing] = useState<string | 'new' | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['products'],
    queryFn: () => api.get<{ items: Product[] }>('admin/products'),
  });

  const editingProduct =
    editing !== null && editing !== 'new'
      ? (data?.items ?? []).find((candidate) => candidate.uuid === editing) ?? null
      : null;

  const archive = useMutation({
    mutationFn: (product: Product) =>
      api.put(`admin/products/${product.uuid}`, {
        status: product.status === 'archived' ? 'active' : 'archived',
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['products'] });
      toast('Producto actualizado.');
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  if (isLoading) return <Spinner />;

  const products = data?.items ?? [];

  return (
    <div>
      <PageHeader
        title="Productos"
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus size={16} /> Nuevo producto
          </Button>
        }
      />

      {products.length === 0 ? (
        <Card>
          <EmptyState title="Aún no hay productos" hint="Crea tu primer producto para empezar a vender." />
        </Card>
      ) : (
        <div className="impay-grid impay-grid-cols-3 impay-gap-4 max-xl:impay-grid-cols-2 max-lg:impay-grid-cols-1">
          {products.map((product) => (
            <Card key={product.uuid} className="impay-flex impay-flex-col impay-p-5">
              <div className="impay-flex impay-items-start impay-justify-between">
                <div>
                  <h3 className="impay-font-semibold impay-tracking-tight">{product.name}</h3>
                  <p className="impay-mt-0.5 impay-text-xs impay-text-muted">
                    {typeLabels[product.type]} · /{product.slug}
                  </p>
                </div>
                <Badge status={product.status} label={statusLabels[product.status] ?? product.status} />
              </div>

              <div className="impay-mt-4 impay-flex-1 impay-space-y-1.5">
                {product.prices.filter((price) => price.status === 'active').map((price) => (
                  <p key={price.uuid} className="impay-tabular impay-text-sm">
                    {price.formatted}{' '}
                    <span className="impay-text-muted">{intervalLabels[price.interval]}</span>
                  </p>
                ))}
                {product.prices.length === 0 && (
                  <p className="impay-text-sm impay-text-muted">Sin precios configurados</p>
                )}
              </div>

              {product.status === 'active' && product.checkout_url && (
                <div className="impay-mt-4 impay-rounded-control impay-border impay-border-line impay-bg-canvas impay-px-3 impay-py-2">
                  <p className="impay-mb-1 impay-text-xs impay-font-medium impay-text-muted">Link de venta</p>
                  <div className="impay-flex impay-items-center impay-gap-2">
                    <button
                      className="impay-min-w-0 impay-flex-1 impay-truncate impay-text-left impay-font-mono impay-text-xs impay-text-ink hover:impay-text-accent"
                      title="Copiar link"
                      onClick={() => {
                        void navigator.clipboard.writeText(product.checkout_url ?? '');
                        toast('Link de venta copiado. Compártelo o úsalo en un botón.');
                      }}
                    >
                      {product.checkout_url}
                    </button>
                    <button
                      className="impay-text-muted hover:impay-text-accent"
                      title="Copiar link"
                      onClick={() => {
                        void navigator.clipboard.writeText(product.checkout_url ?? '');
                        toast('Link de venta copiado.');
                      }}
                    >
                      <Copy size={14} />
                    </button>
                    <a
                      href={product.checkout_url}
                      target="_blank"
                      rel="noreferrer"
                      className="impay-text-muted hover:impay-text-accent"
                      title="Abrir checkout"
                    >
                      <ExternalLink size={14} />
                    </a>
                  </div>
                  <button
                    className="impay-mt-1.5 impay-font-mono impay-text-[11px] impay-text-muted hover:impay-text-accent"
                    title="Copiar shortcode para insertar un botón de compra en cualquier página"
                    onClick={() => {
                      void navigator.clipboard.writeText(`[impay_boton producto="${product.slug}"]`);
                      toast('Shortcode copiado. Pégalo en cualquier página o builder.');
                    }}
                  >
                    [impay_boton producto="{product.slug}"]
                  </button>
                </div>
              )}
              {product.status !== 'active' && (
                <p className="impay-mt-4 impay-rounded-control impay-bg-amber-50 impay-px-3 impay-py-2 impay-text-xs impay-text-warn">
                  Actívalo (Editar → Estado: Activo) para obtener su link de venta.
                </p>
              )}

              <div className="impay-mt-4 impay-flex impay-gap-2 impay-border-t impay-border-line impay-pt-4">
                <Button variant="secondary" onClick={() => setEditing(product.uuid)}>
                  Editar
                </Button>
                <Button variant="ghost" onClick={() => archive.mutate(product)}>
                  {product.status === 'archived' ? 'Reactivar' : 'Archivar'}
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <ProductDrawer product={editingProduct} open={editing !== null} onClose={() => setEditing(null)} />
    </div>
  );
}

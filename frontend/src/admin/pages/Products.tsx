import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
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
  const [editing, setEditing] = useState<Product | 'new' | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['products'],
    queryFn: () => api.get<{ items: Product[] }>('admin/products'),
  });

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

              <div className="impay-mt-4 impay-flex impay-gap-2 impay-border-t impay-border-line impay-pt-4">
                <Button variant="secondary" onClick={() => setEditing(product)}>
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

      <ProductDrawer
        product={editing === 'new' ? null : editing}
        open={editing !== null}
        onClose={() => setEditing(null)}
      />
    </div>
  );
}

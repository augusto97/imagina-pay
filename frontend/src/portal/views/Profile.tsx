import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api, ApiError } from '@shared/api';
import type { Customer } from '@shared/types';
import { Button, Card, Field, Input, Select, Spinner } from '@shared/ui/primitives';
import { toast } from '@shared/ui/toast';

export function ProfileView() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    full_name: '',
    company: '',
    tax_id_type: 'CC',
    tax_id: '',
    country: 'CO',
    phone: '',
  });

  const { data, isLoading } = useQuery({
    queryKey: ['me'],
    queryFn: () => api.get<{ customer: Customer }>('me'),
  });

  useEffect(() => {
    if (data) {
      const { customer } = data;
      setForm({
        full_name: customer.full_name,
        company: customer.company ?? '',
        tax_id_type: customer.tax_id_type ?? 'CC',
        tax_id: customer.tax_id ?? '',
        country: customer.country,
        phone: customer.phone ?? '',
      });
    }
  }, [data]);

  const save = useMutation({
    mutationFn: () => api.put('me', form),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['me'] });
      toast('Perfil actualizado.');
    },
    onError: (error) => toast(error instanceof ApiError ? error.message : 'Error inesperado.', 'error'),
  });

  if (isLoading || !data) return <Spinner />;

  const set = (field: keyof typeof form) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((current) => ({ ...current, [field]: event.target.value }));

  return (
    <Card className="impay-max-w-xl impay-p-6">
      <div className="impay-space-y-4">
        <Field label="Correo electrónico">
          <Input value={data.customer.email} disabled className="impay-bg-canvas" />
        </Field>
        <Field label="Nombre completo">
          <Input value={form.full_name} onChange={set('full_name')} />
        </Field>
        <Field label="Empresa">
          <Input value={form.company} onChange={set('company')} />
        </Field>
        <div className="impay-grid impay-grid-cols-3 impay-gap-3">
          <Field label="Tipo doc.">
            <Select value={form.tax_id_type} onChange={set('tax_id_type')} className="impay-w-full">
              {['CC', 'NIT', 'CE', 'PAS', 'RUT', 'OTRO'].map((type) => (
                <option key={type}>{type}</option>
              ))}
            </Select>
          </Field>
          <div className="impay-col-span-2">
            <Field label="Número de documento">
              <Input value={form.tax_id} onChange={set('tax_id')} />
            </Field>
          </div>
        </div>
        <div className="impay-grid impay-grid-cols-2 impay-gap-3">
          <Field label="País (código)">
            <Input value={form.country} onChange={set('country')} maxLength={2} />
          </Field>
          <Field label="Teléfono">
            <Input value={form.phone} onChange={set('phone')} />
          </Field>
        </div>

        <Button onClick={() => save.mutate()} disabled={save.isPending}>
          Guardar cambios
        </Button>
      </div>
    </Card>
  );
}

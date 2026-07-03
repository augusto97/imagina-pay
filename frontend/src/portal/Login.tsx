import { useState } from 'react';
import { api, ApiError } from '@shared/api';
import { Button, Card, Field, Input } from '@shared/ui/primitives';

export function LoginForm() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async () => {
    setLoading(true);
    setError('');

    try {
      await api.post('portal/login', { email, password });
      window.location.reload();
    } catch (loginError) {
      setLoading(false);
      setError(loginError instanceof ApiError ? loginError.message : 'No fue posible iniciar sesión.');
    }
  };

  return (
    <Card className="impay-p-8">
      <h1 className="impay-text-xl impay-font-semibold impay-tracking-tight">Inicia sesión</h1>
      <p className="impay-mt-1 impay-text-sm impay-text-muted">
        Accede con el correo con el que realizaste tu compra.
      </p>

      <div className="impay-mt-6 impay-space-y-4">
        <Field label="Correo electrónico">
          <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
        </Field>
        <Field label="Contraseña">
          <Input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            onKeyDown={(e) => e.key === 'Enter' && void submit()}
          />
        </Field>

        {error && <p className="impay-text-sm impay-text-bad">{error}</p>}

        <Button className="impay-w-full impay-justify-center" onClick={() => void submit()} disabled={loading || !email || !password}>
          {loading ? 'Entrando…' : 'Entrar'}
        </Button>

        <p className="impay-text-center impay-text-xs impay-text-muted">
          ¿Olvidaste tu contraseña?{' '}
          <a href="/wp-login.php?action=lostpassword" className="impay-text-accent hover:impay-underline">
            Recupérala aquí
          </a>
        </p>
      </div>
    </Card>
  );
}

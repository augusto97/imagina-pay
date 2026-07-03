import { useEffect, useState } from 'react';

/** Routing por hash (#/suscripciones): suficiente para un SPA de admin. */

export function useHashRoute(defaultRoute: string): [string, (route: string) => void] {
  const read = () => window.location.hash.replace(/^#\/?/, '') || defaultRoute;
  const [route, setRoute] = useState(read);

  useEffect(() => {
    const onChange = () => setRoute(read());
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const navigate = (next: string) => {
    window.location.hash = '/' + next;
  };

  return [route, navigate];
}

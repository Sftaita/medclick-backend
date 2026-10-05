import { useCallback, useEffect, useRef, useState } from 'react';

/** Charge une donnée asynchrone. `data` reste undefined pendant le chargement → afficher un skeleton, jamais un faux 0. */
export function useAsync<T>(fn: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | undefined>(undefined);
  const [error, setError] = useState<Error | null>(null);
  const [loading, setLoading] = useState(true);
  const alive = useRef(true);

  const run = useCallback(() => {
    setLoading(true);
    setError(null);
    return fn()
      .then((d) => { if (alive.current) setData(d); return d; })
      .catch((e: Error) => { if (alive.current) setError(e); throw e; })
      .finally(() => { if (alive.current) setLoading(false); });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  useEffect(() => {
    alive.current = true;
    run().catch(() => undefined);
    return () => { alive.current = false; };
  }, [run]);

  return { data, error, loading, reload: run, setData };
}

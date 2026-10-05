import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { ErrorState, Skeleton, TopBar } from '@/components/ui';

/** Lecture des conditions d'utilisation (`GET /api/terms-conditions`), accessible sans connexion. */
export default function TermsPage() {
  const { data, error, reload } = useAsync(() => api.getTerms(), []);
  return (
    <div className="screen">
      <TopBar title="Conditions d'utilisation" center />
      <div className="screen-body narrow">
        {error && <ErrorState error={error} onRetry={reload} />}
        {!data ? <Skeleton h={300} r={14} /> : (
          <>
            <span className="muted small">Version publiée le {new Date(data.publishedAt + 'T12:00:00').toLocaleDateString('fr-BE', { day: 'numeric', month: 'long', year: 'numeric' })}</span>
            <div className="card card--outline" style={{ fontSize: 14, lineHeight: 1.6, whiteSpace: 'pre-line' }}>{data.content}</div>
          </>
        )}
      </div>
    </div>
  );
}

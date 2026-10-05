import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, Skeleton, TopBar } from '@/components/ui';
import { YEAR_LABEL } from '@/data/referentiel';

/** Mes années de formation + export du carnet de stage officiel (`GET /api/excel2/{year}`). */
export default function YearsPage() {
  const { data, error, reload } = useAsync(() => api.listYears(), []);
  const { data: profile } = useAsync(() => api.getProfile(), []);
  const [exporting, setExporting] = useState<string | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const current = profile?.currentYear?.id;

  const exportYear = async (id: string) => {
    setExporting(id);
    setExportError(null);
    try { await api.exportLogbook(id); } catch (e) { setExportError((e as Error).message); } finally { setExporting(null); }
  };

  return (
    <div className="screen">
      <TopBar title="Mes années de formation" center />
      <div className="screen-body narrow" style={{ gap: 12 }}>
        <p className="muted" style={{ fontSize: 13, lineHeight: 1.45 }}>
          Chaque année regroupe ses interventions, consultations, gardes, formations et chirurgiens. Le carnet officiel s'exporte année par année.
        </p>
        {error && <ErrorState error={error} onRetry={reload} />}
        {exportError && <p className="banner banner--danger" role="alert">{exportError}</p>}
        {!data ? <><Skeleton h={150} r={18} /><Skeleton h={150} r={18} /></> : data.map((y) => {
          const isCurrent = y.id === current;
          return (
            <div key={y.id} className={'card ' + (isCurrent ? '' : 'card--outline')} style={{ gap: 12, padding: 16, border: isCurrent ? '1.5px solid var(--mc-primary)' : undefined }}>
              <div className="row" style={{ justifyContent: 'space-between' }}>
                <span className="stack" style={{ gap: 2 }}><strong style={{ fontSize: 17 }}>{YEAR_LABEL(y.yearOfFormation)}</strong><span className="muted small">{y.hospital}</span></span>
                <span className={'delta ' + (isCurrent ? '' : 'delta--neutral')} style={{ fontSize: 11 }}>{isCurrent ? 'En cours' : 'Terminée'}</span>
              </div>
              <span className="muted small">Début : {new Date(y.dateOfStart + 'T12:00:00').toLocaleDateString('fr-BE', { day: 'numeric', month: 'short', year: 'numeric' })} · Maître de stage : {y.master}</span>
              <div className="row" style={{ gap: 8 }}>
                <button type="button" className={'btn btn--sm grow ' + (isCurrent ? 'btn--primary' : 'btn--secondary')} disabled={exporting === y.id} onClick={() => exportYear(y.id)}>
                  {exporting === y.id ? <><span className="spinner" />Export…</> : <><Icon name="download" size={16} stroke={2.2} />Exporter le carnet (Excel)</>}
                </button>
                <Link to={`/annees/${y.id}`} className="btn btn--secondary btn--sm">Modifier</Link>
              </div>
            </div>
          );
        })}
        <span className="muted small center">Le récapitulatif des interventions du carnet couvre toutes vos années.</span>
      </div>
      <div className="bottom-action">
        <Link to="/annees/nouvelle" className="btn btn--primary"><Icon name="plus" size={18} stroke={2.6} />Ajouter une année</Link>
      </div>
    </div>
  );
}

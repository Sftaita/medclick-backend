import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ErrorState, Skeleton, TopBar } from '@/components/ui';
import { surgeonName } from '@/components/CommonFieldsForm';
import { YEAR_LABEL } from '@/data/referentiel';

/** Chirurgiens (superviseurs) d'une année (`GET /api/list/{year}`), maître de stage en premier. */
export default function SurgeonsPage() {
  const { data: years } = useAsync(() => api.listYears(), []);
  const { data: profile } = useAsync(() => api.getProfile(), []);
  const [yearId, setYearId] = useState('');
  useEffect(() => { if (!yearId && profile?.currentYear) setYearId(profile.currentYear.id); }, [profile, yearId]);
  const { data, error, reload } = useAsync(() => (yearId ? api.listSurgeons(yearId) : Promise.resolve(undefined)), [yearId]);
  const list = [...(data ?? [])].sort((a, b) => Number(b.boss) - Number(a.boss));

  return (
    <div className="screen">
      <TopBar title="Mes chirurgiens" center />
      <div className="screen-body narrow" style={{ gap: 14 }}>
        <label className="field-row" style={{ border: '1px solid var(--mc-border)', borderRadius: 14, padding: '0 12px', background: '#fff' }}>
          <Icon name="cap" size={18} />
          <span className="field-label" style={{ width: 60 }}>Année</span>
          <select className="select" style={{ border: 0 }} value={yearId} onChange={(e) => setYearId(e.target.value)}>
            {(years ?? []).map((y) => <option key={y.id} value={y.id}>{YEAR_LABEL(y.yearOfFormation)} · {y.hospital}</option>)}
          </select>
        </label>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!data ? <Skeleton h={180} r={14} /> : (
          <div className="list">
            {list.map((s) => (
              <Link key={s.id} to={`/chirurgiens/${s.id}?annee=${yearId}`} className="list-row">
                <span className="avatar" style={{ background: s.boss ? 'var(--mc-primary)' : 'var(--mc-primary-soft)', color: s.boss ? '#fff' : 'var(--mc-primary)', fontSize: 14 }}>
                  {(s.firstName[0] ?? '') + (s.lastName[0] ?? '')}
                </span>
                <span className="grow stack" style={{ gap: 2 }}><span style={{ fontSize: 15, fontWeight: 700 }}>{surgeonName(s)}</span><span className="list-row-sub">{s.firstName}</span></span>
                {s.boss && <span className="delta" style={{ fontSize: 11 }}>Maître de stage</span>}
                <Icon name="chevR" size={18} />
              </Link>
            ))}
            {list.length === 0 && <p className="muted">Aucun chirurgien pour cette année. Ajoutez-en un pour pouvoir encoder vos interventions.</p>}
          </div>
        )}
        <span className="muted small" style={{ lineHeight: 1.45 }}>Ces chirurgiens apparaissent dans « Aidé par » et « Superviseur » à l'encodage, et dans le carnet de stage (maître de stage en premier).</span>
      </div>
      <div className="bottom-action">
        <Link to={`/chirurgiens/nouveau?annee=${yearId}`} className="btn btn--primary"><Icon name="plus" size={18} stroke={2.6} />Ajouter un chirurgien</Link>
      </div>
    </div>
  );
}

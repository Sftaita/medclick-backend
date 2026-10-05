import { useEffect } from 'react';
import { Icon } from './Icon';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { YEAR_LABEL } from '@/data/referentiel';

/** Sélecteur d'année de formation. Sans valeur, choisit l'année en cours. */
export function YearSelect({ value, onChange }: { value: string; onChange: (id: string) => void }) {
  const { data: years } = useAsync(() => api.listYears(), []);
  const { data: profile } = useAsync(() => api.getProfile(), []);
  useEffect(() => { if (!value && profile?.currentYear) onChange(profile.currentYear.id); }, [value, profile, onChange]);
  return (
    <label className="field-row" style={{ borderBottom: 0 }}>
      <Icon name="cap" size={18} />
      <span className="field-label">Année</span>
      <select className="select" value={value} onChange={(e) => onChange(e.target.value)}>
        {(years ?? []).map((y) => <option key={y.id} value={y.id}>{YEAR_LABEL(y.yearOfFormation)} · {y.hospital}</option>)}
      </select>
    </label>
  );
}

/** Date avec raccourcis Aujourd'hui / Hier / Avant-hier. */
export function DateQuick({ value, onChange, today }: { value: string; onChange: (iso: string) => void; today: string }) {
  const minus = (n: number) => {
    const d = new Date(today + 'T12:00:00');
    d.setDate(d.getDate() - n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };
  const quick = [{ label: "Aujourd'hui", iso: today }, { label: 'Hier', iso: minus(1) }, { label: 'Avant-hier', iso: minus(2) }];
  return (
    <div className="field-row" style={{ flexWrap: 'wrap', paddingBlock: 8 }}>
      <Icon name="calendar" size={18} />
      <span className="field-label">Date</span>
      <span className="row grow" style={{ gap: 6, flexWrap: 'wrap' }}>
        {quick.map((q) => <button key={q.iso} type="button" className="chip" aria-pressed={value === q.iso} onClick={() => onChange(q.iso)}>{q.label}</button>)}
        <input className="input" type="date" aria-label="Autre date" max={today} value={value} onChange={(e) => e.target.value && onChange(e.target.value)} style={{ flex: '1 1 140px', height: 36 }} />
      </span>
    </div>
  );
}

import { Icon } from './Icon';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { YEAR_LABEL } from '@/data/referentiel';
import { addDaysISO, todayISO } from '@/lib/format';
import type { CommonFields } from '@/types';

export const surgeonName = (s: { lastName: string }) => `Dr ${s.lastName}`;

/**
 * Date, année et superviseur : définis une seule fois pour tout un lot ou une journée.
 * `supervisorLabel` = null → aucun superviseur (1re main solo).
 */
export function CommonFieldsForm({ value, onChange, supervisorLabel, supervisorHint }: {
  value: CommonFields; onChange: (v: CommonFields) => void; supervisorLabel: string | null; supervisorHint?: string;
}) {
  const t = todayISO();
  const quick = [
    { label: "Aujourd'hui", iso: t },
    { label: 'Hier', iso: addDaysISO(t, -1) },
    { label: 'Avant-hier', iso: addDaysISO(t, -2) },
  ];
  const set = (p: Partial<CommonFields>) => onChange({ ...value, ...p });
  const { data: years } = useAsync(() => api.listYears(), []);
  const { data: surgeons } = useAsync(() => api.listSurgeons(value.yearId), [value.yearId]);
  return (
    <div className="list">
      <div className="field-row" style={{ flexWrap: 'wrap', paddingBlock: 8 }}>
        <Icon name="calendar" size={18} />
        <span className="field-label">Date</span>
        <span className="row grow" style={{ gap: 6, flexWrap: 'wrap' }}>
          {quick.map((q) => (
            <button key={q.iso} type="button" className="chip" aria-pressed={value.date === q.iso} onClick={() => set({ date: q.iso })}>{q.label}</button>
          ))}
          <input className="input" type="date" aria-label="Autre date" max={t} value={value.date}
            onChange={(e) => e.target.value && set({ date: e.target.value })} style={{ flex: '1 1 140px', height: 36 }} />
        </span>
      </div>
      {supervisorLabel && (
        <label className="field-row">
          <Icon name="user" size={18} />
          <span className="field-label">{supervisorLabel}</span>
          <select className="select" value={value.supervisorId ?? ''} onChange={(e) => set({ supervisorId: e.target.value || undefined })}>
            <option value="">Choisir…</option>
            {(surgeons ?? []).map((s) => <option key={s.id} value={s.id}>{surgeonName(s)}{s.boss ? ' (maître de stage)' : ''}</option>)}
          </select>
        </label>
      )}
      {supervisorHint && <span className="muted small" style={{ padding: '6px 0 4px 30px', lineHeight: 1.4 }}>{supervisorHint}</span>}
      <label className="field-row" style={{ borderBottom: 0 }}>
        <Icon name="cap" size={18} />
        <span className="field-label">Année</span>
        <select className="select" value={value.yearId} onChange={(e) => set({ yearId: e.target.value, supervisorId: undefined })}>
          {(years ?? []).map((y) => <option key={y.id} value={y.id}>{YEAR_LABEL(y.yearOfFormation)} · {y.hospital}</option>)}
        </select>
      </label>
    </div>
  );
}

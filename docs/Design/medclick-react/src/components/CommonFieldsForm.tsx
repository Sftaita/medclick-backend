import { Icon } from './Icon';
import { SURGEONS, TRAINING_YEARS } from '@/data/actes';
import { addDaysISO, todayISO, yearLabel } from '@/lib/format';
import type { CommonFields } from '@/types';

/** Date, chirurgien, année, nomenclature : définis une seule fois pour tout un lot ou une journée. */
export function CommonFieldsForm({ value, onChange, showNomenclature = true }: {
  value: CommonFields; onChange: (v: CommonFields) => void; showNomenclature?: boolean;
}) {
  const t = todayISO();
  const quick = [
    { label: "Aujourd'hui", iso: t },
    { label: 'Hier', iso: addDaysISO(t, -1) },
    { label: 'Avant-hier', iso: addDaysISO(t, -2) },
  ];
  const set = (p: Partial<CommonFields>) => onChange({ ...value, ...p });
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
      <label className="field-row">
        <Icon name="user" size={18} />
        <span className="field-label">Chirurgien</span>
        <select className="select" value={value.surgeon} onChange={(e) => set({ surgeon: e.target.value })}>
          {SURGEONS.map((s) => <option key={s}>{s}</option>)}
        </select>
      </label>
      <label className="field-row">
        <Icon name="cap" size={18} />
        <span className="field-label">Année</span>
        <select className="select" value={value.trainingYear} onChange={(e) => set({ trainingYear: Number(e.target.value) })}>
          {TRAINING_YEARS.map((y) => <option key={y} value={y}>{yearLabel(y)}</option>)}
        </select>
      </label>
      {showNomenclature && (
        <label className="field-row" style={{ borderBottom: 0 }}>
          <Icon name="search" size={18} />
          <span className="field-label">Nomenclature</span>
          <input className="input" placeholder="Optionnel — rechercher un code" value={value.nomenclature ?? ''}
            onChange={(e) => set({ nomenclature: e.target.value || undefined })} />
        </label>
      )}
    </div>
  );
}

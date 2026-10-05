import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActeIcon, CodeBadge, ErrorState, Skeleton, TopBar } from '@/components/ui';

/** Raccourcis vers la nomenclature (`/api/favorites/*`) : renommer, retirer, ajouter. */
export default function FavoritesPage() {
  const { data, error, reload } = useAsync(() => api.listFavorites(), []);
  const [editing, setEditing] = useState<{ id: string; shortcut: string } | null>(null);

  const rename = async () => {
    if (!editing?.shortcut.trim()) return;
    await api.renameFavorite(editing.id, editing.shortcut.trim());
    setEditing(null);
    reload().catch(() => undefined);
  };
  const remove = async (id: string) => { await api.removeFavorite(id); reload().catch(() => undefined); };

  return (
    <div className="screen">
      <TopBar title="Mes favoris" center />
      <div className="screen-body narrow" style={{ gap: 12 }}>
        <p className="muted" style={{ fontSize: 13, lineHeight: 1.45 }}>Vos raccourcis vers la nomenclature : ils s'affichent en premier quand vous ajoutez une intervention.</p>
        {error && <ErrorState error={error} onRetry={reload} />}
        {!data ? <Skeleton h={240} r={14} /> : (
          <div className="list">
            {data.map((f) => editing?.id === f.id ? (
              <form key={f.id} className="card card--tint" style={{ gap: 10, margin: '4px 0' }} onSubmit={(e) => { e.preventDefault(); rename(); }}>
                <div className="row">
                  <ActeIcon acte={f.acte} />
                  <label className="grow fl"><span>Nom du raccourci</span><input className="input" autoFocus value={editing.shortcut} onChange={(e) => setEditing({ id: f.id, shortcut: e.target.value })} style={{ height: 40 }} /></label>
                </div>
                <span className="muted small row" style={{ gap: 6, flexWrap: 'wrap' }}>{f.acte.name} <CodeBadge code={f.acte.code} /></span>
                <div className="row" style={{ justifyContent: 'flex-end', gap: 8 }}>
                  <button type="button" className="btn btn--ghost btn--sm" onClick={() => setEditing(null)}>Annuler</button>
                  <button type="submit" className="btn btn--primary btn--sm">Enregistrer</button>
                </div>
              </form>
            ) : (
              <div key={f.id} className="list-row" style={{ minHeight: 66 }}>
                <ActeIcon acte={f.acte} />
                <span className="grow stack" style={{ gap: 3 }}>
                  <strong style={{ fontSize: 15 }}>{f.shortcut}</strong>
                  <span className="list-row-sub row" style={{ gap: 6, flexWrap: 'wrap' }}>{f.acte.name} <CodeBadge code={f.acte.code} /></span>
                </span>
                <button type="button" className="icon-btn" style={{ color: 'var(--mc-muted)' }} aria-label={`Renommer ${f.shortcut}`} onClick={() => setEditing({ id: f.id, shortcut: f.shortcut })}><Icon name="pen" size={18} /></button>
                <button type="button" className="icon-btn" style={{ color: 'var(--mc-faint)' }} aria-label={`Retirer ${f.shortcut} des favoris`} onClick={() => remove(f.id)}><Icon name="trash" size={18} /></button>
              </div>
            ))}
            {data.length === 0 && <p className="muted">Aucun favori. Ajoutez vos interventions les plus fréquentes depuis la nomenclature.</p>}
          </div>
        )}
      </div>
      <div className="bottom-action">
        <Link to="/nomenclature?pour=favori" className="btn btn--primary"><Icon name="search" size={18} />Ajouter depuis la nomenclature</Link>
      </div>
    </div>
  );
}

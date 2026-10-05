import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { ActeIcon, CodeBadge, ErrorState, Skeleton, TopBar } from '@/components/ui';
import { ORTHO_TYPE_LABEL, REGION_LABEL, REGIONS, SPECIALITIES, SPECIALITY_SHORT } from '@/data/referentiel';
import type { Acte, OrthoType, Region, Speciality } from '@/types';

/**
 * Recherche dans la nomenclature INAMI (`GET /api/nomenclature/{speciality}`, filtrée côté client).
 * `?pour=favori` : l'écran sert à ajouter un favori au lieu de choisir l'acte d'une intervention.
 */
export default function NomenclaturePage() {
  const nav = useNavigate();
  const [params] = useSearchParams();
  const forFavorite = params.get('pour') === 'favori';
  const [speciality, setSpeciality] = useState<Speciality>('ortho');
  const [orthoType, setOrthoType] = useState<OrthoType>('elective');
  const [region, setRegion] = useState<Region | undefined>(undefined);
  const [q, setQ] = useState('');
  const [selected, setSelected] = useState<Acte | null>(null);
  const isOrtho = speciality === 'ortho';
  const { data, error, reload } = useAsync(
    () => api.searchNomenclature({ speciality, orthoType: isOrtho ? orthoType : undefined, region: isOrtho ? region : undefined, q }),
    [speciality, orthoType, region, q],
  );
  const { data: favorites, reload: reloadFav } = useAsync(() => api.listFavorites(), []);
  const favOf = (a: Acte) => favorites?.find((f) => f.acte.id === a.id);

  const toggleFavorite = async (a: Acte) => {
    const f = favOf(a);
    if (f) await api.removeFavorite(f.id); else await api.addFavorite(a.id, a.name);
    reloadFav().catch(() => undefined);
  };
  const confirm = async () => {
    if (!selected) return;
    if (forFavorite) {
      if (!favOf(selected)) await api.addFavorite(selected.id, selected.name);
      nav('/favoris', { replace: true });
    } else {
      nav('/interventions/ajouter', { replace: true, state: { acte: selected } });
    }
  };

  const summary = [SPECIALITY_SHORT[speciality], isOrtho && ORTHO_TYPE_LABEL[orthoType], isOrtho && region && REGION_LABEL[region]].filter(Boolean).join(' · ');
  const regionsShown: Region[] = orthoType === 'trauma'
    ? ['shoulder', 'humerus', 'elbow', 'forearm', 'wristhand', 'pelvic', 'proximalFemur', 'midFemur', 'distalFemur', 'knee', 'limb', 'ankle', 'foot']
    : REGIONS;

  return (
    <div className="screen">
      <TopBar title="Nomenclature INAMI" />
      <div className="screen-body" style={{ gap: 14 }}>
        <label className="search">
          <Icon name="search" size={18} />
          <input type="search" placeholder="Nom ou code de l'intervention" aria-label="Rechercher dans la nomenclature" value={q} onChange={(e) => setQ(e.target.value)} />
        </label>

        <section className="stack" style={{ gap: 8 }}>
          <span className="muted small" style={{ fontWeight: 600 }}>Spécialité</span>
          <div className="chips chips--scroll" role="group" aria-label="Spécialité">
            {SPECIALITIES.map((s) => (
              <button key={s} type="button" className="chip" aria-pressed={s === speciality} onClick={() => { setSpeciality(s); setRegion(undefined); }}>{SPECIALITY_SHORT[s]}</button>
            ))}
          </div>
        </section>

        {isOrtho && (
          <>
            <div className="segmented segmented--sm" role="group" aria-label="Type d'acte orthopédique">
              {(['elective', 'trauma'] as OrthoType[]).map((t) => (
                <button key={t} type="button" aria-pressed={t === orthoType} onClick={() => { setOrthoType(t); setRegion(undefined); }}>{ORTHO_TYPE_LABEL[t]}</button>
              ))}
            </div>
            <section className="stack" style={{ gap: 8 }}>
              <span className="muted small" style={{ fontWeight: 600 }}>Région</span>
              <div className="chips chips--scroll" role="group" aria-label="Région anatomique">
                <button type="button" className="chip" aria-pressed={!region} onClick={() => setRegion(undefined)}>Toutes</button>
                {regionsShown.map((r) => (
                  <button key={r} type="button" className="chip" aria-pressed={r === region} onClick={() => setRegion(r)}>{REGION_LABEL[r]}</button>
                ))}
              </div>
            </section>
          </>
        )}

        {error && <ErrorState error={error} onRetry={reload} />}
        <section className="stack" style={{ gap: 8 }} aria-live="polite">
          {!data ? <><Skeleton h={64} r={14} /><Skeleton h={64} r={14} /></> : (
            <>
              <span className="muted small" style={{ fontWeight: 600 }}>{summary} — {data.length} résultat{data.length > 1 ? 's' : ''}</span>
              {data.map((a) => {
                const fav = !!favOf(a);
                return (
                  <label key={a.id} className={'acte ' + (selected?.id === a.id ? 'acte--on' : '')} style={{ minHeight: 64 }}>
                    <input type="radio" name="acte" className="sr-only" checked={selected?.id === a.id} onChange={() => setSelected(a)} />
                    <ActeIcon acte={a} size={18} />
                    <span className="grow stack" style={{ gap: 3 }}>
                      <span style={{ fontSize: 14, fontWeight: 700 }}>{a.name}</span>
                      <span style={{ alignSelf: 'flex-start' }}><CodeBadge code={a.code} /></span>
                    </span>
                    <button type="button" className="icon-btn" aria-pressed={fav} aria-label={fav ? `Retirer ${a.name} des favoris` : `Ajouter ${a.name} aux favoris`}
                      style={{ color: fav ? '#F0A12B' : 'var(--mc-faint)' }} onClick={(e) => { e.preventDefault(); toggleFavorite(a); }}>
                      <Icon name={fav ? 'starFilled' : 'star'} size={20} />
                    </button>
                  </label>
                );
              })}
              {data.length === 0 && <p className="muted">Aucune intervention ne correspond. Essayez une autre région ou un autre mot.</p>}
            </>
          )}
        </section>
      </div>
      <div className="bottom-action">
        <button type="button" className="btn btn--primary" disabled={!selected} onClick={confirm}>
          {forFavorite ? 'Ajouter aux favoris' : 'Choisir cette intervention'}<Icon name="arrowR" size={18} stroke={2.4} />
        </button>
        <span className="muted small center">L'étoile ajoute l'acte à vos favoris pour l'encoder plus vite.</span>
      </div>
    </div>
  );
}

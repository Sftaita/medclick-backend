import { useEffect, useState, type ReactNode } from 'react';
import { api } from '@/api';
import { usePartner } from '@/hooks/usePartner';
import { Icon } from '@/components/Icon';
import { Logo, SponsorMark, Wordmark } from '@/components/ui';
import { todayISO } from '@/lib/format';
import type { Partner } from '@/types';
import './AdminPartnerPage.css';

const EMPTY: Partner = {
  name: '', tagline: '', about: '', url: '', startDate: todayISO(),
  points: [
    { icon: 'bulb', label: '' }, { icon: 'book', label: '' }, { icon: 'users', label: '' }, { icon: 'target', label: '' },
  ],
};

/**
 * Administration › Partenaire. Un seul partenaire affiché, avec une période. Publier met à jour tous les emplacements
 * de l'application (source unique : `usePartner`). « Retirer » masque tout immédiatement.
 * À intégrer au back-office (medclick-admin) ; routes backend à créer : voir docs/API.md.
 */
export default function AdminPartnerPage() {
  const { partner, reload } = usePartner();
  const [draft, setDraft] = useState<Partner | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => { if (partner !== undefined) setDraft(partner); }, [partner]);
  const set = (p: Partial<Partner>) => setDraft((d) => ({ ...(d ?? EMPTY), ...p }));

  const publish = async (p: Partner | null) => {
    setBusy(true);
    setMsg(null);
    try {
      await api.savePartner(p);
      reload();
      setMsg(p ? 'Partenaire publié : tous les emplacements sont à jour.' : 'Partenaire retiré : les emplacements sponsor sont masqués.');
    } catch (e) {
      setMsg((e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  const onLogo = (file?: File) => { if (file) set({ logoUrl: URL.createObjectURL(file) }); };
  const endSoon = draft?.endDate ? new Date(draft.endDate + 'T12:00:00').toLocaleDateString('fr-BE', { day: 'numeric', month: 'long', year: 'numeric' }) : null;

  return (
    <div className="admin">
      <aside className="admin-nav" aria-label="Navigation de l'administration">
        <span className="row" style={{ gap: 10, padding: '0 8px', marginBottom: 12 }}>
          <Logo size={34} />
          <span className="stack" style={{ gap: 0 }}><Wordmark size={21} /><span className="eyebrow" style={{ fontSize: 10 }}>Administration</span></span>
        </span>
        {(['Tableau de bord', 'Utilisateurs', 'Nomenclature', 'Connexions', 'Campagnes', 'Partenaire', 'Erreurs'] as const).map((l) => (
          <span key={l} className={'sidebar-link ' + (l === 'Partenaire' ? 'active' : '')} aria-current={l === 'Partenaire' ? 'page' : undefined}>{l}</span>
        ))}
      </aside>

      <main className="admin-main">
        <header className="row" style={{ gap: 12, flexWrap: 'wrap' }}>
          <div className="grow stack" style={{ gap: 4 }}>
            <h1 className="h1">Partenaire affiché</h1>
            <p className="muted" style={{ fontSize: 14 }}>Logo et textes du sponsor, repris partout dans l'application dès la publication.</p>
          </div>
          <span className={'delta ' + (partner ? '' : 'delta--neutral')} style={{ fontSize: 12, padding: '5px 10px', alignSelf: 'center' }}>
            {partner ? '● En ligne' : '○ Aucun partenaire affiché'}
          </span>
          {partner && <button type="button" className="btn btn--danger-ghost btn--sm" disabled={busy} onClick={() => publish(null)}>Retirer le partenaire</button>}
          <button type="button" className="btn btn--primary" style={{ minHeight: 46 }} disabled={busy || !draft?.name.trim()} onClick={() => draft && publish(draft)}>Publier les modifications</button>
        </header>

        {msg && <p className="banner banner--success" role="status">{msg}</p>}
        {partner && endSoon && (
          <div className="banner" role="status" style={{ flexDirection: 'row', alignItems: 'center', gap: 12, background: 'var(--mc-warn-soft)', color: '#7A4700', fontSize: 14 }}>
            <Icon name="calendar" size={20} />
            <span className="grow"><strong>Aucun partenaire programmé après le {endSoon}.</strong> À cette date, les emplacements sponsor seront masqués dans l'application.</span>
          </div>
        )}

        <div className="admin-split">
          {!draft ? (
            <section className="card admin-empty">
              <span className="icon-chip icon-chip--lg" style={{ background: '#EEF1F6', color: 'var(--mc-muted)' }}><Icon name="star" size={28} /></span>
              <strong style={{ fontSize: 20 }}>Aucun partenaire n'est affiché actuellement</strong>
              <p className="muted" style={{ fontSize: 14, lineHeight: 1.5, maxWidth: 460 }}>Les emplacements sponsor sont masqués dans l'application. Les utilisateurs ne voient aucun espace vide ni message.</p>
              <button type="button" className="btn btn--primary btn--sm" onClick={() => setDraft(EMPTY)}>Ajouter un partenaire</button>
            </section>
          ) : (
            <section className="card" style={{ padding: 24, gap: 20 }}>
              <div className="fl">
                <span>Logo</span>
                <div className="row" style={{ gap: 12 }}>
                  <span className="admin-logo-tile" style={{ background: '#fff', border: '1px solid var(--mc-border)' }}><SponsorMark preview={draft} logoSize={44} textSize={34} /></span>
                  <span className="admin-logo-tile" style={{ background: '#EEF4FB' }}><SponsorMark preview={draft} logoSize={44} textSize={34} /></span>
                </div>
                <div className="row" style={{ gap: 10 }}>
                  <label className="btn btn--secondary btn--sm" style={{ cursor: 'pointer' }}>
                    <Icon name="upload" size={16} />Remplacer le logo
                    <input type="file" accept="image/svg+xml,image/png" className="sr-only" onChange={(e) => onLogo(e.target.files?.[0])} />
                  </label>
                  <span className="muted small grow">SVG ou PNG à fond transparent, 512 px de large minimum, 1 Mo maximum. Vérifiez la lisibilité sur fond blanc et sur fond bleu clair.</span>
                </div>
              </div>
              <div className="grid-2" style={{ gap: 16 }}>
                <label className="fl"><span>Nom du partenaire</span><input className="input" value={draft.name} onChange={(e) => set({ name: e.target.value })} /></label>
                <label className="fl"><span>Site web</span><input className="input" type="url" value={draft.url} onChange={(e) => set({ url: e.target.value })} /></label>
              </div>
              <label className="fl"><span>Accroche</span><input className="input" value={draft.tagline} onChange={(e) => set({ tagline: e.target.value })} /></label>
              <label className="fl"><span>Texte « À propos »</span><textarea className="textarea" rows={3} value={draft.about} onChange={(e) => set({ about: e.target.value })} /></label>
              <div className="fl">
                <span>Engagements (4 maximum)</span>
                <div className="grid-2" style={{ gap: '10px 16px' }}>
                  {draft.points.map((pt, i) => (
                    <span key={i} className="row" style={{ gap: 8 }}>
                      <span className="icon-chip icon-chip--sq" style={{ width: 44, height: 44 }}><Icon name={pt.icon} size={18} /></span>
                      <input className="input" aria-label={`Engagement ${i + 1}`} value={pt.label}
                        onChange={(e) => set({ points: draft.points.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)) })} />
                    </span>
                  ))}
                </div>
              </div>
              <div className="grid-2" style={{ gap: 16 }}>
                <label className="fl"><span>Affiché à partir du</span><input className="input" type="date" value={draft.startDate} onChange={(e) => set({ startDate: e.target.value })} /></label>
                <label className="fl"><span>Jusqu'au (facultatif)</span><input className="input" type="date" value={draft.endDate ?? ''} onChange={(e) => set({ endDate: e.target.value || undefined })} /></label>
              </div>
            </section>
          )}

          <aside className="card card--outline" style={{ padding: 20, gap: 16, background: '#fff' }}>
            <div className="stack" style={{ gap: 4 }}>
              <strong style={{ fontSize: 15 }}>{draft ? "Aperçu dans l'application" : 'Ce que voient les utilisateurs'}</strong>
              <span className="muted small">{draft ? 'Les 4 emplacements lisent le même logo : il suffit de le changer ici.' : 'Sans partenaire, chaque emplacement se replie.'}</span>
            </div>
            <Preview title="1 · Écran de connexion">
              <span className="admin-login-card">
                {draft ? <><span className="muted small">Avec le soutien de</span><SponsorMark preview={draft} logoSize={34} textSize={28} /></>
                  : <><span style={{ fontSize: 16, fontWeight: 800 }}>Votre carnet de stage numérique</span><span className="muted small">Interventions, consultations, gardes et formations</span></>}
              </span>
            </Preview>
            <Preview title="2 · Accueil (« Partenaire du mois »)">
              {draft ? <span className="partner-card" style={{ minHeight: 88 }}><span className="stack grow" style={{ gap: 4, padding: '12px 14px' }}><span className="small muted" style={{ fontWeight: 600 }}>Partenaire du mois</span><SponsorMark preview={draft} /></span><span className="partner-visual" style={{ width: 90 }} /></span>
                : <Hidden>Carte « Partenaire du mois » masquée</Hidden>}
            </Preview>
            <Preview title="3 · Barre latérale et profil">
              {draft ? <span className="admin-sidebar-partner"><span className="small muted" style={{ fontWeight: 600 }}>Avec le soutien de</span><SponsorMark preview={draft} logoSize={20} textSize={17} /></span>
                : <Hidden>Encadré et lien « nos partenaires » masqués</Hidden>}
            </Preview>
            <Preview title="4 · Page « Nos partenaires »">
              {draft ? <span className="row" style={{ minHeight: 92, background: '#EEF4FB', borderRadius: 12, alignItems: 'stretch', gap: 0, overflow: 'hidden' }}>
                <span className="grow stack" style={{ justifyContent: 'center', padding: 14, gap: 6 }}><SponsorMark preview={draft} logoSize={32} textSize={28} /><span style={{ fontSize: 13, color: 'var(--mc-ink-2)' }}>{draft.tagline}</span></span>
                <span style={{ width: 70, background: '#DCE7F6' }} aria-hidden="true" />
              </span> : <Hidden>Redirige vers l'accueil</Hidden>}
            </Preview>
          </aside>
        </div>
      </main>
    </div>
  );
}

const Preview = ({ title, children }: { title: string; children: ReactNode }) => (
  <div className="admin-preview"><span className="admin-preview-title">{title}</span>{children}</div>
);
const Hidden = ({ children }: { children: ReactNode }) => (
  <span className="admin-hidden"><Icon name="eyeOff" size={18} />{children}</span>
);

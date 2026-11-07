import { useState } from 'react';
import { MedClickLogo } from '../src/features/inami/components/Icons';
import './admin.css';

/**
 * Administration › Publicités (écran existant), avec le nouvel onglet « Recherche INAMI ».
 * Les onglets Accueil et Connexion sont inchangés : chaque emplacement a ses propres images,
 * dates, statut et statistiques, et se modifie sans toucher aux autres.
 */
export default function AdminPreview() {
  const [frame, setFrame] = useState('desktop');
  return (
    <div className="ad">
      <aside className="ad-side">
        <MedClickLogo size={28} fontSize={20} />
        <span className="ad-enc">+ Encoder</span>
        {['Tableau de bord', 'Interventions', 'Favoris', 'Consultations', 'Gardes', 'Formations', 'Mes années'].map((x) => <span key={x} className="ad-nav">{x}</span>)}
        <span className="ad-nav ad-nav--on">Administration</span>
      </aside>

      <main className="ad-main">
        <div className="ad-head">
          <div>
            <h1>Publicités</h1>
            <p>Une image = une publicité. Choisissez le carrousel, ajoutez vos images, vérifiez l'aperçu, puis publiez.</p>
          </div>
          <span className="ad-links"><span>Statistiques</span><span>Gestion avancée</span></span>
        </div>

        <div className="ad-tabs" role="tablist">
          <div className="ad-tab" role="tab"><strong>Accueil</strong><small>Tableau de bord, ordinateur et mobile · 3 en ligne</small></div>
          <div className="ad-tab" role="tab"><strong>Connexion</strong><small>Page de connexion · 2 en ligne</small></div>
          <div className="ad-tab ad-tab--on" role="tab" aria-selected="true">
            <strong>Recherche INAMI <em>Nouveau</em></strong>
            <small>Page publique des codes INAMI · 1 en ligne</small>
          </div>
        </div>

        <div className="ad-grid">
          <section>
            <p className="ad-count">Recherche INAMI ordinateur : 1 / 3 · Recherche INAMI mobile : 1 / 3</p>
            <div className="ad-drop">
              <strong>+ Ajouter une image</strong>
              <span>Glissez vos images ici, ou cliquez pour les choisir · PNG, JPEG ou WebP, 5 Mo maximum</span>
              <span>Recommandé : <b>1200 × 150 px</b> (ordinateur) · une version smartphone <b>640 × 200 px</b> peut être ajoutée ensuite.</span>
            </div>

            <article className="ad-card">
              <div className="ad-card-h">
                <div><strong>Publicité 1</strong> <span className="ad-live">En ligne</span><small>Exemple fictif · annonceur</small></div>
                <span className="ad-stats">1 204 impressions<br />18 clics</span>
              </div>
              <p className="ad-meta">Recherche INAMI ordinateur et mobile · Dès aujourd'hui, sans date de fin</p>
              <div className="ad-vis">
                <div>
                  <p><b>Ordinateur</b> 1200 × 150 px recommandé</p>
                  <div className="ad-frame ad-frame--d"><img src="ad-desktop.svg" alt="" /></div>
                  <small>Affichée entière, sans recadrage.</small>
                </div>
                <div>
                  <p><b>Smartphone</b> 640 × 200 px recommandé</p>
                  <div className="ad-frame ad-frame--m"><img src="ad-mobile.svg" alt="" /></div>
                  <small>Affichée entière, sans recadrage.</small>
                </div>
              </div>
              <div className="ad-acts"><span>Remplacer</span><span className="ad-del">Retirer</span></div>
            </article>
            <p className="ad-note">Ces images ne servent qu'à la recherche INAMI. Les bannières 16:9 / 2:1 de l'Accueil et de la Connexion ne sont ni reprises ni modifiées.</p>
            <p className="ad-note ad-note--warn"><b>Page publique :</b> seules les campagnes à diffusion publique sont proposées ici. Une campagne « réservée aux professionnels » ne peut pas être ajoutée à cet onglet.</p>
          </section>

          <aside className="ad-prev">
            <div className="ad-prev-h"><strong>Aperçu</strong><span>Actualiser</span></div>
            <div className="ad-seg"><span className="on">En ligne actuellement</span><span>Aperçu de mes modifications</span></div>
            <div className="ad-btns">
              <button type="button" className={frame === 'desktop' ? 'on' : ''} onClick={() => setFrame('desktop')}>Recherche INAMI ordinateur</button>
              <button type="button" className={frame === 'mobile' ? 'on' : ''} onClick={() => setFrame('mobile')}>Recherche INAMI mobile</button>
            </div>
            <div className={`ad-mini ad-mini--${frame}`}>
              <div className="ad-mini-ban"><img src={frame === 'desktop' ? 'ad-desktop.svg' : 'ad-mobile.svg'} alt="" /><span>Publicité</span></div>
              <span className="ad-mini-h1" />
              <span className="ad-mini-l" />
              <span className="ad-mini-in" />
              <span className="ad-mini-row" /><span className="ad-mini-row" /><span className="ad-mini-row" />
            </div>
            <p className="ad-prev-n">Une publicité par affichage ; avec plusieurs publicités en ligne, elles alternent à chaque visite (pas de défilement automatique sur cette page).</p>
          </aside>
        </div>
      </main>
    </div>
  );
}

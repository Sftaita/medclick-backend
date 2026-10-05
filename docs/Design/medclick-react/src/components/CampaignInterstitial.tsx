import { useEffect, useState } from 'react';
import { api } from '@/api';
import type { Campaign } from '@/types';
import { Icon } from './Icon';

/**
 * Campagne plein écran affichée une fois après la connexion (`GET /api/marketing/active`).
 * Se ferme seule à la fin de sa durée ; un toucher ouvre le site de l'annonceur et compte le clic.
 */
export function CampaignInterstitial({ onDone }: { onDone: () => void }) {
  const [campaign, setCampaign] = useState<Campaign | null | undefined>(undefined);
  const [started, setStarted] = useState(false);

  useEffect(() => { api.getCampaign().then(setCampaign).catch(() => setCampaign(null)); }, []);
  useEffect(() => {
    if (campaign === null) { onDone(); return; }
    if (!campaign) return;
    const raf = requestAnimationFrame(() => setStarted(true));
    const t = setTimeout(onDone, campaign.durationMs);
    return () => { cancelAnimationFrame(raf); clearTimeout(t); };
  }, [campaign, onDone]);

  if (!campaign) return null;
  const open = () => {
    if (!campaign.redirectUrl) return;
    api.registerCampaignClick(campaign.id).catch(() => undefined);
    window.open(campaign.redirectUrl, '_blank', 'noopener');
  };

  return (
    <div className="campaign mc-fade">
      <button type="button" className="campaign-area" onClick={open} aria-label="Campagne en cours, ouvrir le site de l'annonceur">
        {campaign.imageUrl
          ? <img src={campaign.imageUrl} alt="Publicité" className="campaign-img" />
          : <span className="campaign-placeholder"><Icon name="image" size={44} stroke={1.6} /><strong>[Visuel de la campagne]</strong></span>}
      </button>
      <span className="campaign-progress"><span style={{ transform: `scaleX(${started ? 1 : 0})`, transitionDuration: `${campaign.durationMs}ms` }} /></span>
      <span className="campaign-tag">Annonce</span>
      {campaign.redirectUrl && <span className="campaign-cta">Touchez pour en savoir plus <Icon name="external" size={16} stroke={2.4} /></span>}
    </div>
  );
}

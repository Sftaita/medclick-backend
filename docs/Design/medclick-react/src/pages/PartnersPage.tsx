import { useState } from 'react';
import { api } from '@/api';
import { useAsync } from '@/hooks/useAsync';
import { Icon } from '@/components/Icon';
import { PartnerMark, Skeleton, TopBar } from '@/components/ui';
import { Reveal } from '@/motion';

// §37.20 — page sponsor : apparitions par simple fondu, aucune animation répétée, pas de vidéo automatique.
export default function PartnersPage() {
  const { data: p } = useAsync(() => api.getPartner(), []);
  const [tab, setTab] = useState<'about' | 'resources'>('about');
  return (
    <div className="screen">
      <TopBar title="Nos partenaires" center />
      <div className="screen-body narrow" style={{ padding: 0, gap: 0 }}>
        {!p ? <div style={{ padding: 16 }}><Skeleton h={170} r={0} /></div> : (
          <>
            <Reveal fadeOnly className="row" style={{ minHeight: 170, background: '#EEF4FB', alignItems: 'stretch', gap: 0 }}>
              <span className="grow stack" style={{ justifyContent: 'center', padding: 20, gap: 8 }}>
                <span style={{ fontSize: 28 }}><PartnerMark size={32} name={p.name} /></span>
                <span style={{ fontSize: 14, color: 'var(--mc-ink-2)' }}>{p.tagline}</span>
              </span>
              <span style={{ width: 140, background: '#DCE7F6' }} aria-hidden="true" />
            </Reveal>
            <div role="tablist" className="tabs" style={{ marginTop: 8 }}>
              <button role="tab" aria-selected={tab === 'about'} onClick={() => setTab('about')}>À propos</button>
              <button role="tab" aria-selected={tab === 'resources'} onClick={() => setTab('resources')}>Ressources</button>
            </div>
            <div className="stack" style={{ padding: '18px 16px 24px', gap: 14 }}>
              {tab === 'about' ? (
                <>
                  <h2 className="h2">À propos d'{p.name}</h2>
                  <p style={{ fontSize: 14, lineHeight: 1.5, color: 'var(--mc-ink-2)' }}>{p.about}</p>
                  <ul className="list" style={{ margin: 0, padding: 0, listStyle: 'none' }}>
                    {p.points.map((pt) => (
                      <li key={pt.label} className="row" style={{ minHeight: 48, fontSize: 14, fontWeight: 600, gap: 14 }}>
                        <span className="icon-chip icon-chip--sq" style={{ width: 34, height: 34 }}><Icon name={pt.icon} size={18} /></span>{pt.label}
                      </li>
                    ))}
                  </ul>
                </>
              ) : <p className="muted">[Ressources du partenaire — à alimenter depuis le backend]</p>}
              <a href={p.url} target="_blank" rel="noreferrer noopener" className="btn btn--primary">Découvrir {p.name}<Icon name="external" size={18} stroke={2.4} /></a>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

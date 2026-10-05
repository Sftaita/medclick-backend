import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { api } from '@/api';
import type { Partner } from '@/types';

/**
 * Source unique du partenaire (sponsor) : chargé une fois, partagé par tous les emplacements
 * (connexion, accueil, barre latérale, page Partenaires). Modifié depuis l'administration →
 * `reload()` met tout à jour. `partner === null` : aucun partenaire, chaque emplacement se replie.
 */
const Ctx = createContext<{ partner: Partner | null | undefined; reload: () => void }>({ partner: undefined, reload: () => undefined });

export function PartnerProvider({ children }: { children: ReactNode }) {
  const [partner, setPartner] = useState<Partner | null | undefined>(undefined);
  const reload = useCallback(() => { api.getPartner().then(setPartner).catch(() => setPartner(null)); }, []);
  useEffect(reload, [reload]);
  return <Ctx.Provider value={{ partner, reload }}>{children}</Ctx.Provider>;
}

/** `undefined` pendant le chargement, `null` s'il n'y a pas de partenaire. */
export const usePartner = () => useContext(Ctx);

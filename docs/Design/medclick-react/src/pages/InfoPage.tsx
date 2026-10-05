import { TopBar } from '@/components/ui';

/** Pages d'information du menu Profil, non encore conçues : contenu à rédiger. */
export default function InfoPage({ title }: { title: string }) {
  return (
    <div className="screen">
      <TopBar title={title} center />
      <div className="screen-body narrow">
        <div className="card card--outline muted" style={{ fontSize: 14 }}>[Contenu de la page « {title} » à rédiger]</div>
      </div>
    </div>
  );
}

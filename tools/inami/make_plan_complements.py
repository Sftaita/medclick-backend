"""Plan 3 : ajouts complémentaires parmi les 25 codes laissés en attente à l'étape 5.

Usage : python make_plan_complements.py <analysis.json> <TXT.zip> <plan de sortie>

Seuls les codes dont la correspondance est certaine sont repris. Libellé, type et région sont fixés
explicitement ci-dessous, avec leur justification (aucune déduction automatique).
"""
import datetime as dt
import hashlib
import json
import sys



def fr(day):
    return dt.date.fromisoformat(day).strftime('%d/%m/%Y') if day else '—'


def act_text(act, effect_label):
    """Même format de référence que make_plan.py."""
    if not act:
        return ''
    head = f"INAMI NomenSoft 01.11.2026 — {act.get('doc')} (publ. {act.get('publication')}) {effect_label} {fr(act.get('effect'))} : "
    return (head + (act.get('label') or ''))[:255]


FRACTURE = ' — traitement non sanglant, sans réduction'

# code hospitalisation -> (libellé MedClick, type, région, justification)
DECISIONS = {
    # Thoracique : 229261 (déjà présent) = péricardotomie ; 229482 = rubrique « par voie thoracoscopique ».
    '229482': ('Péricardotomie par voie thoracoscopique', None, None,
               '14 e), rubrique « Prestations chirurgicales par voie thoracoscopique » ; 229261 = voie classique'),
    # Prothèses (KB2024007896, en vigueur le 01/10/2024) : actes nouveaux, aucun équivalent MedClick.
    '286661': (None, '1', 'wristhand', 'trapézo-métacarpienne = poignet-main ; chirurgie programmée'),
    '286683': (None, '1', 'wristhand', 'trapézo-métacarpienne = poignet-main ; chirurgie programmée'),
    '286705': (None, '1', 'wristhand', 'trapézo-métacarpienne = poignet-main ; chirurgie programmée'),
    '286742': (None, '1', 'ankle', 'prothèse de cheville ; chirurgie programmée ; acte distinct de 279204 depuis le 01/10/2024'),
    # Fractures, § 2 Traitements non sanglants, d) sans réduction. Le code MedClick au même libellé est le
    # b) avec réduction : actes différents. Région = celle du code « avec réduction » correspondant.
    '296984': ("Fracture d'un doigt" + FRACTURE, '2', 'wristhand', 'jumeau avec réduction 296446 (wristhand)'),
    '298723': ('Fracture ou luxation du bassin' + FRACTURE, '2', 'pelvic', 'jumeau avec réduction 298222 (pelvic)'),
    '298745': ('Fracture du col du fémur' + FRACTURE, '2', 'hip', 'jumeau avec réduction 298266 (hip)'),
    '298760': ('Fracture intertrochantérienne du fémur' + FRACTURE, '2', 'proximalFemur',
               'jumeau avec réduction 298281 (proximalFe, valeur tronquée normalisée)'),
    '298782': ('Fracture de la diaphyse du fémur' + FRACTURE, '2', 'midFemur', 'jumeau avec réduction 298303 (midFemur)'),
    '298826': ('Fracture de la rotule' + FRACTURE, '2', 'knee', 'jumeau avec réduction 298340 (knee)'),
    '298841': ('Fracture du plateau tibial' + FRACTURE, '2', 'knee', 'jumeau avec réduction 298362 (knee)'),
    '298863': ('Fracture diaphysaire de la jambe : tibia ou les deux os' + FRACTURE, '2', 'limb',
               'jumeau avec réduction 298384 sans région ; diaphyse de jambe = « Jambe » (limb)'),
    '298885': ('Fracture du pilon tibial' + FRACTURE, '2', 'ankle', 'jumeau avec réduction 298406 (ankle)'),
    '298900': ('Fracture unimalléolaire' + FRACTURE, '2', 'ankle', 'jumeau avec réduction 298421 (ankle)'),
    '298922': ('Fracture bi- ou trimalléolaire' + FRACTURE, '2', 'ankle', 'jumeau avec réduction 298443 (ankle)'),
    '298944': ("Fracture de l'astragale" + FRACTURE, '2', 'foot', 'jumeau avec réduction 298465 (foot)'),
    '298966': ('Fracture du calcanéum' + FRACTURE, '2', 'foot', 'jumeau avec réduction 298480 (foot)'),
    '298981': ('Fracture du tarse antérieur' + FRACTURE, '2', 'foot', 'jumeau avec réduction 298502 (foot)'),
    '299003': ("Fracture du métatarse (un ou plusieurs métatarsiens d'un même pied)" + FRACTURE, '2', 'foot',
               'jumeau avec réduction 298546 (foot)'),
}

if __name__ == '__main__':
    analysis_path, txt_zip, out = sys.argv[1], sys.argv[2], sys.argv[3]
    a = json.load(open(analysis_path, encoding='utf-8'))
    sha = hashlib.sha256(open(txt_zip, 'rb').read()).hexdigest()
    pending = {n['code_hosp']: n for n in a['new'] if not n['importable']}
    missing = set(DECISIONS) - set(pending)
    if missing:
        sys.exit(f'codes absents des codes en attente : {sorted(missing)}')

    adds = []
    for code, (name, typ, sub, why) in DECISIONS.items():
        n = pending[code]
        adds.append({
            'speciality': n['speciality'], 'codeHospitalisation': code, 'codeAmbulant': n['code_amb'], 'n': n['n'],
            'name': name or n['name'], 'type': typ, 'subType': sub, 'officialStart': n['dbegin'],
            'reference': act_text(n['creation'], 'création au') or f"INAMI NomenSoft 01.11.2026 — en vigueur depuis le {fr(n['dbegin'])}",
            'justification': why,
        })
    plan = {
        'source': {
            'label': 'INAMI NomenSoft — tableaux de référence applicables au 01/11/2026 (création 09/10/2026)',
            'url': 'https://www.inami.fgov.be/SiteCollectionDocuments/TXT.zip', 'sha256': sha,
            'page': 'https://www.inami.fgov.be/fr/nomenclature/tableaux-de-reference',
            'generated': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds'),
        },
        'add': adds, 'repeal': [],
    }
    json.dump(plan, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f'plan : {len(adds)} ajout(s) ; écartés : {sorted(set(pending) - set(DECISIONS))}')

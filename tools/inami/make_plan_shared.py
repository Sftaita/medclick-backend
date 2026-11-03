"""Plan 4 : actes partagés entre spécialités (ADR-211), d'après les tables officielles NomenSoft.

Usage : python make_plan_shared.py <dossier TXT NomenSoft> <TXT.zip> <export TSV nomenclature MedClick> <plan de sortie>

Règle explicite (aucune recherche par mot-clé dans les libellés) : un code MedClick est partagé si
son code hospitalier ET son code ambulant relèvent, dans la version officielle en vigueur au
01/11/2026, du sous-article NomenSoft « 14n » : « n) les prestations relevant de la spécialité de
chirurgie orthopédique (DP) et neurochirurgie (DA) ». Ces codes sont en `ortho` dans MedClick ; la
neurochirurgie est ajoutée comme spécialité supplémentaire, sans dupliquer la ligne.

L'export TSV (sans en-tête) : id, speciality, code_ambulant, code_hospitalisation, n, name, type, sub_type.
"""
import csv
import datetime as dt
import hashlib
import json
import sys

REFERENCE = dt.date(2026, 11, 1)
SUB_ARTICLE = '14n'
PRIMARY, SHARED = 'ortho', ['neuro']
COLUMNS = ['id', 'speciality', 'code_ambulant', 'code_hospitalisation', 'n', 'name', 'type', 'sub_type']


def day(text):
    return dt.datetime.strptime(text, '%d-%m-%Y').date()


if __name__ == '__main__':
    txt, txt_zip, tsv, out = sys.argv[1:5]
    csv.field_size_limit(10**8)

    # Version officielle EN VIGUEUR à la date de référence, par code.
    current, description = {}, None
    rows = csv.DictReader(open(f'{txt}/NOMEN_SUMMARY_EXT.TXT', encoding='cp1252', newline=''), delimiter='|')
    for r in rows:
        if day(r['dbegin']) <= REFERENCE <= day(r['dend']):
            current[r['nomen_code']] = r['nomen_sub_art']
            if r['nomen_sub_art'] == SUB_ARTICLE:
                description = r['nomen_sub_art_desc_fr'].strip()

    medclick = [dict(zip(COLUMNS, r)) for r in csv.reader(open(tsv, encoding='utf-8', newline=''), delimiter='\t')]
    share, mismatches = [], []
    for m in medclick:
        hosp, amb = m['code_hospitalisation'], m['code_ambulant']
        in_hosp, in_amb = current.get(hosp) == SUB_ARTICLE, current.get(amb) == SUB_ARTICLE
        if not (in_hosp or in_amb):
            continue
        if not (in_hosp and in_amb) or m['speciality'] != PRIMARY:
            mismatches.append(f"{m['id']} {m['speciality']} {hosp}/{amb} : hosp {current.get(hosp)}, amb {current.get(amb)}")
            continue
        share.append({
            'id': int(m['id']), 'codeHospitalisation': hosp, 'codeAmbulant': amb, 'n': m['n'],
            'speciality': PRIMARY, 'specialities': SHARED, 'name': m['name'],
        })
    if mismatches:
        sys.exit('codes incohérents, plan non produit :\n' + '\n'.join(mismatches))

    officials = sorted(c for c, s in current.items() if s == SUB_ARTICLE)
    plan = {
        'source': {
            'label': f'INAMI NomenSoft — sous-article {SUB_ARTICLE} en vigueur au {REFERENCE:%d/%m/%Y} : {description}',
            'url': 'https://www.inami.fgov.be/SiteCollectionDocuments/TXT.zip',
            'sha256': hashlib.sha256(open(txt_zip, 'rb').read()).hexdigest(),
            'page': 'https://www.inami.fgov.be/fr/nomenclature/tableaux-de-reference',
            'rule': f"codes hospitalier et ambulant du sous-article {SUB_ARTICLE} (NOMEN_SUMMARY_EXT.nomen_sub_art, version en vigueur) ; spécialité principale {PRIMARY} conservée, spécialités supplémentaires {', '.join(SHARED)}",
            'officialCodes': len(officials),
            'generated': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds'),
        },
        'share': sorted(share, key=lambda s: s['id']),
    }
    covered = {s['codeHospitalisation'] for s in share} | {s['codeAmbulant'] for s in share}
    json.dump(plan, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f'plan : {len(share)} prestation(s) partagée(s) ; codes officiels {SUB_ARTICLE} en vigueur : {len(officials)} ; '
          f'absents de MedClick : {sorted(set(officials) - covered)}')

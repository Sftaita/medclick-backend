"""Plan des actes de l'article 14 n) partagés entre orthopédie et neurochirurgie (ADR-211), d'après NomenSoft.

Usage : python make_plan_shared.py <dossier TXT NomenSoft> <TXT.zip> <export TSV nomenclature MedClick> <plan de sortie>

Règles explicites (aucune recherche par mot-clé dans les libellés), version officielle en vigueur au 01/11/2026 :
- `classify` : le code hospitalier ET le code ambulant de la ligne relèvent du sous-article NomenSoft « 14n »,
  « n) les prestations relevant de la spécialité de chirurgie orthopédique (DP) et neurochirurgie (DA) ».
  Une intervention déjà enregistrée en neurochirurgie est classée à son récapitulatif ; aucun droit de saisie.
- `share` : ligne de `classify` dont la référence est exacte : les deux codes forment la paire officielle
  (nomenclature correspondante réciproque), catégories hospitalisé et ambulant dans les bonnes colonnes, valeur égale
  à la lettre-clé des deux codes. Proposée pour une nouvelle saisie en neurochirurgie.
- `withdraw` : les autres lignes de `classify` : aucune nouvelle saisie en neurochirurgie tant que leur référence n'est
  pas corrigée. Elles restent en orthopédie, inchangées.

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
HOSPITALISED, AMBULANT = '2', '1'
COLUMNS = ['id', 'speciality', 'code_ambulant', 'code_hospitalisation', 'n', 'name', 'type', 'sub_type']


def day(text):
    return dt.datetime.strptime(text, '%d-%m-%Y').date()


def key(row):
    return f"{row['key_letter1']}{int(row['key_coeff1'].split(',')[0].replace('.', ''))}"


def defect(line, official):
    """Raison pour laquelle la référence de la ligne n'est pas exacte (None si elle l'est)."""
    hosp, amb, n = line['code_hospitalisation'], line['code_ambulant'], line['n']
    h, a = official[hosp], official[amb]
    if h['corresp_pat_cat_real'] != amb or a['corresp_pat_cat_real'] != hosp:
        return f"paire non officielle : {hosp} va avec {h['corresp_pat_cat_real']}, {amb} va avec {a['corresp_pat_cat_real']}"
    if h['ambhos_pat_cat'] != HOSPITALISED or a['ambhos_pat_cat'] != AMBULANT:
        return f"catégories inversées : {hosp} [{h['ambhos_pat_cat']}], {amb} [{a['ambhos_pat_cat']}]"
    if key(h) != n or key(a) != n:
        return f"valeur {n} différente de l'officielle {key(h)}"
    return None


if __name__ == '__main__':
    txt, txt_zip, tsv, out = sys.argv[1:5]
    csv.field_size_limit(10**8)

    # Version officielle EN VIGUEUR à la date de référence, par code.
    official, description = {}, None
    for r in csv.DictReader(open(f'{txt}/NOMEN_SUMMARY_EXT.TXT', encoding='cp1252', newline=''), delimiter='|'):
        if day(r['dbegin']) <= REFERENCE <= day(r['dend']):
            official[r['nomen_code']] = r
            if r['nomen_sub_art'] == SUB_ARTICLE:
                description = r['nomen_sub_art_desc_fr'].strip()
    in_article = lambda code: code in official and official[code]['nomen_sub_art'] == SUB_ARTICLE

    medclick = [dict(zip(COLUMNS, r)) for r in csv.reader(open(tsv, encoding='utf-8', newline=''), delimiter='\t')]
    share, withdraw, classify, mismatches = [], [], [], []
    for m in medclick:
        hosp, amb = m['code_hospitalisation'], m['code_ambulant']
        if not (in_article(hosp) or in_article(amb)):
            continue
        if not (in_article(hosp) and in_article(amb)) or m['speciality'] != PRIMARY:
            mismatches.append(f"{m['id']} {m['speciality']} {hosp}/{amb}")
            continue
        line = {'id': int(m['id']), 'codeHospitalisation': hosp, 'codeAmbulant': amb, 'n': m['n'], 'speciality': PRIMARY, 'specialities': SHARED}
        classify.append(line)
        reason = defect(m, official)
        if reason:
            withdraw.append({**line, 'reason': reason})
        else:
            share.append({**line, 'name': m['name']})
    if mismatches:
        sys.exit('codes incohérents, plan non produit :\n' + '\n'.join(mismatches))

    by_id = lambda item: item['id']
    plan = {
        'source': {
            'label': f'INAMI NomenSoft — sous-article {SUB_ARTICLE} en vigueur au {REFERENCE:%d/%m/%Y} : {description}',
            'url': 'https://www.inami.fgov.be/SiteCollectionDocuments/TXT.zip',
            'sha256': hashlib.sha256(open(txt_zip, 'rb').read()).hexdigest(),
            'page': 'https://www.inami.fgov.be/fr/nomenclature/tableaux-de-reference',
            'rule': f"classify : codes hospitalier et ambulant du sous-article {SUB_ARTICLE} (NOMEN_SUMMARY_EXT.nomen_sub_art) ; share : paire officielle réciproque, catégories et valeur exactes ; withdraw : les autres",
            'officialCodes': sum(1 for code in official if in_article(code)),
            'generated': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds'),
        },
        'share': sorted(share, key=by_id),
        'withdraw': sorted(withdraw, key=by_id),
        'classify': sorted(classify, key=by_id),
    }
    json.dump(plan, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f"plan : classify {len(classify)}, share {len(share)}, withdraw {len(withdraw)} ; codes officiels {SUB_ARTICLE} en vigueur : {plan['source']['officialCodes']}")

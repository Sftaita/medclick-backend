"""Plans de correction des références de l'article 14 n) (ADR-212), d'après NomenSoft.

Usage : python make_plan_correction.py <dossier TXT NomenSoft> <TXT.zip> <export TSV MedClick> <plan 3.7.0> <plan correction> <plan partage>

Chaque ligne non exacte du plan 3.7.0 (`withdraw`) est rapprochée de l'acte officiel par son libellé ET sa valeur
exacts (aucune déduction par inversion des colonnes). La paire cible doit être officielle et réciproque, hospitalisé
dans la colonne hospitalisation, ambulant dans la colonne ambulant.

Une ligne est corrigée seulement si l'acte lui revient sans ambiguïté. Quand deux lignes MedClick visent le même acte,
la ligne corrigée est celle dont les colonnes suivent le décalage systématique (ambulant de l'acte voisin / hospitalisé
de l'acte) ; l'autre reste inchangée, n'est pas partagée et est signalée (DUPLICATES_OF).

L'export TSV (sans en-tête) : id, speciality, code_ambulant, code_hospitalisation, n, name, type, sub_type, active, interventions, favoris.
"""
import collections
import csv
import datetime as dt
import hashlib
import json
import re
import sys
import unicodedata

REFERENCE = dt.date(2026, 11, 1)
HOSPITALISED, AMBULANT = '2', '1'
COLUMNS = ['id', 'speciality', 'code_ambulant', 'code_hospitalisation', 'n', 'name', 'type', 'sub_type', 'active', 'surgeries', 'favorites']
# Ligne doublon -> ligne qui porte l'acte officiel.
DUPLICATES_OF = {6950: 6951, 6957: 6956}


def day(text):
    return dt.datetime.strptime(text, '%d-%m-%Y').date()


def norm(text):
    text = unicodedata.normalize('NFKD', (text or '').replace('’', "'")).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'\s+', ' ', re.sub(r'[^a-z0-9]+', ' ', re.sub(r'^[\s^°]+', '', text))).strip()


def key(row):
    return f"{row['key_letter1']}{int(row['key_coeff1'].split(',')[0].replace('.', ''))}"


if __name__ == '__main__':
    txt, txt_zip, tsv, plan370_path, out_correct, out_share = sys.argv[1:7]
    csv.field_size_limit(10**8)
    official = {}
    for r in csv.DictReader(open(f'{txt}/NOMEN_SUMMARY_EXT.TXT', encoding='cp1252', newline=''), delimiter='|'):
        if day(r['dbegin']) <= REFERENCE <= day(r['dend']):
            official[r['nomen_code']] = r

    acts = collections.defaultdict(list)
    for code, r in official.items():
        if r['nomen_sub_art'] == '14n' and r['ambhos_pat_cat'] == HOSPITALISED:
            acts[(norm(r['nomen_desc_fr']), key(r))].append(code)

    rows = {int(r[0]): dict(zip(COLUMNS, r)) for r in csv.reader(open(tsv, encoding='utf-8', newline=''), delimiter='\t')}
    withdraw = [item['id'] for item in json.load(open(plan370_path, encoding='utf-8'))['withdraw']]

    correct, skipped, errors = [], [], []
    for i in withdraw:
        m = rows[i]
        found = acts.get((norm(m['name']), m['n']), [])
        if len(found) != 1:
            errors.append(f'{i} : {len(found)} acte(s) officiel(s) pour ce libellé et cette valeur')
            continue
        hosp = found[0]
        amb = official[hosp]['corresp_pat_cat_real']
        a = official.get(amb)
        if a is None or a['corresp_pat_cat_real'] != hosp or a['ambhos_pat_cat'] != AMBULANT or key(a) != m['n'] or norm(a['nomen_desc_fr']) != norm(m['name']):
            errors.append(f'{i} : paire officielle {hosp}/{amb} non réciproque ou différente')
            continue
        if i in DUPLICATES_OF:
            skipped.append({
                'id': i, 'codeHospitalisation': m['code_hospitalisation'], 'codeAmbulant': m['code_ambulant'], 'n': m['n'],
                'speciality': m['speciality'], 'specialities': ['neuro'], 'sameActAs': DUPLICATES_OF[i],
                'interventions': int(m['surgeries']), 'favorites': int(m['favorites']),
                'reason': f"même acte officiel ({hosp}/{amb}) que la ligne {DUPLICATES_OF[i]} : décision à prendre, ligne inchangée et non partagée",
            })
            continue
        correct.append({
            'id': i, 'speciality': m['speciality'], 'n': m['n'], 'name': m['name'],
            'before': {'codeHospitalisation': m['code_hospitalisation'], 'codeAmbulant': m['code_ambulant']},
            'after': {'codeHospitalisation': hosp, 'codeAmbulant': amb},
            'interventions': int(m['surgeries']), 'favorites': int(m['favorites']),
        })
    if errors:
        sys.exit('plan non produit :\n' + '\n'.join(errors))

    # Toute la nomenclature après correction : un code sur plusieurs lignes doit être explicitement connu.
    final = {i: (m['code_hospitalisation'], m['code_ambulant']) for i, m in rows.items()}
    for item in correct:
        final[item['id']] = (item['after']['codeHospitalisation'], item['after']['codeAmbulant'])
    usage = collections.defaultdict(set)
    for i, codes in final.items():
        for code in codes:
            if code:
                usage[code].add(i)
    duplicates = {code: sorted(ids) for code, ids in sorted(usage.items()) if len(ids) > 1}
    allowed = {'226542': [6957, 6961], '225632': [6950, 6951], '226494': [6956, 6957]}
    if duplicates != allowed:
        sys.exit(f'codes en double inattendus : {duplicates}')

    source = {
        'label': 'INAMI NomenSoft — tableaux de référence applicables au 01/11/2026, sous-article 14n',
        'url': 'https://www.inami.fgov.be/SiteCollectionDocuments/TXT.zip',
        'sha256': hashlib.sha256(open(txt_zip, 'rb').read()).hexdigest(),
        'rule': "acte officiel unique par libellé et valeur exacts ; paire réciproque ; hospitalisé et ambulant dans leur colonne",
        'generated': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds'),
    }
    by_id = lambda item: item['id']
    json.dump({
        'source': source, 'correct': sorted(correct, key=by_id), 'unchanged': sorted(skipped, key=by_id),
        'knownDuplicates': duplicates,
    }, open(out_correct, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump({
        'source': {**source, 'label': 'Rachis, article 14 n) : lignes aux références corrigées, autorisées en neurochirurgie'},
        'share': [{
            'id': item['id'], 'speciality': item['speciality'], 'specialities': ['neuro'], 'n': item['n'], 'name': item['name'],
            'codeHospitalisation': item['after']['codeHospitalisation'], 'codeAmbulant': item['after']['codeAmbulant'],
        } for item in sorted(correct, key=by_id)],
        'withdraw': [{k: item[k] for k in ('id', 'codeHospitalisation', 'codeAmbulant', 'n', 'speciality', 'specialities', 'reason')} for item in sorted(skipped, key=by_id)],
    }, open(out_share, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    missing = sorted(h for (_, _), codes in acts.items() for h in codes if h not in usage)
    print(f"corrigées {len(correct)} ({sum(c['interventions'] for c in correct)} interventions, {sum(c['favorites'] for c in correct)} favoris) ; inchangées {[(s['id'], s['interventions'], s['favorites']) for s in skipped]}")
    print(f'codes sur 2 lignes : {duplicates}')
    print(f'actes officiels 14n sans ligne MedClick : {missing}')
    print(f"simple inversion des colonnes : {sum(1 for c in correct if c['after'] == {'codeHospitalisation': c['before']['codeAmbulant'], 'codeAmbulant': c['before']['codeHospitalisation']})} ; autre : {sum(1 for c in correct if c['after'] != {'codeHospitalisation': c['before']['codeAmbulant'], 'codeAmbulant': c['before']['codeHospitalisation']})}")

"""Vérification ciblée des 448 désactivations (étape 5) : correspondances ambulant / hospitalisé.

Pour chaque désactivation proposée, on rassemble TOUS les codes officiels de la prestation : les deux
colonnes MedClick et leurs codes correspondants officiels (paire ambulant / hospitalisé). Désactivation
« certaine » si : tous ces codes sont abrogés, avec la même date de fin, et le libellé MedClick
correspond au libellé officiel d'un de ces codes (similarité >= 0,80). Sinon : « douteuse » (isolée).
Les 69 lignes orthopédiques inversées et les 81 lignes neuro (code ambulant dans les deux colonnes)
sont contrôlées explicitement. Aucune écriture ; sortie : verification.json, plans séparés.
"""
import collections
import csv
import datetime as dt
import difflib
import json
import re
import sys
import unicodedata

csv.field_size_limit(10**8)
TXT, ANALYSIS, PLAN, OUTDIR = sys.argv[1:5]
TODAY = dt.date(2026, 11, 2)


def d(text):
    return dt.datetime.strptime(text, '%d-%m-%Y').date()


def norm(text):
    text = unicodedata.normalize('NFKD', (text or '').replace('’', "'")).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'\s+', ' ', re.sub(r'[^a-z0-9]+', ' ', text)).strip()


official = {}
for r in csv.DictReader(open(f'{TXT}/NOMEN_SUMMARY_EXT.TXT', encoding='cp1252', newline=''), delimiter='|'):
    official[r['nomen_code']] = {'dbegin': d(r['dbegin']), 'dend': d(r['dend']), 'cat': r['ambhos_pat_cat'], 'desc': r['nomen_desc_fr'],
                                 'corresp': r['corresp_pat_cat_real'] if r['corresp_pat_cat_real'] not in ('', '000000') else ''}

a = json.load(open(ANALYSIS, encoding='utf-8'))
plan = json.load(open(PLAN, encoding='utf-8'))
by_id = {e['id']: e for e in a['existing']}
results = []
for item in plan['repeal']:
    e = by_id[item['id']]
    columns = [c for c in (e['code_hosp'], e['code_amb']) if c]
    pair = set(columns)
    for c in columns:
        if c in official and official[c]['corresp']:
            pair.add(official[c]['corresp'])
    statuses = {c: ('introuvable' if c not in official else 'valide' if official[c]['dend'] >= TODAY else 'abrogé') for c in sorted(pair)}
    ends = {official[c]['dend'] for c in pair if c in official}
    sims = [difflib.SequenceMatcher(None, norm(e['name']), norm(official[c]['desc'])).ratio() for c in pair if c in official]
    best = max(sims) if sims else 0
    kind = ('codes inversés (ortho)' if any(x.startswith('codes inversés') for x in e['anomalies'])
            else 'code ambulant dans les deux colonnes' if e['code_hosp'] == e['code_amb'] else 'standard')
    doubts = []
    if any(s == 'valide' for s in statuses.values()):
        doubts.append('un code de la paire officielle est toujours valide : ' + ', '.join(c for c, s in statuses.items() if s == 'valide'))
    if len(ends) > 1:
        doubts.append('dates de fin différentes dans la paire : ' + ', '.join(sorted(x.isoformat() for x in ends)))
    if best < 0.80:
        doubts.append(f'libellé MedClick éloigné du libellé officiel (similarité {best:.2f})')
    if any(s == 'introuvable' for s in statuses.values()):
        doubts.append('code de la paire introuvable : ' + ', '.join(c for c, s in statuses.items() if s == 'introuvable'))
    results.append({**item, 'speciality': e['speciality'], 'name': e['name'], 'kind': kind, 'pair': statuses,
                    'label_similarity': round(best, 2), 'certain': not doubts, 'doubts': doubts,
                    'surgeries': e['surgeries'], 'favorites': e['favorites']})

certain = [r for r in results if r['certain']]
doubtful = [r for r in results if not r['certain']]
json.dump(results, open(f'{OUTDIR}/verification-desactivations.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
base = {'source': plan['source']}
json.dump({**base, 'add': plan['add'], 'repeal': []}, open(f'{OUTDIR}/plan-import-inami-20261101-1-ajouts.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
json.dump({**base, 'add': [], 'repeal': [{k: r[k] for k in ('id', 'codeHospitalisation', 'repealedAt', 'source')} for r in certain]},
          open(f'{OUTDIR}/plan-import-inami-20261101-2-desactivations.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

print(f'désactivations proposées : {len(results)} ; certaines : {len(certain)} ; douteuses : {len(doubtful)}')
for kind, rows in sorted(collections.defaultdict(list, {k: [r for r in results if r['kind'] == k] for k in {r['kind'] for r in results}}).items()):
    print(f"  {kind:38} {len(rows):4}  certaines {sum(r['certain'] for r in rows):4}  douteuses {sum(not r['certain'] for r in rows):3}")
print('Lignes contrôlées hors désactivations : inversées ortho', sum(1 for e in a['existing'] if any(x.startswith('codes inversés') for x in e['anomalies'])),
      '; neuro ambulant x2', sum(1 for e in a['existing'] if e['speciality'] == 'neuro' and e['code_hosp'] == e['code_amb']))
print('motifs de doute :', collections.Counter(x.split(':')[0].split('(')[0].strip() for r in doubtful for x in r['doubts']))
for r in doubtful[:12]:
    print('  ', r['id'], r['speciality'], r['codeHospitalisation'], r['kind'], '|', '; '.join(r['doubts'])[:150])

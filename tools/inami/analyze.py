"""Rapprochement nomenclature MedClick <-> NomenSoft (INAMI, publication applicable au 01/11/2026).

Entrées : dossier des tables TXT officielles, export TSV de la nomenclature MedClick (production,
lecture seule). Sortie : analysis.json (détail par code, nouveaux codes, plan d'import).
Aucune écriture en base. Règles :
- un code est « valide » si dbegin <= aujourd'hui <= dend (31-12-2999 = sans fin) ;
- abrogation ÉTABLIE : date de fin passée ET acte officiel « Suppression » (legalchange -1) dont la
  date d'effet = date de fin + 1 jour ; sinon « à vérifier » (jamais désactivé automatiquement) ;
- un code absent des tables n'est jamais considéré comme abrogé (« introuvable ») ;
- date officielle d'abrogation MedClick = date de fin officielle + 1 jour (premier jour sans validité).
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
TXT, MEDCLICK, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
TODAY = dt.date(2026, 11, 2)
FAR = dt.date(2999, 12, 31)


def rd(name):
    return csv.DictReader(open(f'{TXT}/{name}', encoding='cp1252', newline=''), delimiter='|')


def d(text):
    return dt.datetime.strptime(text, '%d-%m-%Y').date() if text else None


def norm(text):
    text = unicodedata.normalize('NFKD', (text or '').replace('’', "'")).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'\s+', ' ', re.sub(r'[^a-z0-9]+', ' ', text)).strip()


def similarity(a, b):
    return difflib.SequenceMatcher(None, norm(a), norm(b)).ratio()


def key(letter, coeff):
    if not letter:
        return ''
    # Format officiel : virgule décimale, point des milliers (« 1.000,000000 » ou « 1.000.000000 »).
    raw = coeff.strip()
    integer, _, decimals = raw.rpartition(',') if ',' in raw else raw.rpartition('.')
    value = (integer.replace('.', '') + '.' + decimals) if integer else decimals
    number = float(value) if value else 0.0
    text = f'{number:.2f}'.rstrip('0').rstrip('.').replace('.', ',')
    return f'{letter}{text}'


# ---------------------------------------------------------------- Tables officielles
official = {}
for r in rd('NOMEN_SUMMARY_EXT.TXT'):
    official[r['nomen_code']] = {
        'code': r['nomen_code'], 'dbegin': d(r['dbegin']), 'dend': d(r['dend']),
        'art': r['nomen_art'], 'sub_art': r['nomen_sub_art'], 'sub_art_desc': r['nomen_sub_art_desc_fr'][:160],
        'cat': r['ambhos_pat_cat'], 'cat_desc': r['ambhos_pat_cat_desc_fr'],
        'desc_fr': re.sub(r'\s+', ' ', r['nomen_desc_fr']).strip(),
        'key': key(r['key_letter1'], r['key_coeff1']),
        'corresp': r['corresp_pat_cat_real'] if r['corresp_pat_cat_real'] not in ('', '000000') else '',
    }

authorities = {r['AUTHORITY_ID']: r for r in rd('NOMEN_AUTHORITY_EXT.TXT')}
legal = collections.defaultdict(list)  # code -> [(legalchange, date d'effet, authority)]
for r in rd('NOMEN_CODE_AUTHORITY_LIM.TXT'):
    if r['legalchange'] in ('0', '-1', '1', '-10'):
        legal[r['nomen_code']].append((r['legalchange'], d(r['dbegin_legalchange']), r['AUTHORITY_ID']))


def authority_ref(authority_id):
    a = authorities.get(authority_id)
    if not a:
        return None
    return {
        'doc': a['author_doc'], 'type': a['type_doc'], 'label': re.sub(r'\s+', ' ', a['author_doc_label_fr']).strip()[:400],
        'publication': a['dpub'], 'document': a['ddoc'], 'application': a['dapplication_doc'],
    }


def legal_act(code, change, effect):
    for lc, date, authority_id in legal.get(code, []):
        if lc == change and (effect is None or date == effect):
            return {'effect': date.isoformat() if date else None, **(authority_ref(authority_id) or {'doc': authority_id})}
    return None


def status(o):
    if o is None:
        return 'introuvable'
    if o['dbegin'] and o['dbegin'] > TODAY:
        return 'pas encore en vigueur'
    if o['dend'] and o['dend'] < TODAY:
        return 'abrogé'
    if o['dend'] and o['dend'] < FAR:
        return 'abrogation programmée'
    return 'valide'


# ---------------------------------------------------------------- MedClick (production, lecture seule)
medclick = []
for line in open(MEDCLICK, encoding='utf-8'):
    f = line.rstrip('\n').split('\t')
    medclick.append({'id': int(f[0]), 'speciality': f[1], 'code_amb': f[2], 'code_hosp': f[3], 'n': f[4], 'name': f[5],
                     'type': f[6], 'sub_type': f[7], 'active': f[8] == '1', 'surgeries': int(f[9]), 'favorites': int(f[10])})
known_codes = {m['code_hosp'] for m in medclick} | {m['code_amb'] for m in medclick if m['code_amb']}

# Répartition empirique spécialité MedClick x sous-article officiel (codes hospitaliers existants).
distribution = collections.defaultdict(collections.Counter)
for m in medclick:
    o = official.get(m['code_hosp'])
    if o:
        distribution[o['sub_art']][m['speciality']] += 1

# ---------------------------------------------------------------- Codes existants
dup = collections.Counter((m['speciality'], m['code_hosp']) for m in medclick)
existing = []
for m in medclick:
    oh, oa = official.get(m['code_hosp']), official.get(m['code_amb']) if m['code_amb'] else None
    sh, sa = status(oh), (status(oa) if m['code_amb'] else 'sans code ambulant')
    anomalies = []
    if dup[(m['speciality'], m['code_hosp'])] > 1:
        anomalies.append('doublon (même spécialité et même code hospitalier)')
    swapped = bool(oh and oa and oh['cat'] == '1' and oa['cat'] == '2')
    if swapped:
        anomalies.append('codes inversés : la colonne « hospitalisation » contient le code ambulant officiel (et inversement)')
    else:
        if oh and oh['cat'] not in ('2', '3'):
            anomalies.append(f"code hospitalier de catégorie « {oh['cat_desc']} »")
        if oa and oa['cat'] not in ('1', '3'):
            anomalies.append(f"code ambulant de catégorie « {oa['cat_desc']} »")
    if oh and oa and not swapped and oh['corresp'] and oh['corresp'] != m['code_amb']:
        anomalies.append(f"correspondance officielle ambulant {oh['corresp']} ≠ {m['code_amb']}")
    label_sim = similarity(m['name'], oh['desc_fr']) if oh else None
    label_status = None if oh is None else ('identique' if label_sim >= 0.97 else 'proche' if label_sim >= 0.80 else 'différent')
    key_diff = bool(oh and oh['key'] and oh['key'] != m['n'])
    expected = distribution.get(oh['sub_art']) if oh else None
    main_spec = expected.most_common(1)[0][0] if expected else None
    classification = None
    if oh and main_spec and main_spec != m['speciality']:
        classification = f"sous-article {oh['sub_art']} majoritairement « {main_spec} » dans MedClick"

    repeal = None
    action = 'inchangé'
    if sh == 'introuvable':
        action = 'à vérifier : code introuvable dans NomenSoft'
    elif sh == 'abrogé':
        effect = oh['dend'] + dt.timedelta(days=1)
        act = legal_act(m['code_hosp'], '-1', effect)
        repeal = {'date': effect.isoformat(), 'act': act, 'official_end': oh['dend'].isoformat()}
        if act and sa in ('abrogé', 'introuvable', 'sans code ambulant'):
            action = 'désactiver (abrogation établie)'
        elif act:
            action = f'à vérifier : code hospitalier abrogé mais code ambulant « {sa} »'
        else:
            action = 'à vérifier : date de fin passée sans acte de suppression correspondant'
    elif sh == 'abrogation programmée':
        effect = oh['dend'] + dt.timedelta(days=1)
        act = legal_act(m['code_hosp'], '-1', effect)
        repeal = {'date': effect.isoformat(), 'act': act, 'official_end': oh['dend'].isoformat()}
        action = 'abrogation programmée : date à enregistrer' if act else 'à vérifier : fin programmée sans acte de suppression'
    elif sh == 'pas encore en vigueur':
        action = 'à vérifier : code pas encore en vigueur'

    replacements = []
    if repeal:
        for code, o in official.items():
            if o['sub_art'] == oh['sub_art'] and o['cat'] == oh['cat'] and o['dbegin'] == oh['dend'] + dt.timedelta(days=1):
                s = similarity(oh['desc_fr'], o['desc_fr'])
                if s >= 0.6:
                    replacements.append({'code': code, 'similarity': round(s, 2), 'desc_fr': o['desc_fr'][:200]})
        replacements.sort(key=lambda x: -x['similarity'])

    existing.append({
        **m, 'status_hosp': sh, 'status_amb': sa,
        'official': None if oh is None else {k: (v.isoformat() if isinstance(v, dt.date) else v) for k, v in oh.items()},
        'creation': legal_act(m['code_hosp'], '0', oh['dbegin']) if oh else None,
        'label_similarity': None if label_sim is None else round(label_sim, 3), 'label_status': label_status,
        'key_official': oh['key'] if oh else None, 'key_diff': key_diff,
        'classification_note': classification, 'anomalies': anomalies,
        'repeal': repeal, 'replacements': replacements[:3], 'action': action,
    })

# ---------------------------------------------------------------- Nouveaux codes
SPECS = ['ortho', 'dig', 'general', 'uro', 'vasc', 'thor', 'plastic', 'neuro', 'transp']
ortho_known = [m for m in medclick if m['speciality'] == 'ortho' and m['type']]
REGIONS = [
    ('shoulder', ['epaule', 'scapul', 'omoplate', 'clavicul', 'acromio', 'coiffe']),
    ('humerus', ['humerus', 'humeral']),
    ('elbow', ['coude', 'olecran', 'radio humeral']),
    ('forearm', ['avant bras', 'radius', 'cubitus', 'ulna']),
    ('wristhand', ['poignet', 'main', 'doigt', 'carpe', 'metacarp', 'pouce', 'phalang', 'scaphoide', 'tendon flechisseur', 'dupuytren']),
    ('back', ['rachis', 'vertebr', 'disc', 'colonne', 'lombaire', 'cervical', 'dorsal', 'spinal', 'sacr']),
    ('pelvic', ['bassin', 'pelvi', 'acetabul', 'cotyle', 'sacro iliaque', 'symphyse']),
    ('hip', ['hanche', 'coxo femoral']),
    ('proximalFemur', ['col du femur', 'col femoral', 'trochanter', 'femur proximal']),
    ('midFemur', ['diaphyse femorale', 'diaphyse du femur']),
    ('distalFemur', ['femur distal', 'condyle femoral', 'supracondyl']),
    ('knee', ['genou', 'rotul', 'menisc', 'ligament croise', 'patella']),
    ('limb', ['jambe', 'tibia', 'perone', 'fibula']),
    ('ankle', ['cheville', 'malleol', 'astragale', 'talus']),
    ('foot', ['pied', 'orteil', 'calcaneum', 'metatars', 'hallux', 'tarse']),
]
# Liste fermée des régions (identique à l'administration, NomenclatureController::updateNomenclatureSubType).
ALLOWED_SUBTYPES = {'shoulder', 'humerus', 'elbow', 'forearm', 'wristhand', 'back', 'pelvic', 'hip', 'proximalFemur',
                    'midFemur', 'distalFemur', 'knee', 'limb', 'ankle', 'foot'}
# Valeurs tronquées héritées : normalisées pour les NOUVEAUX codes seulement (codes existants inchangés).
SUBTYPE_FIX = {'proximalFe': 'proximalFemur', 'distalFemu': 'distalFemur'}
TRAUMA = ['fracture', 'luxation', 'reduction', 'osteosynthese', 'traumat', 'arrachement']


def ortho_proposal(desc):
    best, best_sim = None, 0.0
    for m in ortho_known:
        s = similarity(desc, m['name'])
        if s > best_sim:
            best, best_sim = m, s
    words = norm(desc)
    regions = [r for r, keys in REGIONS if any(k in words for k in keys)]
    trauma = any(k in words for k in TRAUMA)
    keyword = {'type': '2' if trauma else '1', 'sub_type': regions[0] if len(regions) == 1 else None, 'regions': regions}
    if best and best_sim >= 0.90:
        return {'type': best['type'], 'sub_type': best['sub_type'], 'confidence': 'haute',
                'basis': f"libellé quasi identique au code MedClick {best['code_hosp']} (id {best['id']}, similarité {best_sim:.2f})", 'keyword': keyword}
    return {'type': keyword['type'], 'sub_type': keyword['sub_type'], 'confidence': 'à valider',
            'basis': f"mots-clés (régions : {', '.join(regions) or 'aucune'} ; traumatologie : {'oui' if trauma else 'non'})"
                     + (f" ; code MedClick le plus proche {best['code_hosp']} ({best_sim:.2f})" if best else ''), 'keyword': keyword}


new_codes = []
relevant_sub_arts = {s for s, c in distribution.items() if sum(c.values()) >= 1}
for code, o in official.items():
    if code in known_codes or o['cat'] not in ('2', '3') or status(o) not in ('valide', 'abrogation programmée'):
        continue
    # Prestation déjà présente via l'autre code de la paire officielle (codes inversés dans MedClick).
    if o['corresp'] and o['corresp'] in known_codes:
        continue
    if o['sub_art'] not in relevant_sub_arts:
        continue
    dist = distribution[o['sub_art']]
    total = sum(dist.values())
    spec, count = dist.most_common(1)[0]
    share = count / total
    amb = o['corresp'] if o['corresp'] and o['corresp'] in official and status(official[o['corresp']]) in ('valide', 'abrogation programmée') else ''
    reasons = []
    if share < 0.90:
        reasons.append('sous-article partagé entre spécialités MedClick : ' + ', '.join(f'{s} {n}' for s, n in dist.most_common()))
    if len(o['desc_fr']) > 500:
        reasons.append('libellé officiel > 500 caractères (limite des interventions)')
    if not o['key']:
        reasons.append('lettre-clé absente')
    if not o['sub_art'].startswith('14'):
        reasons.append(f"hors article 14 ({o['sub_art']}) : prestation non chirurgicale à confirmer")
    if o['sub_art'] == '14n':
        reasons.append("article 14 n) commun à l'orthopédie et à la neurochirurgie : spécialité à décider")
    twins = [m for m in medclick if norm(m['name']) == norm(o['desc_fr'])]
    if twins:
        reasons.append('libellé identique à un code MedClick existant (' + ', '.join(f"{t['code_hosp']} {t['speciality']}" for t in twins[:3])
                       + ') : modalité à préciser avant import')
    proposal = None
    if spec == 'ortho' and share >= 0.90:
        proposal = ortho_proposal(o['desc_fr'])
        proposal['sub_type'] = SUBTYPE_FIX.get(proposal['sub_type'], proposal['sub_type'])
        if proposal['confidence'] != 'haute':
            reasons.append('classification orthopédique (type / région) à valider')
        elif proposal['type'] not in ('1', '2') or proposal['sub_type'] not in ALLOWED_SUBTYPES:
            reasons.append(f"classification copiée incomplète (type {proposal['type'] or '—'}, région {proposal['sub_type'] or '—'}) à valider")
    new_codes.append({
        'code_hosp': code, 'code_amb': amb, 'n': o['key'], 'name': o['desc_fr'], 'sub_art': o['sub_art'], 'art': o['art'],
        'sub_art_desc': o['sub_art_desc'], 'dbegin': o['dbegin'].isoformat(), 'dend': o['dend'].isoformat(),
        'status': status(o), 'creation': legal_act(code, '0', o['dbegin']),
        'speciality': spec, 'speciality_share': round(share, 3), 'classification': proposal,
        'importable': not reasons, 'pending_reasons': reasons,
    })

json.dump({'today': TODAY.isoformat(), 'distribution': {k: dict(v) for k, v in distribution.items()},
           'existing': existing, 'new': new_codes}, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1, default=str)

# ---------------------------------------------------------------- Résumé
print('Répartition empirique (sous-article -> spécialités MedClick) :')
for s, c in sorted(distribution.items(), key=lambda x: -sum(x[1].values())):
    print(f'  {s:8} ' + ', '.join(f'{k} {v}' for k, v in c.most_common()))
print('\nCodes existants : actions')
for k, v in collections.Counter(e['action'] for e in existing).most_common():
    print(f'  {v:5}  {k}')
print('statuts hospitaliers :', collections.Counter(e['status_hosp'] for e in existing))
print('libellés :', collections.Counter(e['label_status'] for e in existing))
print('lettres-clés différentes :', sum(e['key_diff'] for e in existing))
print('anomalies :', collections.Counter(a.split(' ')[0] + ' ' + a.split(' ')[1] for e in existing for a in e['anomalies']))
print('\nNouveaux codes par spécialité (importables / à valider) :')
c = collections.defaultdict(lambda: [0, 0])
for n in new_codes:
    c[n['speciality']][0 if n['importable'] else 1] += 1
for s in SPECS:
    print(f'  {s:8} importables {c[s][0]:4}  à valider {c[s][1]:4}')

"""Plan d'import vérifié (JSON) à partir du rapprochement : seuls les changements établis."""
import datetime as dt
import hashlib
import json
import sys

analysis_path, txt_zip, out = sys.argv[1], sys.argv[2], sys.argv[3]
a = json.load(open(analysis_path, encoding='utf-8'))
sha = hashlib.sha256(open(txt_zip, 'rb').read()).hexdigest()


def fr(day):
    return dt.date.fromisoformat(day).strftime('%d/%m/%Y') if day else '—'


def act_text(act, effect_label):
    if not act:
        return ''
    head = f"INAMI NomenSoft 01.11.2026 — {act.get('doc')} (publ. {act.get('publication')}) {effect_label} {fr(act.get('effect'))} : "
    return (head + (act.get('label') or ''))[:255]


adds = []
for n in a['new']:
    if not n['importable']:
        continue
    c = n['classification'] or {}
    adds.append({
        'speciality': n['speciality'], 'codeHospitalisation': n['code_hosp'], 'codeAmbulant': n['code_amb'], 'n': n['n'],
        'name': n['name'], 'type': c.get('type') if n['speciality'] == 'ortho' else None,
        'subType': c.get('sub_type') if n['speciality'] == 'ortho' else None,
        'officialStart': n['dbegin'], 'reference': act_text(n['creation'], 'création au') or f"INAMI NomenSoft 01.11.2026 — en vigueur depuis le {fr(n['dbegin'])}",
    })

repeals = []
for e in a['existing']:
    if e['action'] != 'désactiver (abrogation établie)':
        continue
    repeals.append({
        'id': e['id'], 'codeHospitalisation': e['code_hosp'], 'repealedAt': e['repeal']['date'],
        'source': act_text(e['repeal']['act'], 'suppression au'),
    })

plan = {
    'source': {
        'label': 'INAMI NomenSoft — tableaux de référence applicables au 01/11/2026 (création 09/10/2026)',
        'url': 'https://www.inami.fgov.be/SiteCollectionDocuments/TXT.zip', 'sha256': sha,
        'page': 'https://www.inami.fgov.be/fr/nomenclature/tableaux-de-reference',
        'generated': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds'),
    },
    'add': adds, 'repeal': repeals,
}
json.dump(plan, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(f"plan : {len(adds)} ajout(s), {len(repeals)} abrogation(s) ; sources vides : {sum(1 for r in repeals if not r['source'])}")
print('exemple de source :', repeals[0]['source'] if repeals else '-')

"""Plan de désactivation administrative des lignes en doublon (ADR-212), d'après le plan de correction.

Usage : python make_plan_doublons.py <plan de correction> <export TSV MedClick> <plan de sortie>

L'export TSV (sans en-tête) : id, speciality, code_ambulant, code_hospitalisation, n, name, ...
Chaque ligne `unchanged` du plan de correction doit avoir le même libellé et la même valeur que la ligne corrigée qui
porte l'acte officiel.
"""
import csv
import datetime as dt
import json
import sys

COLUMNS = ['id', 'speciality', 'code_ambulant', 'code_hospitalisation', 'n', 'name']

if __name__ == '__main__':
    correction_path, tsv, out = sys.argv[1:4]
    correction = json.load(open(correction_path, encoding='utf-8'))
    csv.field_size_limit(10**8)
    rows = {int(r[0]): dict(zip(COLUMNS, r)) for r in csv.reader(open(tsv, encoding='utf-8', newline=''), delimiter='\t')}
    corrected = {item['id']: item for item in correction['correct']}

    lines = []
    for item in correction['unchanged']:
        row = rows[item['id']]
        kept = corrected[item['sameActAs']]
        if kept['name'] != row['name'] or kept['n'] != row['n'] or kept['speciality'] != row['speciality']:
            sys.exit(f"{item['id']} : autre acte que la ligne {item['sameActAs']}")
        codes = kept['after']
        lines.append({
            'id': item['id'], 'codeHospitalisation': row['code_hospitalisation'], 'codeAmbulant': row['code_ambulant'],
            'n': row['n'], 'speciality': row['speciality'], 'name': row['name'],
            'sameActAs': item['sameActAs'], 'sameActCodes': codes,
            'reason': f"Désactivation administrative (non abrogée INAMI) : doublon de la ligne {item['sameActAs']} ({codes['codeHospitalisation']}/{codes['codeAmbulant']})",
        })
    json.dump({
        'source': {
            'label': 'Lignes 14 n) en doublon : désactivation administrative, sans abrogation officielle',
            'correctionPlan': correction['source']['sha256'],
            'generated': dt.datetime.now(dt.timezone.utc).isoformat(timespec='seconds'),
        },
        'deactivate': sorted(lines, key=lambda line: line['id']),
    }, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(f"plan : {[(line['id'], line['sameActAs']) for line in lines]}")

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { normalize, parseNK, buildIndex, filterIndex, sortRows, highlightParts } from '../src/features/inami/lib/search.js';

const data = [
  { name: 'Ostéosynthèse d’une fracture de la cheville', codeAmbulant: '', codeHospitalisation: '293113', n: 'K 225' },
  { name: 'Arthroscopie diagnostique du genou', codeAmbulant: '300292', codeHospitalisation: '300303', n: 'K120' },
  { name: 'Infiltration intra-articulaire', codeAmbulant: '355132', codeHospitalisation: '355143', n: 'N 15' },
  { name: 'Arthroplastie totale du genou', codeAmbulant: null, codeHospitalisation: 289414, n: 500 },
];
const idx = buildIndex(data);

test('normalize retire accents et casse', () => {
  assert.equal(normalize('ÉVEINAGE Ostéo'), 'eveinage osteo');
});
test('parseNK lit plusieurs formats', () => {
  assert.deepEqual([parseNK('K 225').letter, parseNK('K 225').value], ['K', '225']);
  assert.deepEqual([parseNK('k120').letter, parseNK('k120').value], ['K', '120']);
  assert.deepEqual([parseNK(500).letter, parseNK(500).value], [null, '500']);
  assert.equal(parseNK('').value, null);
});
test('recherche texte sans accents, plusieurs mots', () => {
  assert.equal(filterIndex(idx, 'osteo').length, 1);
  assert.equal(filterIndex(idx, 'GENOU').length, 2);
  assert.equal(filterIndex(idx, 'totale genou').length, 1);
  assert.equal(filterIndex(idx, 'zzz').length, 0);
});
test('recherche par code ambulatoire ou hospitalisation', () => {
  assert.equal(filterIndex(idx, '3002').length, 1);
  assert.equal(filterIndex(idx, '289 414').length, 1);
  assert.equal(filterIndex(idx, '355143').length, 1);
});
test('tri N/K : lettre puis valeur', () => {
  const s = sortRows(idx, { key: 'nk', dir: 1 }).map((r) => r.nk.raw);
  assert.deepEqual(s, ['500', 'K120', 'K 225', 'N 15']);
});
test('tri pertinence : commence par la recherche en premier', () => {
  const s = sortRows(filterIndex(idx, 'arthro'), { key: 'relevance', query: 'arthro' }).map((r) => r.name);
  assert.equal(s.length, 2);
});
test('surlignage insensible aux accents', () => {
  const p = highlightParts('Ostéosynthèse de la cheville', 'osteo');
  assert.deepEqual(p[0], { text: 'Ostéo', hit: true });
  assert.equal(p.map((x) => x.text).join(''), 'Ostéosynthèse de la cheville');
});

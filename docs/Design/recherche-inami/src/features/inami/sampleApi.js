/**
 * API d'exemple — DONNÉES FICTIVES (noms, codes et valeurs N/K inventés, format seulement).
 * À remplacer par vos appels réels : voir README, section « Brancher les données ».
 */

const SPECIALITIES = [
  { id: 'chirurgie-orthopedique', label: 'Chirurgie orthopédique' },
  { id: 'chirurgie-digestive', label: 'Chirurgie digestive' },
  { id: 'urologie', label: 'Urologie' },
  { id: 'chirurgie-vasculaire', label: 'Chirurgie vasculaire' },
  { id: 'chirurgie-plastique', label: 'Chirurgie plastique' },
];

const ROWS = {
  'chirurgie-orthopedique': [
    ['Arthroplastie totale du genou', '', '289414', 'K 500'],
    ['Prothèse unicompartimentale du genou', '', '289436', 'K 450'],
    ['Reprise de prothèse totale du genou', '', '289451', 'K 650'],
    ['Plastie du ligament croisé antérieur sous arthroscopie', '', '300255', 'K 300'],
    ['Méniscectomie sous arthroscopie', '300270', '300281', 'K 180'],
    ['Arthroscopie diagnostique du genou', '300292', '300303', 'K 120'],
    ['Arthroplastie totale de la hanche', '', '289031', 'K 500'],
    ['Ostéosynthèse d’une fracture de la cheville', '', '293113', 'K 225'],
    ['Ostéosynthèse d’une fracture du poignet', '293135', '293146', 'K 200'],
    ['Ablation de matériel d’ostéosynthèse', '293555', '293566', 'K 75'],
    ['Libération du nerf médian au canal carpien', '294514', '294525', 'K 100'],
    ['Suture tendineuse de la main', '294551', '294562', 'K 150'],
    ['Infiltration intra-articulaire', '355132', '355143', 'N 15'],
    ['Ostéotomie tibiale de valgisation', '', '289555', 'K 350'],
    ['Arthrodèse de la cheville', '', '289570', 'K 400'],
    ['Réparation de la coiffe des rotateurs sous arthroscopie', '', '300453', 'K 350'],
    ['Cure d’hallux valgus avec ostéotomie', '', '289713', 'K 250'],
  ],
  'chirurgie-digestive': [
    ['Cholécystectomie par laparoscopie', '', '242893', 'K 300'],
    ['Appendicectomie par laparoscopie', '', '241113', 'K 225'],
    ['Cure de hernie inguinale', '241216', '241220', 'K 180'],
    ['Hémorroïdectomie', '244271', '244282', 'K 150'],
    ['Colectomie segmentaire', '', '243233', 'K 600'],
    ['Gastroscopie diagnostique', '473070', '473081', 'N 70'],
  ],
  urologie: [
    ['Résection transurétrale de la prostate', '', '260573', 'K 375'],
    ['Cystoscopie diagnostique', '261074', '261085', 'K 60'],
    ['Néphrectomie partielle', '', '260271', 'K 600'],
    ['Vasectomie bilatérale', '261390', '261401', 'K 90'],
  ],
  'chirurgie-vasculaire': [
    ['Éveinage de la grande veine saphène', '', '238075', 'K 225'],
    ['Endartériectomie carotidienne', '', '237433', 'K 500'],
    ['Création d’une fistule artério-veineuse', '238156', '238160', 'K 250'],
  ],
  'chirurgie-plastique': [
    ['Exérèse d’une lésion cutanée avec suture', '252891', '252902', 'K 30'],
    ['Greffe de peau mince', '', '253532', 'K 150'],
    ['Réduction mammaire bilatérale', '', '253775', 'K 450'],
  ],
};

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

export const sampleApi = {
  /** → [{ id, label }] */
  async getSpecialities() {
    await wait(150);
    return SPECIALITIES;
  },
  /** speciality = id, ou 'all' → [{ name, codeAmbulant, codeHospitalisation, n, speciality }] */
  async getCodes(speciality) {
    await wait(350);
    const ids = speciality === 'all' ? Object.keys(ROWS) : [speciality];
    return ids.flatMap((id) =>
      (ROWS[id] || []).map(([name, codeAmbulant, codeHospitalisation, n]) => ({
        name, codeAmbulant, codeHospitalisation, n, speciality: id,
      }))
    );
  },
};

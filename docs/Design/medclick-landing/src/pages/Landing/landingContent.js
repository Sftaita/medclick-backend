/**
 * Textes de la page d'accueil publique MedClick.
 * Modifiez ce fichier pour changer les textes sans toucher au composant.
 */

export const landingConfig = {
  // Routes de l'application (à adapter à votre routeur)
  loginHref: '/login',
  registerHref: '/register',
  termsHref: '/conditions-utilisation',
  partnersHref: '/partenaires',

  // Laisser vide pour masquer les liens « Nous contacter » / « Écrivez-nous »
  contactEmail: '',

  version: '3.1',
};

export const hero = {
  eyebrow: 'Carnet de stage numérique pour médecins en formation',
  titleStart: 'Le carnet de stage qui vous suit',
  titleAccent: "du bloc à l'export officiel.",
  lead:
    'Encodez vos interventions, consultations, gardes et formations depuis votre téléphone, suivez votre progression semaine après semaine, et exportez votre carnet officiel en un clic.',
  primaryCta: 'Créer mon compte',
  secondaryCta: "J'ai déjà un compte",
  trust: [
    'Créé par des assistants, pour des assistants',
    'iPhone, Android et ordinateur',
    'Sans passer par un store',
  ],
};

export const activityTypes = [
  { key: 'interventions', title: 'Interventions', sub: 'Nomenclature INAMI', tone: 'blue', icon: 'scalpel' },
  { key: 'consultations', title: 'Consultations', sub: 'Par journée', tone: 'green', icon: 'stethoscope' },
  { key: 'gardes', title: 'Gardes', sub: 'Jour, nuit, week-end', tone: 'purple', icon: 'moon' },
  { key: 'formations', title: 'Formations', sub: 'Cours, congrès, ateliers', tone: 'orange', icon: 'cap' },
];

export const steps = [
  {
    title: 'Créez votre compte',
    text: "Votre nom, votre e-mail et votre spécialité. Vous activez le compte depuis l'e-mail reçu.",
  },
  {
    title: 'Encodez au fil des jours',
    text: 'Après chaque journée opératoire, consultation ou garde. MedClick vous signale les jours oubliés.',
  },
  {
    title: 'Exportez votre carnet',
    text: "En fin d'année, téléchargez le carnet officiel au format Excel. Vos années précédentes restent disponibles.",
  },
];

export const origin = {
  kicker: "Qu'est-ce que MedClick ?",
  title: 'Une solution créée par des assistants, pour des assistants.',
  text:
    "MedClick est pensé par des médecins en formation qui encodent eux-mêmes leurs journées. Chaque écran part d'un besoin réel du terrain : aller vite entre deux interventions et ne rien oublier pour le carnet.",
};

/**
 * FAQ. `answer` est un tableau de paragraphes ; `steps` (optionnel) ajoute une liste numérotée.
 */
export const faq = [
  {
    question: "Comment installer l'application ?",
    answer: [
      "MedClick est une progressive web app : elle s'installe directement depuis votre navigateur, sans passer par un store. Rendez-vous dans les options du navigateur et choisissez « Ajouter à l'écran d'accueil ».",
    ],
    steps: [
      ['iPhone', "ouvrez medclick.be dans Safari, touchez Partager, puis « Sur l'écran d'accueil »."],
      ['Android', "ouvrez medclick.be dans Chrome, menu ⋮, puis « Installer l'application »."],
      ['Ordinateur', 'utilisez simplement medclick.be dans votre navigateur.'],
    ],
  },
  {
    question: 'Est-ce payant ? Cela le deviendra-t-il ?',
    answer: [
      "Pour le moment, l'application est gratuite dans sa version bêta. Nous réfléchissons à des moyens de financement.",
    ],
  },
  {
    question: "Que faites-vous des informations que j'enregistre ?",
    answer: [
      'Vos informations sont principalement utilisées pour générer vos statistiques personnelles. Certaines données anonymisées peuvent être utilisées à des fins marketing.',
    ],
  },
  {
    question: "Comment contribuer à améliorer l'application ?",
    answer: [
      "N'hésitez pas à nous contacter pour signaler un bug ou proposer une idée afin d'améliorer MedClick. L'application se met à jour d'elle-même : quand une nouvelle version est disponible, un bandeau vous propose de recharger.",
    ],
  },
];

export const finalCta = {
  title: "Commencez votre carnet aujourd'hui.",
  text: "Apprendre aujourd'hui, la pratique de demain.",
};

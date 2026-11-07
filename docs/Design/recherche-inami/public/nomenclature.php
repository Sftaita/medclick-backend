<?php
// Aperçu local uniquement : lit la nomenclature ACTUELLE de la base de développement, en lecture
// seule, à chaque chargement. Aucune copie statique, aucun identifiant dans ce fichier : la
// connexion est lue dans le .env.local / .env du backend (jamais versionnés).
//
// Garde-fous :
// - répond 404 hors du serveur intégré de PHP (php -S) : jamais exécuté par Apache, LiteSpeed…
//   même si le dossier docs/ était exposé par erreur ;
// - n'accepte que les requêtes venant de la machine elle-même (127.0.0.1 / ::1) ;
// - refuse toute base qui n'est pas locale (évite d'interroger la production par erreur) ;
// - requête fixe en lecture seule, sans aucun paramètre venant de la requête ;
// - aucun détail d'erreur renvoyé (ni message PDO, ni chemin, ni nom d'utilisateur).
// La vraie route publique sera un contrôleur Symfony : ce fichier ne doit jamais être déployé.

$deny = static function (int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erreur' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

if (PHP_SAPI !== 'cli-server') {
    $deny(404, 'Introuvable.');
}
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    $deny(403, 'Aperçu réservé à la machine locale.');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $deny(405, 'Lecture seule.');
}

$root = dirname(__DIR__, 4);
$url = null;
foreach (['.env.local', '.env'] as $file) {
    $path = $root.'/'.$file;
    if (is_file($path) && preg_match('/^DATABASE_URL="?([^"\r\n]+)"?/m', (string) file_get_contents($path), $m)) {
        $url = $m[1];
        break;
    }
}

$db = $url ? parse_url($url) : false;
if (!$db || ($db['scheme'] ?? '') !== 'mysql' || empty($db['path'])) {
    $deny(503, 'Base MySQL locale non configurée (DATABASE_URL dans le .env du backend).');
}
if (!in_array($db['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
    $deny(503, "Base non locale : l'aperçu ne lit que la base de développement.");
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'] ?? 3306, ltrim($db['path'], '/')),
        urldecode($db['user'] ?? ''),
        urldecode($db['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rows = $pdo->query('SELECT name, code_ambulant, code_hospitalisation, n, speciality FROM nomenclature ORDER BY id')
        ->fetchAll(PDO::FETCH_NUM);
} catch (PDOException) {
    $deny(503, 'Base MySQL locale indisponible (WAMP démarré ?).');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);

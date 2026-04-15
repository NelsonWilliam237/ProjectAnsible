<?php
// Connexion base de données
$host     = getenv('DB_HOST') ?: '10.0.2.13';
$dbname   = getenv('DB_NAME') ?: 'app_db';
$username = getenv('DB_USER') ?: 'app_user';
$password = getenv('DB_PASSWORD');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('<div style="padding:2rem;color:red">Erreur DB : ' . htmlspecialchars($e->getMessage()) . '</div>');
}

// Créer la table si elle n'existe pas
$pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(255) NOT NULL,
    description TEXT,
    priorite ENUM('faible','moyenne','haute','critique') DEFAULT 'moyenne',
    statut ENUM('ouvert','en_cours','resolu') DEFAULT 'ouvert',
    auteur VARCHAR(100) DEFAULT 'Anonyme',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Insérer données de démo si table vide
$count = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
if ($count == 0) {
    $pdo->exec("INSERT INTO tickets (titre, description, priorite, statut, auteur) VALUES
        ('Serveur web inaccessible', 'Le site principal retourne une erreur 502 depuis 10 minutes.', 'critique', 'ouvert', 'Alice Martin'),
        ('Mise à jour SSL requise', 'Le certificat SSL expire dans 7 jours sur le domaine principal.', 'haute', 'en_cours', 'Bob Tremblay'),
        ('Lenteur base de données', 'Les requêtes prennent plus de 3 secondes en heure de pointe.', 'haute', 'ouvert', 'Alice Martin'),
        ('Ajouter monitoring Grafana', 'Configurer un dashboard Grafana pour surveiller les métriques serveur.', 'moyenne', 'en_cours', 'Carlos Diaz'),
        ('Mettre à jour PHP 8.2', 'Migration de PHP 8.1 vers 8.2 sur les serveurs de production.', 'moyenne', 'ouvert', 'Bob Tremblay'),
        ('Nettoyer les logs anciens', 'Les logs datant de plus de 90 jours occupent 40 Go de disque.', 'faible', 'resolu', 'Carlos Diaz'),
        ('Backup base de données', 'Automatiser les sauvegardes quotidiennes de MySQL vers S3.', 'haute', 'ouvert', 'Alice Martin'),
        ('Documentation API manquante', 'La nouvelle API REST n a pas de documentation Swagger.', 'faible', 'resolu', 'Bob Tremblay')
    ");
}

// Actions POST
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $titre  = trim($_POST['titre'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $prio   = $_POST['priorite'] ?? 'moyenne';
        $auteur = trim($_POST['auteur'] ?? 'Anonyme');
        if ($titre) {
            $stmt = $pdo->prepare("INSERT INTO tickets (titre, description, priorite, auteur) VALUES (?, ?, ?, ?)");
            $stmt->execute([$titre, $desc, $prio, $auteur]);
            $message = 'success:Ticket créé avec succès.';
        }
    } elseif ($action === 'update_statut') {
        $id     = (int)($_POST['id'] ?? 0);
        $statut = $_POST['statut'] ?? '';
        if ($id && in_array($statut, ['ouvert','en_cours','resolu'])) {
            $pdo->prepare("UPDATE tickets SET statut=? WHERE id=?")->execute([$statut, $id]);
            $message = 'success:Statut mis à jour.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM tickets WHERE id=?")->execute([$id]);
            $message = 'success:Ticket supprimé.';
        }
    }
    header("Location: ?msg=" . urlencode($message));
    exit;
}

if (isset($_GET['msg'])) $message = $_GET['msg'];

// Filtres
$filtre_statut = $_GET['statut'] ?? '';
$filtre_prio   = $_GET['priorite'] ?? '';
$where = []; $params = [];
if ($filtre_statut) { $where[] = "statut = ?"; $params[] = $filtre_statut; }
if ($filtre_prio)   { $where[] = "priorite = ?"; $params[] = $filtre_prio; }
$sql = "SELECT * FROM tickets" . ($where ? " WHERE " . implode(" AND ", $where) : "") . " ORDER BY FIELD(priorite,'critique','haute','moyenne','faible'), created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compteurs
$stats = $pdo->query("SELECT statut, COUNT(*) as n FROM tickets GROUP BY statut")->fetchAll(PDO::FETCH_KEY_PAIR);
$total   = array_sum($stats);
$ouverts = $stats['ouvert'] ?? 0;
$encours = $stats['en_cours'] ?? 0;
$resolus = $stats['resolu'] ?? 0;

$prio_colors = ['critique'=>'#dc2626','haute'=>'#ea580c','moyenne'=>'#2563eb','faible'=>'#16a34a'];
$prio_bg     = ['critique'=>'#fef2f2','haute'=>'#fff7ed','moyenne'=>'#eff6ff','faible'=>'#f0fdf4'];
$statut_colors = ['ouvert'=>'#dc2626','en_cours'=>'#d97706','resolu'=>'#16a34a'];
$statut_bg     = ['ouvert'=>'#fef2f2','en_cours'=>'#fffbeb','resolu'=>'#f0fdf4'];
$statut_labels = ['ouvert'=>'Ouvert','en_cours'=>'En cours','resolu'=>'Résolu'];
$prio_labels   = ['faible'=>'Faible','moyenne'=>'Moyenne','haute'=>'Haute','critique'=>'Critique'];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DevLaunch — Système de Ticketing</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;color:#1e293b;min-height:100vh}
header{background:#1e40af;color:#fff;padding:0 2rem;height:60px;display:flex;align-items:center;justify-content:space-between}
header h1{font-size:18px;font-weight:600;letter-spacing:-.3px}
header span{font-size:13px;opacity:.75}
.container{max-width:1100px;margin:0 auto;padding:2rem}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:2rem}
.stat{background:#fff;border-radius:12px;padding:1.25rem;border:1px solid #e2e8f0}
.stat .n{font-size:32px;font-weight:700;line-height:1}
.stat .l{font-size:13px;color:#64748b;margin-top:4px}
.stat.total .n{color:#1e40af}
.stat.ouvert .n{color:#dc2626}
.stat.encours .n{color:#d97706}
.stat.resolu .n{color:#16a34a}
.toolbar{display:flex;gap:10px;margin-bottom:1.5rem;flex-wrap:wrap;align-items:center}
.toolbar select{padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff;color:#1e293b}
.btn-new{margin-left:auto;background:#1e40af;color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer}
.btn-new:hover{background:#1d4ed8}
.table-wrap{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:2rem}
table{width:100%;border-collapse:collapse;font-size:14px}
thead{background:#f1f5f9}
th{padding:12px 16px;text-align:left;font-weight:500;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
td{padding:12px 16px;border-top:1px solid #f1f5f9;vertical-align:middle}
tr:hover td{background:#fafbfc}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:500}
.ticket-title{font-weight:500;color:#1e293b;margin-bottom:2px}
.ticket-meta{font-size:12px;color:#94a3b8}
.actions{display:flex;gap:6px;align-items:center}
.btn-sm{padding:5px 10px;border-radius:6px;font-size:12px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;color:#475569}
.btn-sm:hover{background:#f1f5f9}
.btn-del{border-color:#fecaca;color:#dc2626}
.btn-del:hover{background:#fef2f2}
.select-sm{padding:5px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;background:#fff;color:#1e293b}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;padding:2rem;width:100%;max-width:480px;margin:1rem}
.modal h2{font-size:18px;font-weight:600;margin-bottom:1.5rem;color:#1e293b}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:13px;font-weight:500;color:#374151;margin-bottom:4px}
.form-group input,.form-group textarea,.form-group select{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;color:#1e293b}
.form-group textarea{resize:vertical;min-height:80px}
.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:1.5rem}
.btn-cancel{padding:9px 18px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;font-size:14px;cursor:pointer;color:#475569}
.btn-submit{padding:9px 18px;border:none;border-radius:8px;background:#1e40af;color:#fff;font-size:14px;font-weight:500;cursor:pointer}
.alert{padding:12px 16px;border-radius:8px;font-size:14px;margin-bottom:1.5rem}
.alert.success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.empty{text-align:center;padding:3rem;color:#94a3b8;font-size:14px}
@media(max-width:700px){.stats{grid-template-columns:repeat(2,1fr)}.toolbar{flex-direction:column;align-items:stretch}.btn-new{margin-left:0}}
</style>
</head>
<body>
<header>
  <h1>DevLaunch — Support Ticketing</h1>
  <span>Déployé avec Ansible sur AWS · <?= gethostname() ?></span>
</header>
<div class="container">

<?php if ($message): list($type,$txt) = explode(':', $message, 2); ?>
<div class="alert <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($txt) ?></div>
<?php endif; ?>

<div class="stats">
  <div class="stat total"><div class="n"><?= $total ?></div><div class="l">Total tickets</div></div>
  <div class="stat ouvert"><div class="n"><?= $ouverts ?></div><div class="l">Ouverts</div></div>
  <div class="stat encours"><div class="n"><?= $encours ?></div><div class="l">En cours</div></div>
  <div class="stat resolu"><div class="n"><?= $resolus ?></div><div class="l">Résolus</div></div>
</div>

<div class="toolbar">
  <select onchange="location='?statut='+this.value+'&priorite=<?= urlencode($filtre_prio) ?>'">
    <option value="" <?= !$filtre_statut?'selected':'' ?>>Tous les statuts</option>
    <?php foreach($statut_labels as $k=>$v): ?>
    <option value="<?=$k?>" <?= $filtre_statut===$k?'selected':'' ?>><?=$v?></option>
    <?php endforeach; ?>
  </select>
  <select onchange="location='?statut=<?= urlencode($filtre_statut) ?>&priorite='+this.value">
    <option value="" <?= !$filtre_prio?'selected':'' ?>>Toutes les priorités</option>
    <?php foreach($prio_labels as $k=>$v): ?>
    <option value="<?=$k?>" <?= $filtre_prio===$k?'selected':'' ?>><?=$v?></option>
    <?php endforeach; ?>
  </select>
  <?php if($filtre_statut||$filtre_prio): ?>
  <a href="?" style="font-size:13px;color:#64748b;text-decoration:none">Effacer filtres</a>
  <?php endif; ?>
  <button class="btn-new" onclick="document.getElementById('modal-new').classList.add('open')">+ Nouveau ticket</button>
</div>

<div class="table-wrap">
<?php if (empty($tickets)): ?>
  <div class="empty">Aucun ticket trouvé pour ces filtres.</div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Titre</th>
      <th>Priorité</th>
      <th>Statut</th>
      <th>Auteur</th>
      <th>Date</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($tickets as $t): ?>
  <tr>
    <td style="color:#94a3b8;font-size:13px">#<?= $t['id'] ?></td>
    <td>
      <div class="ticket-title"><?= htmlspecialchars($t['titre']) ?></div>
      <?php if($t['description']): ?>
      <div class="ticket-meta"><?= htmlspecialchars(mb_substr($t['description'],0,60)) ?><?= mb_strlen($t['description'])>60?'…':'' ?></div>
      <?php endif; ?>
    </td>
    <td>
      <span class="badge" style="background:<?= $prio_bg[$t['priorite']] ?>;color:<?= $prio_colors[$t['priorite']] ?>">
        <?= $prio_labels[$t['priorite']] ?>
      </span>
    </td>
    <td>
      <span class="badge" style="background:<?= $statut_bg[$t['statut']] ?>;color:<?= $statut_colors[$t['statut']] ?>">
        <?= $statut_labels[$t['statut']] ?>
      </span>
    </td>
    <td style="font-size:13px;color:#475569"><?= htmlspecialchars($t['auteur']) ?></td>
    <td style="font-size:12px;color:#94a3b8"><?= date('d/m/Y',strtotime($t['created_at'])) ?></td>
    <td>
      <div class="actions">
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="update_statut">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <select name="statut" class="select-sm" onchange="this.form.submit()">
            <?php foreach($statut_labels as $k=>$v): ?>
            <option value="<?=$k?>" <?= $t['statut']===$k?'selected':'' ?>><?=$v?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce ticket ?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $t['id'] ?>">
          <button type="submit" class="btn-sm btn-del">Supprimer</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

</div>

<div class="modal-overlay" id="modal-new" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal">
    <h2>Nouveau ticket</h2>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label>Titre *</label>
        <input type="text" name="titre" placeholder="Décrivez le problème en une ligne" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" placeholder="Détails, étapes pour reproduire, impact..."></textarea>
      </div>
      <div class="form-group">
        <label>Auteur</label>
        <input type="text" name="auteur" placeholder="Votre nom" value="Admin">
      </div>
      <div class="form-group">
        <label>Priorité</label>
        <select name="priorite">
          <option value="faible">Faible</option>
          <option value="moyenne" selected>Moyenne</option>
          <option value="haute">Haute</option>
          <option value="critique">Critique</option>
        </select>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-cancel" onclick="document.getElementById('modal-new').classList.remove('open')">Annuler</button>
        <button type="submit" class="btn-submit">Créer le ticket</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
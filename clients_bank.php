<?php
// ============================================================
// CONNEXION — fichier config existant
// ============================================================
require_once 'config.php'; // $conn déjà défini
require_once 'bank_helpers.php';

ensure_clients_bank_total_ventes($conn);
refresh_client_total_ventes($conn);

// ============================================================
// TRAITEMENT DES REQUÊTES AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // --- AJOUTER ---
    if ($action === 'ajouter') {
        $nom     = trim($_POST['nom']);
        $adresse = trim($_POST['adresse']);
        $nrs     = trim($_POST['nrs']);
        $nai     = trim($_POST['nai']);
        $nif     = trim($_POST['nif']);
        $nis     = trim($_POST['nis']);

        if (empty($nom)) {
            echo json_encode(['success' => false, 'message' => 'Le nom est obligatoire.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO clients_bank (nom, adresse, nrs, nai, nif, nis) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $nom, $adresse, $nrs, $nai, $nif, $nis);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Client ajouté avec succès.', 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'ajout.']);
        }
        $stmt->close();
        exit;
    }

    // --- MODIFIER ---
    if ($action === 'modifier') {
        $id      = intval($_POST['id']);
        $nom     = trim($_POST['nom']);
        $adresse = trim($_POST['adresse']);
        $nrs     = trim($_POST['nrs']);
        $nai     = trim($_POST['nai']);
        $nif     = trim($_POST['nif']);
        $nis     = trim($_POST['nis']);

        if ($id <= 0 || empty($nom)) {
            echo json_encode(['success' => false, 'message' => 'Données invalides.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE clients_bank SET nom=?, adresse=?, nrs=?, nai=?, nif=?, nis=? WHERE id=?");
        $stmt->bind_param("ssssssi", $nom, $adresse, $nrs, $nai, $nif, $nis, $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Client modifié avec succès.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la modification.']);
        }
        $stmt->close();
        exit;
    }

    // --- SUPPRIMER ---
    if ($action === 'supprimer') {
        $id = intval($_POST['id']);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID invalide.']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM clients_bank WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Client supprimé.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression.']);
        }
        $stmt->close();
        exit;
    }
}

// ============================================================
// CHARGEMENT DES CLIENTS
// ============================================================
$clients = [];
$result = $conn->query("SELECT * FROM clients_bank ORDER BY id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Clients — Warehouse</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:   #0f766e;
            --primary-h: #0d6460;
            --danger:    #dc2626;
            --danger-h:  #b91c1c;
            --warning:   #d97706;
            --warning-h: #b45309;
            --success:   #16a34a;
            --bg:        #f0fdfa;
            --card:      #ffffff;
            --border:    #ccfbf1;
            --border2:   #e2e8f0;
            --text:      #1e293b;
            --muted:     #64748b;
            --radius:    10px;
            --shadow:    0 2px 12px rgba(0,0,0,.08);
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        /* HEADER */
        header {
            background: var(--primary);
            color: #fff;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(15,118,110,.35);
        }
        header .icon { font-size: 1.6rem; }
        header h1   { font-size: 1.25rem; font-weight: 700; }
        header span { font-size: .85rem; opacity: .8; margin-left: auto; }

        /* LAYOUT */
        .container {
            max-width: 1150px;
            margin: 0 auto;
            padding: 24px 16px;
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 820px) {
            .container { grid-template-columns: 1fr; }
        }

        /* CARD */
        .card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border2);
            overflow: hidden;
        }
        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border2);
            font-weight: 700;
            font-size: .95rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* FORMULAIRE */
        .form-body { padding: 18px 20px; }

        .field { margin-bottom: 14px; }
        .field label {
            display: block;
            font-size: .78rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .field input, .field textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--border2);
            border-radius: 7px;
            font-size: .93rem;
            color: var(--text);
            background: #f8fafc;
            transition: border-color .2s;
            outline: none;
            font-family: inherit;
        }
        .field textarea { resize: none; height: 64px; }
        .field input:focus, .field textarea:focus {
            border-color: var(--primary);
            background: #fff;
        }

        /* Grille 2 colonnes pour les numéros */
        .fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 14px;
        }

        /* BOUTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            border: none;
            border-radius: 7px;
            font-size: .88rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .18s, transform .1s;
            font-family: inherit;
        }
        .btn:active { transform: scale(.97); }
        .btn-primary  { background: var(--primary);  color: #fff; }
        .btn-primary:hover  { background: var(--primary-h); }
        .btn-danger   { background: var(--danger);   color: #fff; }
        .btn-danger:hover   { background: var(--danger-h); }
        .btn-warning  { background: var(--warning);  color: #fff; }
        .btn-warning:hover  { background: var(--warning-h); }
        .btn-cancel   { background: #e2e8f0; color: var(--text); }
        .btn-cancel:hover   { background: #cbd5e1; }
        .btn-sm   { padding: 5px 11px; font-size: .78rem; }
        .btn-full { width: 100%; justify-content: center; margin-top: 4px; }

        /* TOAST */
        #toast {
            position: fixed;
            bottom: 24px; right: 24px;
            padding: 12px 20px;
            border-radius: 8px;
            color: #fff;
            font-weight: 600;
            font-size: .9rem;
            box-shadow: 0 4px 16px rgba(0,0,0,.18);
            opacity: 0;
            transform: translateY(10px);
            transition: opacity .3s, transform .3s;
            z-index: 999;
            pointer-events: none;
        }
        #toast.show { opacity: 1; transform: translateY(0); }
        #toast.ok  { background: var(--success); }
        #toast.err { background: var(--danger); }

        /* TABLE TOP */
        .table-top {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border2);
        }
        #search {
            flex: 1;
            padding: 8px 12px;
            border: 1.5px solid var(--border2);
            border-radius: 7px;
            font-size: .88rem;
            outline: none;
            font-family: inherit;
        }
        #search:focus { border-color: var(--primary); }
        .count { font-size: .8rem; color: var(--muted); white-space: nowrap; }

        /* TABLEAU */
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
        }
        thead tr { background: #f0fdfa; }
        thead th {
            padding: 11px 12px;
            text-align: left;
            font-size: .75rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            border-bottom: 2px solid var(--border2);
            white-space: nowrap;
        }
        tbody tr { border-bottom: 1px solid var(--border2); transition: background .15s; }
        tbody tr:hover { background: #f0fdfa; }
        tbody tr.editing { background: #fffbeb; }
        td {
            padding: 10px 12px;
            vertical-align: middle;
        }
        td.id-col { color: var(--muted); font-size: .78rem; width: 36px; }
        td.total-col { text-align: right; font-weight: 700; color: var(--primary); white-space: nowrap; }

        /* Inline edit */
        td input.ie, td textarea.ie {
            width: 100%;
            padding: 5px 7px;
            border: 1.5px solid var(--primary);
            border-radius: 5px;
            font-size: .83rem;
            outline: none;
            font-family: inherit;
            min-width: 70px;
        }
        td textarea.ie { resize: none; height: 48px; }

        .actions { display: flex; gap: 5px; flex-wrap: wrap; }

        /* badge numéro */
        .num-badge {
            display: inline-block;
            background: #f0fdfa;
            color: var(--primary);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 2px 7px;
            font-size: .78rem;
            font-family: monospace;
            font-weight: 600;
        }

        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }
        .empty .icon { font-size: 2.5rem; margin-bottom: 8px; }
    </style>
</head>
<body>

<header>
    <span class="icon">👥</span>
    <h1>Gestion des Clients</h1>
    <span>warehouse_db</span>
</header>

<div id="toast"></div>

<div class="container">

    <!-- ========== FORMULAIRE ========== -->
    <div class="card">
        <div class="card-header">➕ Ajouter un client</div>
        <div class="form-body">

            <div class="field">
                <label>Nom *</label>
                <input type="text" id="f_nom" placeholder="Nom du client" maxlength="150">
            </div>

            <div class="field">
                <label>Adresse</label>
                <textarea id="f_adresse" placeholder="Adresse complète"></textarea>
            </div>

            <div class="fields-grid">
                <div class="field">
                    <label>N.R.S</label>
                    <input type="text" id="f_nrs" placeholder="Numéro R.S" maxlength="30">
                </div>
                <div class="field">
                    <label>N.A.I</label>
                    <input type="text" id="f_nai" placeholder="Numéro A.I" maxlength="30">
                </div>
                <div class="field">
                    <label>N.I.F</label>
                    <input type="text" id="f_nif" placeholder="Numéro I.F" maxlength="30">
                </div>
                <div class="field">
                    <label>N.I.S</label>
                    <input type="text" id="f_nis" placeholder="Numéro I.S" maxlength="30">
                </div>
            </div>

            <button class="btn btn-primary btn-full" onclick="ajouterClient()">
                ➕ Ajouter le client
            </button>
        </div>
    </div>

    <!-- ========== TABLEAU ========== -->
    <div class="card">
        <div class="card-header">📋 Liste des clients</div>
        <div class="table-top">
            <input type="text" id="search" placeholder="🔍  Rechercher par nom..." oninput="filtrerTable()">
            <span class="count" id="count_label"></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nom</th>
                        <th>Adresse</th>
                        <th>N.R.S</th>
                        <th>N.A.I</th>
                        <th>N.I.F</th>
                        <th>N.I.S</th>
                        <th style="text-align:right">Total ventes (TTC)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tbody">
                <?php if (empty($clients)): ?>
                    <tr id="empty-row">
                        <td colspan="9">
                            <div class="empty">
                                <div class="icon">👤</div>
                                <p>Aucun client enregistré.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clients as $c): ?>
                    <tr id="row-<?= $c['id'] ?>" data-nom="<?= htmlspecialchars(strtolower($c['nom'])) ?>">
                        <td class="id-col"><?= $c['id'] ?></td>

                        <!-- Nom -->
                        <td>
                            <span class="v-nom"><?= htmlspecialchars($c['nom']) ?></span>
                            <input class="ie e-nom" type="text" value="<?= htmlspecialchars($c['nom']) ?>" style="display:none" maxlength="150">
                        </td>

                        <!-- Adresse -->
                        <td>
                            <span class="v-adresse"><?= nl2br(htmlspecialchars($c['adresse'])) ?></span>
                            <textarea class="ie e-adresse" style="display:none"><?= htmlspecialchars($c['adresse']) ?></textarea>
                        </td>

                        <!-- N.R.S -->
                        <td>
                            <span class="v-nrs num-badge"><?= htmlspecialchars($c['nrs'] ?: '—') ?></span>
                            <input class="ie e-nrs" type="text" value="<?= htmlspecialchars($c['nrs']) ?>" style="display:none" maxlength="30">
                        </td>

                        <!-- N.A.I -->
                        <td>
                            <span class="v-nai num-badge"><?= htmlspecialchars($c['nai'] ?: '—') ?></span>
                            <input class="ie e-nai" type="text" value="<?= htmlspecialchars($c['nai']) ?>" style="display:none" maxlength="30">
                        </td>

                        <!-- N.I.F -->
                        <td>
                            <span class="v-nif num-badge"><?= htmlspecialchars($c['nif'] ?: '—') ?></span>
                            <input class="ie e-nif" type="text" value="<?= htmlspecialchars($c['nif']) ?>" style="display:none" maxlength="30">
                        </td>

                        <!-- N.I.S -->
                        <td>
                            <span class="v-nis num-badge"><?= htmlspecialchars($c['nis'] ?: '—') ?></span>
                            <input class="ie e-nis" type="text" value="<?= htmlspecialchars($c['nis']) ?>" style="display:none" maxlength="30">
                        </td>

                        <!-- Total ventes -->
                        <td class="total-col">
                            <span class="v-total-ventes"><?= number_format((float)($c['total_ventes'] ?? 0), 2, ',', ' ') ?> DA</span>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div class="actions view-actions">
                                <button class="btn btn-warning btn-sm" onclick="activerModif(<?= $c['id'] ?>)">✏️ Modifier</button>
                                <button class="btn btn-danger btn-sm"  onclick="supprimerClient(<?= $c['id'] ?>)">🗑️ Supprimer</button>
                            </div>
                            <div class="actions edit-actions" style="display:none">
                                <button class="btn btn-primary btn-sm" onclick="sauvegarder(<?= $c['id'] ?>)">💾 Sauver</button>
                                <button class="btn btn-cancel btn-sm"  onclick="annuler(<?= $c['id'] ?>)">✕ Annuler</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
// ---- Toast ----
function toast(msg, type = 'ok') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.className = '', 3200);
}

// ---- Compteur ----
function updateCount() {
    const rows = document.querySelectorAll('#tbody tr[id^="row-"]');
    const n = [...rows].filter(r => r.style.display !== 'none').length;
    document.getElementById('count_label').textContent = n + ' client(s)';
}
updateCount();

// ---- Filtre ----
function filtrerTable() {
    const q = document.getElementById('search').value.toLowerCase();
    document.querySelectorAll('#tbody tr[id^="row-"]').forEach(row => {
        row.style.display = row.dataset.nom.includes(q) ? '' : 'none';
    });
    updateCount();
}

// ============================================================
// AJOUTER
// ============================================================
function ajouterClient() {
    const nom = document.getElementById('f_nom').value.trim();
    if (!nom) { toast('Le nom est obligatoire.', 'err'); return; }

    const fd = new FormData();
    fd.append('action',   'ajouter');
    fd.append('nom',      nom);
    fd.append('adresse',  document.getElementById('f_adresse').value.trim());
    fd.append('nrs',      document.getElementById('f_nrs').value.trim());
    fd.append('nai',      document.getElementById('f_nai').value.trim());
    fd.append('nif',      document.getElementById('f_nif').value.trim());
    fd.append('nis',      document.getElementById('f_nis').value.trim());

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                toast(data.message, 'ok');
                resetForm();
                setTimeout(() => location.reload(), 700);
            } else {
                toast(data.message, 'err');
            }
        })
        .catch(() => toast('Erreur réseau.', 'err'));
}

function resetForm() {
    ['f_nom','f_adresse','f_nrs','f_nai','f_nif','f_nis'].forEach(id => {
        document.getElementById(id).value = '';
    });
}

// ============================================================
// MODIFIER (inline)
// ============================================================
function activerModif(id) {
    const row = document.getElementById('row-' + id);
    row.classList.add('editing');
    row.querySelectorAll('[class^="v-"]').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.ie').forEach(el => el.style.display = 'block');
    row.querySelector('.view-actions').style.display = 'none';
    row.querySelector('.edit-actions').style.display = 'flex';
}

function annuler(id) {
    const row = document.getElementById('row-' + id);
    row.classList.remove('editing');
    row.querySelectorAll('[class^="v-"]').forEach(el => el.style.display = '');
    row.querySelectorAll('.ie').forEach(el => el.style.display = 'none');
    row.querySelector('.view-actions').style.display = 'flex';
    row.querySelector('.edit-actions').style.display = 'none';
}

function sauvegarder(id) {
    const row = document.getElementById('row-' + id);
    const nom = row.querySelector('.e-nom').value.trim();
    if (!nom) { toast('Le nom est obligatoire.', 'err'); return; }

    const fd = new FormData();
    fd.append('action',  'modifier');
    fd.append('id',      id);
    fd.append('nom',     nom);
    fd.append('adresse', row.querySelector('.e-adresse').value.trim());
    fd.append('nrs',     row.querySelector('.e-nrs').value.trim());
    fd.append('nai',     row.querySelector('.e-nai').value.trim());
    fd.append('nif',     row.querySelector('.e-nif').value.trim());
    fd.append('nis',     row.querySelector('.e-nis').value.trim());

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Mettre à jour les spans
                const get = cls => row.querySelector(cls).value.trim();
                row.querySelector('.v-nom').textContent      = get('.e-nom');
                row.querySelector('.v-adresse').textContent  = get('.e-adresse');
                row.querySelector('.v-nrs').textContent      = get('.e-nrs') || '—';
                row.querySelector('.v-nai').textContent      = get('.e-nai') || '—';
                row.querySelector('.v-nif').textContent      = get('.e-nif') || '—';
                row.querySelector('.v-nis').textContent      = get('.e-nis') || '—';
                row.dataset.nom = get('.e-nom').toLowerCase();
                annuler(id);
                toast(data.message, 'ok');
            } else {
                toast(data.message, 'err');
            }
        })
        .catch(() => toast('Erreur réseau.', 'err'));
}

// ============================================================
// SUPPRIMER
// ============================================================
function supprimerClient(id) {
    const nom = document.querySelector('#row-' + id + ' .v-nom').textContent;
    if (!confirm('Supprimer le client "' + nom + '" ?')) return;

    const fd = new FormData();
    fd.append('action', 'supprimer');
    fd.append('id', id);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById('row-' + id);
                row.style.transition = 'opacity .4s';
                row.style.opacity = '0';
                setTimeout(() => { row.remove(); updateCount(); }, 400);
                toast(data.message, 'ok');
            } else {
                toast(data.message, 'err');
            }
        })
        .catch(() => toast('Erreur réseau.', 'err'));
}
</script>

</body>
</html>
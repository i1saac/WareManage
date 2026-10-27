<?php
require_once 'config.php'; // Inclure la connexion à la base de données
// ============================================================
// TRAITEMENT DES REQUÊTES AJAX (PHP BACKEND)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // --- AJOUTER UN PRODUIT ---
    if ($action === 'ajouter') {
        $designation = trim($_POST['designation']);
        $quantite    = intval($_POST['quantite']);
        $prix_achat  = floatval($_POST['prix_achat']);
        $prix_vente  = round($prix_achat + 0.05 * $prix_achat, 2);

        if (empty($designation) || $quantite < 0 || $prix_achat <= 0) {
            echo json_encode(['success' => false, 'message' => 'Données invalides.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO produit_bank (designation, quantite, prix_achat, prix_vente) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sidd", $designation, $quantite, $prix_achat, $prix_vente);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Produit ajouté avec succès.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'ajout.']);
        }
        $stmt->close();
        exit;
    }

    // --- MODIFIER UN PRODUIT ---
    if ($action === 'modifier') {
        $id          = intval($_POST['id']);
        $designation = trim($_POST['designation']);
        $quantite    = intval($_POST['quantite']);
        $prix_achat  = floatval($_POST['prix_achat']);
        $prix_vente  = round($prix_achat + 0.05 * $prix_achat, 2);

        if ($id <= 0 || empty($designation) || $quantite < 0 || $prix_achat <= 0) {
            echo json_encode(['success' => false, 'message' => 'Données invalides.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE produit_bank SET designation=?, quantite=?, prix_achat=?, prix_vente=? WHERE id=?");
        $stmt->bind_param("siddi", $designation, $quantite, $prix_achat, $prix_vente, $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Produit modifié avec succès.', 'prix_vente' => $prix_vente]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la modification.']);
        }
        $stmt->close();
        exit;
    }

    // --- SUPPRIMER UN PRODUIT ---
    if ($action === 'supprimer') {
        $id = intval($_POST['id']);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID invalide.']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM produit_bank WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Produit supprimé.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression.']);
        }
        $stmt->close();
        exit;
    }
}

// ============================================================
// CHARGEMENT DES PRODUITS POUR AFFICHAGE
// ============================================================
$produits = [];
$result = $conn->query("SELECT * FROM produit_bank ORDER BY id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $produits[] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Produits — Warehouse</title>
    <style>
        /* ===================== RESET & BASE ===================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:   #2563eb;
            --primary-h: #1d4ed8;
            --danger:    #dc2626;
            --danger-h:  #b91c1c;
            --warning:   #d97706;
            --warning-h: #b45309;
            --success:   #16a34a;
            --bg:        #f1f5f9;
            --card:      #ffffff;
            --border:    #e2e8f0;
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

        /* ===================== HEADER ===================== */
        header {
            background: var(--primary);
            color: #fff;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(37,99,235,.35);
        }
        header .icon { font-size: 1.6rem; }
        header h1   { font-size: 1.25rem; font-weight: 700; letter-spacing: .3px; }
        header span { font-size: .85rem; opacity: .8; margin-left: auto; }

        /* ===================== LAYOUT ===================== */
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px;
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 800px) {
            .container { grid-template-columns: 1fr; }
        }

        /* ===================== CARD ===================== */
        .card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: .95rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ===================== FORMULAIRE ===================== */
        .form-body { padding: 20px; }

        .field { margin-bottom: 16px; }
        .field label {
            display: block;
            font-size: .82rem;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .field input {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid var(--border);
            border-radius: 7px;
            font-size: .95rem;
            color: var(--text);
            background: #f8fafc;
            transition: border-color .2s;
            outline: none;
        }
        .field input:focus  { border-color: var(--primary); background: #fff; }
        .field input[readonly] {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: var(--success);
            font-weight: 700;
            cursor: default;
        }
        .field .hint {
            font-size: .75rem;
            color: var(--success);
            margin-top: 4px;
        }

        /* ===================== BOUTONS ===================== */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border: none;
            border-radius: 7px;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .18s, transform .1s;
        }
        .btn:active { transform: scale(.97); }
        .btn-primary  { background: var(--primary);  color: #fff; }
        .btn-primary:hover  { background: var(--primary-h); }
        .btn-danger   { background: var(--danger);   color: #fff; }
        .btn-danger:hover   { background: var(--danger-h); }
        .btn-warning  { background: var(--warning);  color: #fff; }
        .btn-warning:hover  { background: var(--warning-h); }
        .btn-sm { padding: 6px 12px; font-size: .8rem; }
        .btn-full { width: 100%; justify-content: center; }
        .btn-cancel { background:#e2e8f0; color:var(--text); }
        .btn-cancel:hover { background:#cbd5e1; }

        .btn-row { display:flex; gap:8px; margin-top:4px; }

        /* ===================== TOAST ===================== */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
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

        /* ===================== TABLEAU ===================== */
        .table-wrap {
            overflow-x: auto;
            padding: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .88rem;
        }
        thead tr { background: #f8fafc; }
        thead th {
            padding: 12px 14px;
            text-align: left;
            font-size: .78rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
        }
        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }
        tbody tr:hover { background: #f0f7ff; }
        tbody tr.editing { background: #fffbeb; }
        td {
            padding: 11px 14px;
            color: var(--text);
            vertical-align: middle;
        }
        td.id-col { color: var(--muted); font-size: .8rem; }

        /* inline edit */
        td input.inline-edit {
            width: 100%;
            padding: 5px 8px;
            border: 1.5px solid var(--primary);
            border-radius: 5px;
            font-size: .85rem;
            outline: none;
            min-width: 80px;
        }
        td input.inline-readonly {
            width: 100%;
            padding: 5px 8px;
            border: 1.5px solid #bbf7d0;
            border-radius: 5px;
            font-size: .85rem;
            background: #f0fdf4;
            color: var(--success);
            font-weight: 700;
            outline: none;
        }

        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: .78rem;
            font-weight: 700;
        }
        .badge-blue { background:#dbeafe; color:#1d4ed8; }

        .actions { display:flex; gap:6px; }

        /* vide */
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }
        .empty .icon { font-size: 2.5rem; margin-bottom: 8px; }

        /* search bar */
        .table-top {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
        }
        #search {
            flex: 1;
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: 7px;
            font-size: .88rem;
            outline: none;
        }
        #search:focus { border-color: var(--primary); }
        .count { font-size: .82rem; color: var(--muted); white-space: nowrap; }
    </style>
</head>
<body>

<!-- HEADER -->
<header>
    <span class="icon">📦</span>
    <h1>Gestion des Produits</h1>
    <span>warehouse_db</span>
</header>

<!-- TOAST -->
<div id="toast"></div>

<div class="container">

    <!-- =================== FORMULAIRE =================== -->
    <div class="card">
        <div class="card-header">➕ Ajouter un produit</div>
        <div class="form-body">
            <div class="field">
                <label>Désignation *</label>
                <input type="text" id="f_designation" placeholder="Ex : Stylo BIC bleu" maxlength="150">
            </div>
            <div class="field">
                <label>Quantité *</label>
                <input type="number" id="f_quantite" placeholder="0" min="0">
            </div>
            <div class="field">
                <label>Prix d'achat (DA) *</label>
                <input type="number" id="f_prix_achat" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="field">
                <label>Prix de vente (DA)</label>
                <input type="number" id="f_prix_vente" readonly placeholder="Calculé automatiquement">
                <p class="hint">= Prix achat + 5% (marge)</p>
            </div>
            <button class="btn btn-primary btn-full" onclick="ajouterProduit()">
                ➕ Ajouter le produit
            </button>
        </div>
    </div>

    <!-- =================== TABLEAU =================== -->
    <div class="card">
        <div class="card-header">📋 Liste des produits</div>
        <div class="table-top">
            <input type="text" id="search" placeholder="🔍  Rechercher un produit..." oninput="filtrerTable()">
            <span class="count" id="count_label"></span>
        </div>
        <div class="table-wrap">
            <table id="produit_table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Désignation</th>
                        <th>Qté</th>
                        <th>Prix achat</th>
                        <th>Prix vente</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tbody">
                    <?php if (empty($produits)): ?>
                    <tr id="empty-row">
                        <td colspan="6">
                            <div class="empty">
                                <div class="icon">🗃️</div>
                                <p>Aucun produit enregistré.</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($produits as $p): ?>
                    <tr id="row-<?= $p['id'] ?>" data-designation="<?= htmlspecialchars(strtolower($p['designation'])) ?>">
                        <td class="id-col"><?= $p['id'] ?></td>

                        <!-- Désignation -->
                        <td>
                            <span class="view-designation"><?= htmlspecialchars($p['designation']) ?></span>
                            <input class="inline-edit edit-designation" type="text"
                                   value="<?= htmlspecialchars($p['designation']) ?>"
                                   style="display:none" maxlength="150">
                        </td>

                        <!-- Quantité -->
                        <td>
                            <span class="view-quantite badge badge-blue"><?= $p['quantite'] ?></span>
                            <input class="inline-edit edit-quantite" type="number"
                                   value="<?= $p['quantite'] ?>"
                                   style="display:none" min="0">
                        </td>

                        <!-- Prix achat -->
                        <td>
                            <span class="view-prix_achat"><?= number_format($p['prix_achat'], 2) ?> DA</span>
                            <input class="inline-edit edit-prix_achat" type="number"
                                   value="<?= $p['prix_achat'] ?>"
                                   style="display:none" min="0" step="0.01">
                        </td>

                        <!-- Prix vente (readonly inline) -->
                        <td>
                            <span class="view-prix_vente" style="color:var(--success);font-weight:700">
                                <?= number_format($p['prix_vente'], 2) ?> DA
                            </span>
                            <input class="inline-readonly edit-prix_vente" type="number"
                                   value="<?= $p['prix_vente'] ?>"
                                   style="display:none" readonly>
                        </td>

                        <!-- Actions -->
                        <td>
                            <!-- Boutons vue -->
                            <div class="actions view-actions">
                                <button class="btn btn-warning btn-sm"
                                        onclick="activerModif(<?= $p['id'] ?>)">✏️ Modifier</button>
                                <button class="btn btn-danger btn-sm"
                                        onclick="supprimerProduit(<?= $p['id'] ?>)">🗑️ Supprimer</button>
                            </div>
                            <!-- Boutons édition -->
                            <div class="actions edit-actions btn-row" style="display:none">
                                <button class="btn btn-primary btn-sm"
                                        onclick="sauvegarderModif(<?= $p['id'] ?>)">💾 Sauver</button>
                                <button class="btn btn-cancel btn-sm"
                                        onclick="annulerModif(<?= $p['id'] ?>)">✕ Annuler</button>
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

<!-- =================== JAVASCRIPT =================== -->
<script>
// ---- Calcul automatique prix_vente (formulaire) ----
document.getElementById('f_prix_achat').addEventListener('input', function () {
    const pa = parseFloat(this.value);
    const pv = document.getElementById('f_prix_vente');
    pv.value = isNaN(pa) || pa <= 0 ? '' : (pa + 0.05 * pa).toFixed(2);
});

// ---- Toast ----
function toast(msg, type = 'ok') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.className = '', 3000);
}

// ---- Compteur lignes ----
function updateCount() {
    const rows = document.querySelectorAll('#tbody tr[id^="row-"]');
    const visible = [...rows].filter(r => r.style.display !== 'none').length;
    document.getElementById('count_label').textContent = visible + ' produit(s)';
}
updateCount();

// ---- Filtre recherche ----
function filtrerTable() {
    const q = document.getElementById('search').value.toLowerCase();
    document.querySelectorAll('#tbody tr[id^="row-"]').forEach(row => {
        const match = row.dataset.designation.includes(q);
        row.style.display = match ? '' : 'none';
    });
    updateCount();
}

// ============================================================
// AJOUTER UN PRODUIT
// ============================================================
function ajouterProduit() {
    const designation = document.getElementById('f_designation').value.trim();
    const quantite    = document.getElementById('f_quantite').value;
    const prix_achat  = parseFloat(document.getElementById('f_prix_achat').value);
    const prix_vente  = parseFloat(document.getElementById('f_prix_vente').value);

    if (!designation)        { toast('Veuillez saisir une désignation.', 'err'); return; }
    if (!quantite && quantite !== '0') { toast('Veuillez saisir une quantité.', 'err'); return; }
    if (!prix_achat || prix_achat <= 0) { toast('Veuillez saisir un prix d\'achat valide.', 'err'); return; }

    const fd = new FormData();
    fd.append('action', 'ajouter');
    fd.append('designation', designation);
    fd.append('quantite', quantite);
    fd.append('prix_achat', prix_achat);

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
    ['f_designation', 'f_quantite', 'f_prix_achat', 'f_prix_vente'].forEach(id => {
        document.getElementById(id).value = '';
    });
}

// ============================================================
// ACTIVER MODE MODIFICATION (inline)
// ============================================================
function activerModif(id) {
    const row = document.getElementById('row-' + id);
    row.classList.add('editing');

    // Cacher spans, afficher inputs
    row.querySelectorAll('[class^="view-"]').forEach(el => el.style.display = 'none');
    row.querySelectorAll('.inline-edit, .inline-readonly').forEach(el => el.style.display = 'block');
    row.querySelector('.view-actions').style.display = 'none';
    row.querySelector('.edit-actions').style.display  = 'flex';

    // Calcul auto prix_vente inline quand prix_achat change
    const paInput = row.querySelector('.edit-prix_achat');
    const pvInput = row.querySelector('.edit-prix_vente');
    paInput.addEventListener('input', function () {
        const pa = parseFloat(this.value);
        pvInput.value = isNaN(pa) || pa <= 0 ? '' : (pa + 0.05 * pa).toFixed(2);
    });
}

// ============================================================
// ANNULER MODIFICATION
// ============================================================
function annulerModif(id) {
    const row = document.getElementById('row-' + id);
    row.classList.remove('editing');
    row.querySelectorAll('[class^="view-"]').forEach(el => el.style.display = '');
    row.querySelectorAll('.inline-edit, .inline-readonly').forEach(el => el.style.display = 'none');
    row.querySelector('.view-actions').style.display = 'flex';
    row.querySelector('.edit-actions').style.display  = 'none';
}

// ============================================================
// SAUVEGARDER MODIFICATION
// ============================================================
function sauvegarderModif(id) {
    const row         = document.getElementById('row-' + id);
    const designation = row.querySelector('.edit-designation').value.trim();
    const quantite    = row.querySelector('.edit-quantite').value;
    const prix_achat  = parseFloat(row.querySelector('.edit-prix_achat').value);
    const prix_vente  = parseFloat(row.querySelector('.edit-prix_vente').value);

    if (!designation)        { toast('Désignation vide.', 'err'); return; }
    if (!prix_achat || prix_achat <= 0) { toast('Prix d\'achat invalide.', 'err'); return; }

    const fd = new FormData();
    fd.append('action',      'modifier');
    fd.append('id',          id);
    fd.append('designation', designation);
    fd.append('quantite',    quantite);
    fd.append('prix_achat',  prix_achat);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Mettre à jour les spans visuellement
                row.querySelector('.view-designation').textContent = designation;
                row.querySelector('.view-quantite').textContent    = quantite;
                row.querySelector('.view-prix_achat').textContent  = parseFloat(prix_achat).toFixed(2) + ' DA';
                row.querySelector('.view-prix_vente').textContent  = parseFloat(data.prix_vente).toFixed(2) + ' DA';
                row.dataset.designation = designation.toLowerCase();
                annulerModif(id);
                toast(data.message, 'ok');
            } else {
                toast(data.message, 'err');
            }
        })
        .catch(() => toast('Erreur réseau.', 'err'));
}

// ============================================================
// SUPPRIMER UN PRODUIT
// ============================================================
function supprimerProduit(id) {
    const nom = document.querySelector('#row-' + id + ' .view-designation').textContent;
    if (!confirm('Supprimer le produit "' + nom + '" ?')) return;

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
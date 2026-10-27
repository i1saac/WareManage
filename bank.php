<?php
require_once 'config.php';

// ============================================================
// ACTIONS AJAX (toutes en POST, comme ventes_bank.php)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // --- CRÉER UN ACHAT ---
    if ($action === 'creer_achat') {
        $fournisseur_nom = trim($_POST['fournisseur'] ?? '');
        $date_achat      = trim($_POST['date_achat'] ?? '') ?: date('Y-m-d');
        $tva_taux        = floatval($_POST['tva_taux'] ?? 0);
        $produits        = json_decode($_POST['produits'] ?? '[]', true);

        if ($fournisseur_nom === '' || empty($produits)) {
            echo json_encode(['success' => false, 'message' => 'Fournisseur ou produits manquants.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            // 1. Fournisseur : trouver ou créer
            $stmtF = $conn->prepare("SELECT id FROM fournisseurs_bank WHERE nom = ? LIMIT 1");
            $stmtF->bind_param("s", $fournisseur_nom);
            $stmtF->execute();
            $resF = $stmtF->get_result()->fetch_assoc();
            $stmtF->close();

            if ($resF) {
                $fournisseur_id = intval($resF['id']);
            } else {
                $stmtFI = $conn->prepare("INSERT INTO fournisseurs_bank (nom) VALUES (?)");
                $stmtFI->bind_param("s", $fournisseur_nom);
                $stmtFI->execute();
                $fournisseur_id = $conn->insert_id;
                $stmtFI->close();
            }

            // 2. Parcourir les lignes produits, mettre à jour / créer le stock
            $total = 0;
            $lignesPreparees = [];

            $stmtSelP = $conn->prepare("SELECT id, quantite FROM produit_bank WHERE designation = ? LIMIT 1");
            $stmtUpdP = $conn->prepare("UPDATE produit_bank SET quantite = ?, prix_achat = ? WHERE id = ?");
            $stmtInsP = $conn->prepare("INSERT INTO produit_bank (designation, quantite, prix_achat, prix_vente) VALUES (?, ?, ?, ?)");

            foreach ($produits as $p) {
                $designation = trim($p['designation'] ?? '');
                $quantite    = intval($p['quantite'] ?? 0);
                $prix_achat  = floatval($p['prix_achat'] ?? 0);

                if ($designation === '' || $quantite <= 0 || $prix_achat < 0) continue;

                $stmtSelP->bind_param("s", $designation);
                $stmtSelP->execute();
                $prod = $stmtSelP->get_result()->fetch_assoc();

                if ($prod) {
                    $produit_id   = intval($prod['id']);
                    $nouvelle_qte = intval($prod['quantite']) + $quantite;
                    $stmtUpdP->bind_param("ddi", $nouvelle_qte, $prix_achat, $produit_id);
                    $stmtUpdP->execute();
                } else {
                    $stmtInsP->bind_param("sidd", $designation, $quantite, $prix_achat, $prix_achat);
                    $stmtInsP->execute();
                    $produit_id = $conn->insert_id;
                }

                $montant = round($quantite * $prix_achat, 2);
                $total  += $montant;

                $lignesPreparees[] = [
                    'produit_id' => $produit_id,
                    'quantite'   => $quantite,
                    'prix_achat' => $prix_achat,
                    'montant'    => $montant,
                ];
            }
            $stmtSelP->close();
            $stmtUpdP->close();
            $stmtInsP->close();

            if (empty($lignesPreparees)) {
                throw new Exception('Aucune ligne produit valide.');
            }

            $tva       = round($total * ($tva_taux / 100), 2);
            $total_ttc = round($total + $tva, 2);

            // 3. Entête achat
            $stmtA = $conn->prepare("INSERT INTO achat_bank (fournisseurs_bank_id, date_achat, total, tva, total_ttc) VALUES (?, ?, ?, ?, ?)");
            $stmtA->bind_param("isddd", $fournisseur_id, $date_achat, $total, $tva, $total_ttc);
            $stmtA->execute();
            $achat_id = $conn->insert_id;
            $stmtA->close();

            // 4. Lignes achat_produit
            $stmtL = $conn->prepare("INSERT INTO achat_produit (achat_bank_id, produit_bank_id, quantite, prix_achat, montant) VALUES (?, ?, ?, ?, ?)");
            foreach ($lignesPreparees as $l) {
                $stmtL->bind_param("iiddd", $achat_id, $l['produit_id'], $l['quantite'], $l['prix_achat'], $l['montant']);
                $stmtL->execute();
            }
            $stmtL->close();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Achat créé avec succès.', 'achat_id' => $achat_id]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
        }
        exit;
    }

    // --- SUPPRIMER UN ACHAT (avec réajustement du stock) ---
    if ($action === 'supprimer_achat') {
        $aid = intval($_POST['id']);
        $conn->begin_transaction();
        try {
            $rows = $conn->query("SELECT produit_bank_id, quantite FROM achat_produit WHERE achat_bank_id = $aid");
            $rst  = $conn->prepare("UPDATE produit_bank SET quantite = GREATEST(quantite - ?, 0) WHERE id = ?");
            while ($r = $rows->fetch_assoc()) {
                $rst->bind_param("ii", $r['quantite'], $r['produit_bank_id']);
                $rst->execute();
            }
            $rst->close();
            $conn->query("DELETE FROM achat_produit WHERE achat_bank_id = $aid");
            $conn->query("DELETE FROM achat_bank WHERE id = $aid");
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Achat supprimé, stock réajusté.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- DÉTAIL D'UN ACHAT ---
    if ($action === 'detail_achat') {
        $aid = intval($_POST['id']);
        $a = $conn->query("SELECT ab.*, fb.nom AS fournisseur_nom
                            FROM achat_bank ab
                            JOIN fournisseurs_bank fb ON fb.id = ab.fournisseurs_bank_id
                            WHERE ab.id = $aid")->fetch_assoc();
        $lignes = [];
        $res = $conn->query("SELECT ap.*, pb.designation FROM achat_produit ap
                              JOIN produit_bank pb ON pb.id = ap.produit_bank_id
                              WHERE ap.achat_bank_id = $aid ORDER BY ap.id");
        while ($r = $res->fetch_assoc()) $lignes[] = $r;
        echo json_encode(['success' => true, 'achat' => $a, 'lignes' => $lignes]);
        exit;
    }
}

// ============================================================
// CHARGEMENT PAGE
// ============================================================
$fournisseurs = [];
$rf = $conn->query("SELECT id, nom FROM fournisseurs_bank ORDER BY nom");
while ($row = $rf->fetch_assoc()) $fournisseurs[] = $row;

$produits = [];
$rp = $conn->query("SELECT id, designation, quantite, prix_achat, prix_vente FROM produit_bank ORDER BY designation");
while ($row = $rp->fetch_assoc()) $produits[] = $row;

$achats = [];
$ra = $conn->query("SELECT ab.*, fb.nom AS fournisseur_nom
                     FROM achat_bank ab
                     JOIN fournisseurs_bank fb ON fb.id = ab.fournisseurs_bank_id
                     ORDER BY ab.id DESC");
while ($row = $ra->fetch_assoc()) $achats[] = $row;

$sv = $conn->query("SELECT COALESCE(SUM(total),0) AS s FROM vente_bank")->fetch_assoc();
$sum_ventes = floatval($sv['s']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des Achats</title>
<style>
    :root{
        --primary:#2563eb; --primary-dark:#1d4ed8;
        --danger:#dc2626; --danger-dark:#b91c1c;
        --success:#16a34a;
        --bg:#f4f6fb; --card:#ffffff; --border:#e2e8f0;
        --text:#1e293b; --text-light:#64748b; --radius:10px;
    }
    *{box-sizing:border-box;}
    body{margin:0; font-family:'Segoe UI', Roboto, Arial, sans-serif; background:var(--bg); color:var(--text); padding:24px;}
    h1{font-size:1.6rem; margin-bottom:4px;}
    h2{font-size:1.15rem; margin:0 0 14px 0;}
    .subtitle{color:var(--text-light); margin-top:0; margin-bottom:20px;}
    .top-bar{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;}
    .sum-box{background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; padding:14px 22px; border-radius:var(--radius); min-width:260px; box-shadow:0 4px 10px rgba(37,99,235,.25);}
    .sum-box span{display:block; font-size:.8rem; opacity:.85;}
    .sum-box strong{font-size:1.5rem;}
    .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:22px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,.04);}
    .grid-2{display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px;}
    @media (max-width:800px){ .grid-2{grid-template-columns:1fr;} }
    label{display:block; font-size:.82rem; font-weight:600; color:var(--text-light); margin-bottom:6px;}
    input, select{width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:.95rem; background:#fff; color:var(--text);}
    input:focus, select:focus{outline:2px solid var(--primary); border-color:var(--primary);}
    .line-form{display:grid; grid-template-columns:2.2fr 1fr 1fr 1fr auto; gap:12px; align-items:end; margin-top:16px;}
    @media (max-width:800px){ .line-form{grid-template-columns:1fr 1fr;} }
    button{cursor:pointer; border:none; border-radius:8px; font-size:.9rem; font-weight:600; padding:10px 16px; transition:.15s;}
    .btn-primary{background:var(--primary); color:#fff;} .btn-primary:hover{background:var(--primary-dark);}
    .btn-success{background:var(--success); color:#fff;} .btn-success:hover{background:#15803d;}
    .btn-danger{background:var(--danger); color:#fff;} .btn-danger:hover{background:var(--danger-dark);}
    .btn-ghost{background:#eef2ff; color:var(--primary);} .btn-ghost:hover{background:#e0e7ff;}
    .btn-sm{padding:6px 10px; font-size:.8rem;}
    button:disabled{opacity:.5; cursor:not-allowed;}
    table{width:100%; border-collapse:collapse; margin-top:14px; font-size:.9rem;}
    th, td{padding:9px 10px; border-bottom:1px solid var(--border); text-align:left;}
    th{background:#f8fafc; color:var(--text-light); font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.03em;}
    tbody tr:hover{background:#f8fafc;}
    td.num, th.num{text-align:right;}
    .totals-row{display:flex; justify-content:flex-end; gap:24px; margin-top:14px; flex-wrap:wrap;}
    .totals-row div{background:#f1f5f9; padding:10px 16px; border-radius:8px; font-size:.9rem;}
    .totals-row strong{color:var(--primary-dark);}
    .search-bar{display:flex; gap:10px; margin-bottom:12px;}
    .search-bar input{max-width:320px;}
    .empty-row td{text-align:center; color:var(--text-light); padding:18px;}
    .modal-overlay{display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); align-items:center; justify-content:center; z-index:50; padding:20px;}
    .modal-overlay.active{display:flex;}
    .modal-box{background:#fff; border-radius:var(--radius); padding:24px; max-width:640px; width:100%; max-height:80vh; overflow:auto;}
    .modal-header{display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;}
    .close-btn{background:none; color:var(--text-light); font-size:1.3rem; padding:0 6px;}
    .toast{position:fixed; bottom:24px; right:24px; padding:12px 20px; border-radius:8px; color:#fff; font-size:.9rem; z-index:100; box-shadow:0 4px 12px rgba(0,0,0,.15); display:none;}
    .toast.show{display:block;}
    .toast.ok{background:var(--success);} .toast.err{background:var(--danger);}
    .actions-cell{display:flex; gap:6px;}
    .remove-x{background:none; color:var(--danger); font-weight:700; padding:4px 8px;}
</style>
</head>
<body>

<div class="top-bar">
    <div>
        <h1>📦 Gestion des Achats</h1>
        <p class="subtitle">Saisie des achats fournisseurs et mise à jour automatique du stock</p>
    </div>
    <div class="sum-box">
        <span>Somme totale de toutes les ventes</span>
        <strong id="sumVentes"><?= number_format($sum_ventes, 2, ',', ' ') ?> DA</strong>
    </div>
</div>

<!-- ============== FORMULAIRE ACHAT EN COURS ============== -->
<div class="card">
    <h2>Nouvel achat</h2>
    <div class="grid-2">
        <div>
            <label for="fournisseurInput">Fournisseur</label>
            <input type="text" id="fournisseurInput" list="fournisseurList" placeholder="Nom du fournisseur">
            <datalist id="fournisseurList">
                <?php foreach ($fournisseurs as $f): ?>
                    <option value="<?= htmlspecialchars($f['nom']) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div>
            <label for="dateAchat">Date d'achat</label>
            <input type="date" id="dateAchat">
        </div>
        <div>
            <label for="tvaTaux">Taux TVA (%)</label>
            <input type="number" id="tvaTaux" value="19" min="0" step="0.01">
        </div>
    </div>

    <div class="line-form">
        <div>
            <label for="ligneDesignation">Désignation produit</label>
            <input type="text" id="ligneDesignation" list="produitList" placeholder="Nom du produit">
            <datalist id="produitList">
                <?php foreach ($produits as $p): ?>
                    <option value="<?= htmlspecialchars($p['designation']) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div>
            <label for="ligneQuantite">Quantité</label>
            <input type="number" id="ligneQuantite" min="1" step="1" value="1">
        </div>
        <div>
            <label for="lignePrixAchat">Prix d'achat</label>
            <input type="number" id="lignePrixAchat" min="0" step="0.01" value="0">
        </div>
        <div>
            <label>Montant</label>
            <input type="text" id="ligneMontant" value="0.00" disabled>
        </div>
        <div>
            <button class="btn-ghost" id="btnAddLigne" type="button">+ Ajouter produit</button>
        </div>
    </div>

    <table>
        <thead><tr><th>Désignation</th><th class="num">Quantité</th><th class="num">Prix d'achat</th><th class="num">Montant</th><th></th></tr></thead>
        <tbody id="lignesBody">
            <tr class="empty-row"><td colspan="5">Aucun produit ajouté pour le moment</td></tr>
        </tbody>
    </table>

    <div class="totals-row">
        <div>Total : <strong id="totalHT">0.00</strong> DA</div>
        <div>TVA : <strong id="totalTVA">0.00</strong> DA</div>
        <div>Total TTC : <strong id="totalTTC">0.00</strong> DA</div>
    </div>

    <div style="margin-top:18px; text-align:right;">
        <button class="btn-primary" id="btnResetAchat" type="button">Réinitialiser</button>
        <button class="btn-success" id="btnCreateAchat" type="button">✔ Créer achat</button>
    </div>
</div>

<!-- ============== LISTE DES ACHATS ============== -->
<div class="card">
    <h2>Historique des achats</h2>
    <div class="search-bar">
        <input type="text" id="searchAchat" placeholder="Rechercher par fournisseur, date ou ID...">
    </div>
    <table>
        <thead><tr><th>ID</th><th>Fournisseur</th><th>Date</th><th class="num">Total</th><th class="num">TVA</th><th class="num">Total TTC</th><th>Actions</th></tr></thead>
        <tbody id="achatsBody">
            <?php if (empty($achats)): ?>
                <tr class="empty-row"><td colspan="7">Aucun achat enregistré</td></tr>
            <?php else: ?>
                <?php foreach ($achats as $a): ?>
                    <tr id="arow-<?= $a['id'] ?>"
                        data-search="<?= htmlspecialchars(strtolower($a['fournisseur_nom'] . ' ' . $a['date_achat'] . ' ' . $a['id'])) ?>">
                        <td><?= $a['id'] ?></td>
                        <td><?= htmlspecialchars($a['fournisseur_nom']) ?></td>
                        <td><?= htmlspecialchars($a['date_achat']) ?></td>
                        <td class="num"><?= number_format($a['total'], 2) ?></td>
                        <td class="num"><?= number_format($a['tva'], 2) ?></td>
                        <td class="num"><?= number_format($a['total_ttc'], 2) ?></td>
                        <td class="actions-cell">
                            <button class="btn-ghost btn-sm" onclick="openDetail(<?= $a['id'] ?>)" type="button">Consulter</button>
                            <button class="btn-danger btn-sm" onclick="deleteAchat(<?= $a['id'] ?>)" type="button">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ============== MODAL DÉTAIL ============== -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="modalTitle">Détail achat</h2>
            <button class="close-btn" id="modalClose" type="button">&times;</button>
        </div>
        <div id="modalContent"></div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let lignesAchat = [];
const $ = (sel) => document.querySelector(sel);
const lignesBody = $('#lignesBody');

function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function showToast(msg, type = 'ok'){
    const t = $('#toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}

$('#dateAchat').value = new Date().toISOString().slice(0, 10);

function recalcLigneMontant(){
    const q = parseFloat($('#ligneQuantite').value) || 0;
    const pa = parseFloat($('#lignePrixAchat').value) || 0;
    $('#ligneMontant').value = (q * pa).toFixed(2);
}
$('#ligneQuantite').addEventListener('input', recalcLigneMontant);
$('#lignePrixAchat').addEventListener('input', recalcLigneMontant);

$('#btnAddLigne').addEventListener('click', () => {
    const designation = $('#ligneDesignation').value.trim();
    const quantite = parseInt($('#ligneQuantite').value, 10);
    const prixAchat = parseFloat($('#lignePrixAchat').value);

    if (!designation) { showToast('Entrez la désignation du produit', 'err'); return; }
    if (!quantite || quantite <= 0) { showToast('Quantité invalide', 'err'); return; }
    if (isNaN(prixAchat) || prixAchat < 0) { showToast("Prix d'achat invalide", 'err'); return; }

    lignesAchat.push({ designation, quantite, prix_achat: prixAchat, montant: quantite * prixAchat });

    $('#ligneDesignation').value = '';
    $('#ligneQuantite').value = 1;
    $('#lignePrixAchat').value = 0;
    $('#ligneMontant').value = '0.00';
    renderLignes();
});

function renderLignes(){
    if (lignesAchat.length === 0) {
        lignesBody.innerHTML = `<tr class="empty-row"><td colspan="5">Aucun produit ajouté pour le moment</td></tr>`;
    } else {
        lignesBody.innerHTML = lignesAchat.map((l, i) => `
            <tr>
                <td>${escapeHtml(l.designation)}</td>
                <td class="num">${l.quantite}</td>
                <td class="num">${l.prix_achat.toFixed(2)}</td>
                <td class="num">${l.montant.toFixed(2)}</td>
                <td><button class="remove-x" data-i="${i}" type="button">&times;</button></td>
            </tr>`).join('');
    }
    recalcTotaux();
}

lignesBody.addEventListener('click', (e) => {
    if (e.target.classList.contains('remove-x')) {
        lignesAchat.splice(parseInt(e.target.dataset.i, 10), 1);
        renderLignes();
    }
});

function recalcTotaux(){
    const total = lignesAchat.reduce((s, l) => s + l.montant, 0);
    const tauxTva = parseFloat($('#tvaTaux').value) || 0;
    const tva = total * (tauxTva / 100);
    $('#totalHT').textContent = total.toFixed(2);
    $('#totalTVA').textContent = tva.toFixed(2);
    $('#totalTTC').textContent = (total + tva).toFixed(2);
}
$('#tvaTaux').addEventListener('input', recalcTotaux);

$('#btnResetAchat').addEventListener('click', () => {
    lignesAchat = [];
    $('#fournisseurInput').value = '';
    $('#dateAchat').value = new Date().toISOString().slice(0, 10);
    $('#tvaTaux').value = 19;
    renderLignes();
});

$('#btnCreateAchat').addEventListener('click', () => {
    const fournisseur = $('#fournisseurInput').value.trim();
    const date_achat = $('#dateAchat').value;
    const tva_taux = parseFloat($('#tvaTaux').value) || 0;

    if (!fournisseur) { showToast('Entrez le nom du fournisseur', 'err'); return; }
    if (lignesAchat.length === 0) { showToast('Ajoutez au moins un produit', 'err'); return; }

    const btn = $('#btnCreateAchat');
    btn.disabled = true; btn.textContent = 'Enregistrement...';

    const fd = new FormData();
    fd.append('action', 'creer_achat');
    fd.append('fournisseur', fournisseur);
    fd.append('date_achat', date_achat);
    fd.append('tva_taux', tva_taux);
    fd.append('produits', JSON.stringify(lignesAchat));

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast(d.message, 'ok');
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(d.message, 'err');
                btn.disabled = false; btn.textContent = '✔ Créer achat';
            }
        })
        .catch(() => { showToast('Erreur réseau', 'err'); btn.disabled = false; btn.textContent = '✔ Créer achat'; });
});

/* ---------------- Recherche (filtrage local, sans requête réseau) ---------------- */
$('#searchAchat').addEventListener('input', () => {
    const q = $('#searchAchat').value.trim().toLowerCase();
    document.querySelectorAll('#achatsBody tr[data-search]').forEach(tr => {
        tr.style.display = tr.dataset.search.includes(q) ? '' : 'none';
    });
});

/* ---------------- Consulter un achat ---------------- */
function openDetail(id){
    const fd = new FormData();
    fd.append('action', 'detail_achat');
    fd.append('id', id);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { showToast(d.message, 'err'); return; }
            const a = d.achat;
            $('#modalTitle').textContent = `Achat #${a.id} — ${a.fournisseur_nom}`;
            $('#modalContent').innerHTML = `
                <p style="color:#64748b; margin-top:0;">Date : ${a.date_achat}</p>
                <table>
                    <thead><tr><th>Produit</th><th class="num">Qté</th><th class="num">Prix achat</th><th class="num">Montant</th></tr></thead>
                    <tbody>
                        ${d.lignes.map(l => `
                            <tr>
                                <td>${escapeHtml(l.designation)}</td>
                                <td class="num">${l.quantite}</td>
                                <td class="num">${parseFloat(l.prix_achat).toFixed(2)}</td>
                                <td class="num">${parseFloat(l.montant).toFixed(2)}</td>
                            </tr>`).join('')}
                    </tbody>
                </table>
                <div class="totals-row">
                    <div>Total : <strong>${parseFloat(a.total).toFixed(2)}</strong> DA</div>
                    <div>TVA : <strong>${parseFloat(a.tva).toFixed(2)}</strong> DA</div>
                    <div>Total TTC : <strong>${parseFloat(a.total_ttc).toFixed(2)}</strong> DA</div>
                </div>`;
            $('#modalOverlay').classList.add('active');
        })
        .catch(() => showToast('Erreur réseau', 'err'));
}
$('#modalClose').addEventListener('click', () => $('#modalOverlay').classList.remove('active'));
$('#modalOverlay').addEventListener('click', (e) => { if (e.target.id === 'modalOverlay') $('#modalOverlay').classList.remove('active'); });

/* ---------------- Supprimer un achat ---------------- */
function deleteAchat(id){
    if (!confirm(`Supprimer l'achat #${id} ? Le stock des produits sera ajusté en conséquence.`)) return;

    const fd = new FormData();
    fd.append('action', 'supprimer_achat');
    fd.append('id', id);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast(d.message, 'ok');
                document.getElementById('arow-' + id)?.remove();
            } else {
                showToast(d.message, 'err');
            }
        })
        .catch(() => showToast('Erreur réseau', 'err'));
}
</script>
</body>
</html>
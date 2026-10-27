<?php
require_once 'config.php';
require_once 'bank_helpers.php';

ensure_clients_bank_total_ventes($conn);
refresh_client_total_ventes($conn);

// ── Ensure reglement columns exist (safe migration) ──
$conn->query("ALTER TABLE vente_bank ADD COLUMN IF NOT EXISTS reglement VARCHAR(10) NOT NULL DEFAULT 'RIB'");
$conn->query("ALTER TABLE vente_bank ADD COLUMN IF NOT EXISTS num_cheque VARCHAR(30) DEFAULT NULL");
$conn->query("ALTER TABLE vente_bank ADD COLUMN IF NOT EXISTS num_facture VARCHAR(50) DEFAULT NULL");
$conn->query("ALTER TABLE vente_bank ADD COLUMN IF NOT EXISTS remise DECIMAL(12,2) NOT NULL DEFAULT 0");

// ── Ensure nin column exists in clients_bank ──
$conn->query("ALTER TABLE clients_bank ADD COLUMN IF NOT EXISTS nin VARCHAR(50) DEFAULT NULL");

// ── Ensure selected column exists in vente_produit for tracking selection ──
$conn->query("ALTER TABLE vente_produit ADD COLUMN IF NOT EXISTS selected TINYINT(1) DEFAULT 1");

// ============================================================
// ACTIONS AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // --- CRÉER UNE VENTE ---
    if ($action === 'creer_vente') {
        $client_id = intval($_POST['client_id']);
        $produits  = json_decode($_POST['produits'], true);
        $total     = floatval($_POST['total']);
        $tva       = round($total * 0.19, 2);
        $remise    = floatval($_POST['remise'] ?? 0);
        if ($remise < 0) $remise = 0;
        $total_ttc = round($total + $tva - $remise, 2);
        if ($total_ttc < 0) $total_ttc = 0;
        $date_raw  = $_POST['date_vente'] ?? date('Y-m-d');
        $date      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_raw) ? $date_raw : date('Y-m-d');
        $num_facture = trim($_POST['num_facture'] ?? '');

        if ($client_id <= 0 || empty($produits)) {
            echo json_encode(['success' => false, 'message' => 'Client ou produits manquants.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $reglement  = in_array($_POST['reglement'] ?? 'RIB', ['RIB','cheque']) ? $_POST['reglement'] : 'RIB';
            $num_cheque = trim($_POST['num_cheque'] ?? '');
            $stmt = $conn->prepare("INSERT INTO vente_bank (clients_bank_id, date_vente, total, tva, remise, total_ttc, reglement, num_cheque, num_facture) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isddddsss", $client_id, $date, $total, $tva, $remise, $total_ttc, $reglement, $num_cheque, $num_facture);
            $stmt->execute();
            $vente_id = $conn->insert_id;
            $stmt->close();

            $stmt2 = $conn->prepare("INSERT INTO vente_produit (vente_bank_id, produit_bank_id, quantite, prix_vente, montant, selected) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt3 = $conn->prepare("UPDATE produit_bank SET quantite = quantite - ? WHERE id = ? AND quantite >= ?");

            foreach ($produits as $p) {
                $pid  = intval($p['id']);
                $qte  = intval($p['quantite']);
                $pv   = floatval($p['prix_vente']);
                $mont = round($qte * $pv, 2);
                $stmt2->bind_param("iiddd", $vente_id, $pid, $qte, $pv, $mont);
                $stmt2->execute();
                $stmt3->bind_param("iii", $qte, $pid, $qte);
                $stmt3->execute();
            }
            $stmt2->close();
            $stmt3->close();
            refresh_client_total_ventes($conn, $client_id);
            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Vente créée avec succès.',
                'vente_id' => $vente_id,
                'total_ventes' => get_client_total_ventes($conn, $client_id)
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
        }
        exit;
    }

    // --- SUPPRIMER UNE VENTE ---
    if ($action === 'supprimer_vente') {
        $vid = intval($_POST['id']);
        $vente_row = $conn->query("SELECT clients_bank_id FROM vente_bank WHERE id = $vid")->fetch_assoc();
        $client_id = $vente_row ? (int)$vente_row['clients_bank_id'] : 0;
        $conn->begin_transaction();
        try {
            $rows = $conn->query("SELECT produit_bank_id, quantite FROM vente_produit WHERE vente_bank_id = $vid");
            $rst  = $conn->prepare("UPDATE produit_bank SET quantite = quantite + ? WHERE id = ?");
            while ($r = $rows->fetch_assoc()) {
                $rst->bind_param("ii", $r['quantite'], $r['produit_bank_id']);
                $rst->execute();
            }
            $rst->close();
            $conn->query("DELETE FROM vente_produit WHERE vente_bank_id = $vid");
            $conn->query("DELETE FROM vente_bank WHERE id = $vid");
            if ($client_id > 0) {
                refresh_client_total_ventes($conn, $client_id);
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Vente supprimée, stock restauré.']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- SUPPRIMER UN PRODUIT D'UNE VENTE ---
    if ($action === 'supprimer_ligne') {
        $vpid = intval($_POST['vp_id']);
        $row  = $conn->query("SELECT * FROM vente_produit WHERE id = $vpid")->fetch_assoc();
        if (!$row) { echo json_encode(['success' => false, 'message' => 'Ligne introuvable.']); exit; }

        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM vente_produit WHERE id = $vpid");
            $conn->query("UPDATE produit_bank SET quantite = quantite + {$row['quantite']} WHERE id = {$row['produit_bank_id']}");
            $vb_row = $conn->query("SELECT remise FROM vente_bank WHERE id = {$row['vente_bank_id']}")->fetch_assoc();
            $remise = $vb_row ? floatval($vb_row['remise']) : 0;
            $res   = $conn->query("SELECT COALESCE(SUM(montant),0) AS tot FROM vente_produit WHERE vente_bank_id = {$row['vente_bank_id']}")->fetch_assoc();
            $total = floatval($res['tot']);
            $tva   = round($total * 0.19, 2);
            $ttc   = round($total + $tva - $remise, 2);
            if ($ttc < 0) $ttc = 0;
            $conn->query("UPDATE vente_bank SET total=$total, tva=$tva, total_ttc=$ttc WHERE id={$row['vente_bank_id']}");
            $vente_client = $conn->query("SELECT clients_bank_id FROM vente_bank WHERE id = {$row['vente_bank_id']}")->fetch_assoc();
            if ($vente_client) {
                refresh_client_total_ventes($conn, (int)$vente_client['clients_bank_id']);
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Produit retiré.', 'total' => $total, 'tva' => $tva, 'ttc' => $ttc, 'remise' => $remise]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- MODIFIER QUANTITÉ LIGNE VENTE ---
    if ($action === 'modifier_ligne') {
        $vpid    = intval($_POST['vp_id']);
        $new_qte = intval($_POST['quantite']);
        if ($new_qte <= 0) { echo json_encode(['success' => false, 'message' => 'Quantité invalide.']); exit; }

        $row       = $conn->query("SELECT * FROM vente_produit WHERE id = $vpid")->fetch_assoc();
        $stock_row = $conn->query("SELECT quantite FROM produit_bank WHERE id = {$row['produit_bank_id']}")->fetch_assoc();
        $diff      = $new_qte - $row['quantite'];

        if ($diff > $stock_row['quantite']) {
            echo json_encode(['success' => false, 'message' => 'Stock insuffisant (dispo: ' . $stock_row['quantite'] . ')']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $new_mont = round($new_qte * $row['prix_vente'], 2);
            $conn->query("UPDATE vente_produit SET quantite=$new_qte, montant=$new_mont WHERE id=$vpid");
            $conn->query("UPDATE produit_bank SET quantite = quantite - $diff WHERE id = {$row['produit_bank_id']}");
            $vb_row = $conn->query("SELECT remise FROM vente_bank WHERE id = {$row['vente_bank_id']}")->fetch_assoc();
            $remise = $vb_row ? floatval($vb_row['remise']) : 0;
            $res   = $conn->query("SELECT COALESCE(SUM(montant),0) AS tot FROM vente_produit WHERE vente_bank_id = {$row['vente_bank_id']}")->fetch_assoc();
            $total = floatval($res['tot']);
            $tva   = round($total * 0.19, 2);
            $ttc   = round($total + $tva - $remise, 2);
            if ($ttc < 0) $ttc = 0;
            $conn->query("UPDATE vente_bank SET total=$total, tva=$tva, total_ttc=$ttc WHERE id={$row['vente_bank_id']}");
            $vente_client = $conn->query("SELECT clients_bank_id FROM vente_bank WHERE id = {$row['vente_bank_id']}")->fetch_assoc();
            if ($vente_client) {
                refresh_client_total_ventes($conn, (int)$vente_client['clients_bank_id']);
            }
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Quantité modifiée.', 'montant' => $new_mont, 'total' => $total, 'tva' => $tva, 'ttc' => $ttc, 'remise' => $remise]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- MODIFIER DATE VENTE ---
    if ($action === 'modifier_date') {
        $vid      = intval($_POST['id']);
        $date_raw = $_POST['date_vente'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_raw)) {
            echo json_encode(['success'=>false,'message'=>'Format de date invalide.']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE vente_bank SET date_vente=? WHERE id=?");
        $stmt->bind_param("si", $date_raw, $vid);
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'message'=>'Date modifiée.','date'=>$date_raw]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Erreur mise à jour date.']);
        }
        $stmt->close();
        exit;
    }

    // --- MODIFIER NUMERO DE FACTURE ---
    if ($action === 'modifier_num_facture') {
        $vid = intval($_POST['id']);
        $num_facture = trim($_POST['num_facture'] ?? '');
        if (empty($num_facture)) {
            echo json_encode(['success'=>false,'message'=>'Le numéro de facture ne peut pas être vide.']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE vente_bank SET num_facture=? WHERE id=?");
        $stmt->bind_param("si", $num_facture, $vid);
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'message'=>'Numéro de facture modifié.','num_facture'=>$num_facture]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Erreur mise à jour du numéro de facture.']);
        }
        $stmt->close();
        exit;
    }

    // --- MODIFIER NUMERO DE CHEQUE ---
    if ($action === 'modifier_num_cheque') {
        $vid = intval($_POST['id']);
        $num_cheque = trim($_POST['num_cheque'] ?? '');
        $stmt = $conn->prepare("UPDATE vente_bank SET num_cheque=? WHERE id=?");
        $stmt->bind_param("si", $num_cheque, $vid);
        if ($stmt->execute()) {
            echo json_encode(['success'=>true,'message'=>'Numéro de chèque modifié.','num_cheque'=>$num_cheque]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Erreur mise à jour du numéro de chèque.']);
        }
        $stmt->close();
        exit;
    }

    // --- MODIFIER LA REMISE ---
    if ($action === 'modifier_remise') {
        $vid    = intval($_POST['id']);
        $remise = floatval($_POST['remise'] ?? 0);
        if ($remise < 0) $remise = 0;
        $row = $conn->query("SELECT total, tva FROM vente_bank WHERE id = $vid")->fetch_assoc();
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Vente introuvable.']);
            exit;
        }
        $total = floatval($row['total']);
        $tva   = floatval($row['tva']);
        $ttc   = round($total + $tva - $remise, 2);
        if ($ttc < 0) $ttc = 0;
        $stmt = $conn->prepare("UPDATE vente_bank SET remise=?, total_ttc=? WHERE id=?");
        $stmt->bind_param("ddi", $remise, $ttc, $vid);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Remise mise à jour.', 'remise' => $remise, 'ttc' => $ttc]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur mise à jour remise.']);
        }
        $stmt->close();
        exit;
    }

    // --- DETAIL VENTE ---
    if ($action === 'detail_vente') {
        $vid = intval($_POST['id']);
        $v   = $conn->query("SELECT vb.*, cb.nom, cb.adresse, cb.nrs, cb.nai, cb.nif, cb.nis, cb.nin
                              FROM vente_bank vb
                              JOIN clients_bank cb ON cb.id = vb.clients_bank_id
                              WHERE vb.id = $vid")->fetch_assoc();
        // Ensure backward compat for rows without reglement column
        if (!isset($v['reglement'])) $v['reglement'] = 'RIB';
        if (!isset($v['num_cheque'])) $v['num_cheque'] = '';
        if (!isset($v['num_facture'])) $v['num_facture'] = '';
        if (!isset($v['nin'])) $v['nin'] = '';
        if (!isset($v['remise'])) $v['remise'] = 0;
        $lignes = [];
        $res    = $conn->query("SELECT vp.*, pb.designation FROM vente_produit vp
                                JOIN produit_bank pb ON pb.id = vp.produit_bank_id
                                WHERE vp.vente_bank_id = $vid ORDER BY vp.id");
        while ($r = $res->fetch_assoc()) $lignes[] = $r;
        echo json_encode(['success' => true, 'vente' => $v, 'lignes' => $lignes]);
        exit;
    }

    if ($action === 'client_total_ventes') {
        $client_id = intval($_POST['client_id'] ?? 0);
        if ($client_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Client invalide.']);
            exit;
        }
        refresh_client_total_ventes($conn, $client_id);
        echo json_encode([
            'success' => true,
            'total_ventes' => get_client_total_ventes($conn, $client_id)
        ]);
        exit;
    }
}

// ============================================================
// CHARGEMENT PAGE
// ============================================================
$clients = [];
$r = $conn->query("SELECT id, nom, total_ventes FROM clients_bank ORDER BY nom");
while ($row = $r->fetch_assoc()) $clients[] = $row;

// Get ALL products with stock > 0
$produits_all = [];
$r2 = $conn->query("SELECT id, designation, quantite, prix_vente FROM produit_bank WHERE quantite > 0 ORDER BY designation");
while ($row = $r2->fetch_assoc()) $produits_all[] = $row;

$ventes = [];
$r3 = $conn->query("SELECT vb.*, cb.nom AS client_nom FROM vente_bank vb JOIN clients_bank cb ON cb.id = vb.clients_bank_id ORDER BY vb.id DESC");
while ($row = $r3->fetch_assoc()) $ventes[] = $row;

$tg = $conn->query("SELECT COALESCE(SUM(total_ttc),0) AS s FROM vente_bank")->fetch_assoc();
$total_global = floatval($tg['s']);

// ⬇ PERSONNALISEZ CET EN-TÊTE (affiché sur chaque facture imprimée)
$societe = [
    'nom'      => 'NEBBAA ABDERAHMANE GROS',
    'activite' => 'VENTE EN GROS DES PRODUITS DÉTERGENT, ENTRETIEN MÉNAGER ET PROFESSIONNEL',
    'adresse'  => 'ONE 09 , N02 CHETTIA CHLEF',
    'rc'       => '21 A 45802846 02/00',
    'compte'   => '443991120807',
    'nif'      => '18544040108716700000',
    'nis'      => '1985440401087828',
    'nin'      => '109851420010870003',
    'article'  => 'N:02242422536',
    'tel'      => '07 71 31 66 25',
    'wilaya'   => 'CHLEF',
];
$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gestion des Ventes — Warehouse</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --pri:#1e40af;--pri-h:#1d3ba0;--pri-l:#dbeafe;
  --grn:#16a34a;--grn-l:#dcfce7;
  --red:#dc2626;--red-h:#b91c1c;--red-l:#fee2e2;
  --ora:#d97706;--ora-l:#fef3c7;
  --bg:#f1f5f9;--card:#fff;--border:#e2e8f0;
  --text:#1e293b;--muted:#64748b;
  --radius:10px;--shadow:0 2px 14px rgba(0,0,0,.08)
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* HEADER */
header{background:var(--pri);color:#fff;padding:16px 24px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(30,64,175,.35)}
header .ico{font-size:1.5rem}
header h1{font-size:1.2rem;font-weight:700}
header .sub{font-size:.82rem;opacity:.75;margin-left:auto}

/* PAGE */
.page{max-width:1300px;margin:0 auto;padding:20px 14px;display:flex;flex-direction:column;gap:22px}

/* CARD */
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}
.card-head{padding:13px 18px;border-bottom:1px solid var(--border);font-weight:700;font-size:.93rem;color:var(--pri);display:flex;align-items:center;gap:8px}

/* STATS */
.stats-row{display:flex;gap:14px;flex-wrap:wrap}
.stat-box{flex:1;min-width:200px;background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);padding:16px 20px}
.stat-box .lbl{font-size:.73rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.stat-box .val{font-size:1.4rem;font-weight:800;color:var(--pri)}
.stat-box.green .val{color:var(--grn)}

/* FORM */
.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;padding:16px 18px}
@media(max-width:900px){.form-grid{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.form-grid{grid-template-columns:1fr}}
.field label{display:block;font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.field select,.field input{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:7px;font-size:.92rem;color:var(--text);background:#f8fafc;outline:none;font-family:inherit;transition:border-color .2s}
.field select:focus,.field input:focus{border-color:var(--pri);background:#fff}
.field input[readonly]{background:#eff6ff;color:var(--pri);font-weight:700;border-color:#bfdbfe}

/* TABLE */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.84rem}
thead th{padding:10px 11px;text-align:left;font-size:.73rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid var(--border);white-space:nowrap;background:#f8fafc}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:hover{background:#f0f7ff}
tbody tr.added{background:#dcfce7 !important}
tbody tr.added td{color:#166534}
td{padding:9px 11px;vertical-align:middle}
td.r{text-align:right;font-weight:600}
td.muted{color:var(--muted);font-size:.77rem}
input.qin{width:72px;padding:5px 7px;border:1.5px solid var(--border);border-radius:5px;font-size:.83rem;text-align:center;outline:none;font-family:inherit}
input.qin:focus{border-color:var(--pri)}

/* TOTAUX */
.tot-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:0;border-top:2px solid var(--border)}
.tot-cell{padding:14px 18px;border-right:1px solid var(--border)}
.tot-cell:last-child{border-right:none;background:var(--pri-l)}
.tot-cell .tl{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.tot-cell .tv{font-size:1.1rem;font-weight:800;color:var(--text)}
.tot-cell:last-child .tv{color:var(--pri);font-size:1.25rem}

/* BOUTONS */
.btn{display:inline-flex;align-items:center;gap:5px;padding:9px 15px;border:none;border-radius:7px;font-size:.86rem;font-weight:600;cursor:pointer;transition:background .18s,transform .1s;font-family:inherit;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-pri{background:var(--pri);color:#fff}.btn-pri:hover{background:var(--pri-h)}
.btn-red{background:var(--red);color:#fff}.btn-red:hover{background:var(--red-h)}
.btn-ora{background:var(--ora);color:#fff}.btn-ora:hover{background:#b45309}
.btn-grn{background:var(--grn);color:#fff}.btn-grn:hover{background:#15803d}
.btn-ghost{background:#e2e8f0;color:var(--text)}.btn-ghost:hover{background:#cbd5e1}
.btn-sm{padding:5px 10px;font-size:.77rem}
.btn-row{display:flex;gap:8px;flex-wrap:wrap;padding:14px 18px;border-top:1px solid var(--border)}

/* BADGE */
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.74rem;font-weight:700}
.b-blue{background:var(--pri-l);color:var(--pri)}
.b-green{background:var(--grn-l);color:var(--grn)}
.b-red{background:var(--red-l);color:var(--red)}

/* TOAST */
#toast{position:fixed;bottom:22px;right:22px;padding:11px 18px;border-radius:8px;color:#fff;font-weight:600;font-size:.88rem;box-shadow:0 4px 16px rgba(0,0,0,.18);opacity:0;transform:translateY(8px);transition:opacity .3s,transform .3s;z-index:9999;pointer-events:none}
#toast.show{opacity:1;transform:translateY(0)}
#toast.ok{background:var(--grn)}
#toast.err{background:var(--red)}

/* MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:12px;width:100%;max-width:900px;box-shadow:0 10px 50px rgba(0,0,0,.25);overflow:hidden;margin:auto}
.modal-hd{background:var(--pri);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;font-weight:700}
.modal-bd{padding:20px;max-height:75vh;overflow-y:auto}
.modal-ft{padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:.87rem;margin-bottom:14px;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid var(--border)}
.info-grid span{color:var(--muted);font-size:.75rem;display:block;margin-bottom:2px}

/* SECTION PRODUIT */
.prod-section{padding:0 18px 6px}
.prod-section h3{font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin:12px 0 8px;padding-bottom:5px;border-bottom:1px solid var(--border)}

/* ============ FACTURE PRINT OPTIMISÉE ============ */
#facture-print{display:none}

@media print{
  *{
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  body > *{ display:none !important; }

  #facture-print{
    display:block !important;
    position:static !important;
    background:#fff !important;
    margin:0;
    padding:0;
  }

  #facture-print .invoice-page{
    display:block;
    position: relative;
    width: 210mm;
    min-height: 297mm;
    box-sizing: border-box;
    page-break-after: always;
  }

  #facture-print .invoice-page:last-child{
    page-break-after: auto;
  }

  #facture-print .invoice-header{
    padding: 6mm 8mm 4mm 8mm;
  }

  #facture-print .invoice-body{
    padding: 0 8mm;
  }

  /* Petit espace en haut des pages de suite (2e page, 3e page, ...) pour ne pas
     coller le tableau directement au bord supérieur de la feuille */
  #facture-print .invoice-body-continued{
    padding-top: 6mm;
  }

  #facture-print .invoice-footer{
    padding: 4mm 8mm 6mm 8mm;
    page-break-inside: avoid;
  }

  /* Numéro de page, toujours collé au tout en bas de la feuille imprimée */
  #facture-print .page-number-footer{
    position: absolute;
    left: 0;
    right: 0;
    bottom: 6mm;
    text-align: center;
    font-size: 8pt;
    font-weight: bold;
    color: #000;
  }

  #facture-print table{
    width:100%;
    border-collapse:collapse;
    page-break-inside:auto;
  }

  #facture-print thead{
    display:table-header-group;
  }

  #facture-print tr{
    page-break-inside:avoid;
    page-break-after:auto;
  }

  @page{
    size: A4 portrait;
    margin: 0;
  }
}
</style>
</head>
<body>

<header>
  <span class="ico">🧾</span>
  <h1>Gestion des Ventes</h1>
  <span class="sub">warehouse_db</span>
</header>

<div id="toast"></div>
<div id="facture-print"></div>

<div class="page">

  <!-- ===== STATISTIQUES ===== -->
  <div class="stats-row">
    <div class="stat-box">
      <div class="lbl">Nombre de ventes</div>
      <div class="val" id="nb_ventes"><?= count($ventes) ?></div>
    </div>
    <div class="stat-box green">
      <div class="lbl">Total général toutes ventes (TTC)</div>
      <div class="val" id="total_global"><?= number_format($total_global,2,',',' ') ?> DA</div>
    </div>
  </div>

  <!-- ===== NOUVELLE VENTE ===== -->
  <div class="card">
    <div class="card-head">➕ Créer une nouvelle vente</div>

    <div class="form-grid">
      <div class="field">
        <label>Client *</label>
        <select id="sel_client" onchange="afficherTotalVentesClient()">
          <option value="">— Sélectionnez un client —</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" data-total-ventes="<?= number_format((float)$c['total_ventes'], 2, '.', '') ?>">
            <?= htmlspecialchars($c['nom']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Total ventes client (TTC)</label>
        <input type="text" id="client_total_ventes" value="—" readonly>
      </div>
      <div class="field">
        <label>Date de la vente</label>
        <input type="date" id="vente_date" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="field">
        <label>Numéro de facture *</label>
        <input type="text" id="num_facture" placeholder="Ex: 2026-001" maxlength="50">
      </div>
      <div class="field">
        <label>Mode de règlement *</label>
        <select id="sel_reglement" onchange="toggleCheque()">
          <option value="RIB">💳 RIB (virement)</option>
          <option value="cheque">📝 Chèque</option>
        </select>
      </div>
      <div class="field" id="field_cheque" style="display:none">
        <label>Numéro du chèque</label>
        <input type="text" id="num_cheque" placeholder="Ex: 0012345678" maxlength="30">
      </div>
    </div>

    <div class="prod-section">
      <h3>📦 Produits en stock — entrez la quantité et cliquez sur "Ajouter"</h3>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>N°</th>
              <th>Désignation</th>
              <th>Stock dispo</th>
              <th>Qté à ajouter</th>
              <th style="text-align:right">Prix vente</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="tbody_new">
          <?php if (empty($produits_all)): ?>
            <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">
              ⚠️ Aucun produit en stock disponible.
            </td></tr>
          <?php else: ?>
          <?php foreach ($produits_all as $i => $p): ?>
          <tr id="prow-<?= $p['id'] ?>" data-id="<?= $p['id'] ?>" data-pv="<?= $p['prix_vente'] ?>" data-stock="<?= $p['quantite'] ?>">
            <td class="muted"><?= $i+1 ?></td>
            <td><?= htmlspecialchars($p['designation']) ?></td>
            <td><span class="badge b-blue"><?= $p['quantite'] ?></span></td>
            <td>
              <input type="number" class="qin" id="qte-<?= $p['id'] ?>" value="" placeholder="Qté" min="1" max="<?= $p['quantite'] ?>">
            </td>
            <td class="r"><?= number_format($p['prix_vente'],2,',','.') ?> DA</td>
            <td>
              <button class="btn btn-grn btn-sm" onclick="ajouterProduit(<?= $p['id'] ?>)">➕ Ajouter</button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:12px;">
        <h3 style="font-size:.8rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;padding-bottom:5px;border-bottom:1px solid var(--border)">
          📋 Produits sélectionnés pour cette vente
        </h3>
        <div class="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>N°</th>
                <th>Désignation</th>
                <th style="text-align:right">Qté</th>
                <th style="text-align:right">Prix vente</th>
                <th style="text-align:right">Montant</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="tbody_selected">
              <tr id="no_selected"><td colspan="6" style="text-align:center;padding:15px;color:var(--muted)">Aucun produit sélectionné</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tot-strip">
        <div class="tot-cell">
          <div class="tl">Total HT</div>
          <div class="tv" id="d_total">0,00 DA</div>
        </div>
        <div class="tot-cell">
          <div class="tl">TVA 19%</div>
          <div class="tv" id="d_tva">0,00 DA</div>
        </div>
        <div class="tot-cell">
          <div class="tl">Total TTC</div>
          <div class="tv" id="d_ttc">0,00 DA</div>
        </div>
      </div>

      <div style="padding:14px 18px;border-top:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <label style="font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">💰 Remise (DA)</label>
        <input type="number" id="in_remise" min="0" step="0.01" value="0" oninput="recalcTotaux()"
               style="width:170px;padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;font-size:.92rem;outline:none;font-family:inherit;background:#f8fafc">
        <span style="font-size:.8rem;color:var(--muted)">Déduite du Total TTC ci-dessus une fois saisie.</span>
      </div>
    </div>

    <div class="btn-row">
      <button class="btn btn-pri" onclick="creerVente()">💾 Créer la vente</button>
      <button class="btn btn-ghost" onclick="resetForm()">↺ Réinitialiser</button>
    </div>
  </div>

  <!-- ===== LISTE VENTES ===== -->
  <div class="card">
    <div class="card-head">📋 Toutes les ventes</div>
    <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>N°</th>
            <th>Client</th>
            <th>Date</th>
            <th style="text-align:right">Total HT</th>
            <th style="text-align:right">TVA 19%</th>
            <th style="text-align:right">Total TTC</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tbody_ventes">
        <?php if (empty($ventes)): ?>
          <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted)">Aucune vente enregistrée.</td></tr>
        <?php else: ?>
        <?php foreach ($ventes as $v): ?>
          <tr id="vrow-<?= $v['id'] ?>">
            <td class="muted"><?= $v['id'] ?></td>
            <td><strong><?= htmlspecialchars($v['client_nom']) ?></strong></td>
            <td><?= date('d/m/Y', strtotime($v['date_vente'])) ?></td>
            <td class="r"><?= number_format($v['total'],2,',',' ') ?> DA</td>
            <td class="r"><?= number_format($v['tva'],2,',',' ') ?> DA</td>
            <td class="r"><span class="badge b-green"><?= number_format($v['total_ttc'],2,',',' ') ?> DA</span></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap">
                <button class="btn btn-ora btn-sm" onclick="consulterVente(<?= $v['id'] ?>)">🔍 Consulter</button>
                <button class="btn btn-pri btn-sm" onclick="imprimerVente(<?= $v['id'] ?>)">🖨️ Imprimer</button>
                <button class="btn btn-red btn-sm" onclick="supprimerVente(<?= $v['id'] ?>)" title="Supprimer">🗑️</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /page -->

<!-- ===== MODAL CONSULTER ===== -->
<div class="modal-bg" id="modal_consulter">
  <div class="modal">
    <div class="modal-hd">
      <span>🔍 Détail vente — <span id="m_titre"></span></span>
      <button class="btn btn-ghost btn-sm" onclick="fermerModal()">✕</button>
    </div>
    <div class="modal-bd" id="m_body">
      <p style="color:var(--muted);padding:20px">Chargement…</p>
    </div>
    <div class="modal-ft">
      <button class="btn btn-red" id="m_del_btn">🗑️ Supprimer toute la vente</button>
      <button class="btn btn-pri btn-sm" id="m_print_btn">🖨️ Imprimer</button>
      <button class="btn btn-ghost" onclick="fermerModal()">✕ Fermer</button>
    </div>
  </div>
</div>

<script>
// ================================================================
// UTILITAIRES
// ================================================================
function toast(msg,type='ok'){
  const el=document.getElementById('toast');
  el.textContent=msg; el.className='show '+type;
  clearTimeout(el._t); el._t=setTimeout(()=>el.className='',3200);
}
function fmtDA(n){
  return parseFloat(n).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2})+' DA';
}
function fmtNum(n){
  return parseFloat(n).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2});
}

// ================================================================
// GESTION DES PRODUITS SÉLECTIONNÉS
// ================================================================
let selectedProducts = [];
let productCounter = 0;

function ajouterProduit(id){
  const row = document.getElementById('prow-'+id);
  if(!row) return;
  const stock = parseInt(row.dataset.stock)||0;
  const pv = parseFloat(row.dataset.pv)||0;
  const qteInput = document.getElementById('qte-'+id);
  let qte = parseInt(qteInput.value)||0;
  
  if(qte <= 0){
    toast('Entrez une quantité valide (≥ 1)','err');
    return;
  }
  if(qte > stock){
    toast('Stock insuffisant (dispo: '+stock+')','err');
    return;
  }

  // Check if product already selected
  const existing = selectedProducts.find(p => p.id == id);
  if(existing){
    // Update quantity
    const newQte = existing.quantite + qte;
    if(newQte > stock){
      toast('Stock insuffisant pour cette quantité supplémentaire','err');
      return;
    }
    existing.quantite = newQte;
    existing.montant = roundTo2(newQte * existing.prix_vente);
    // Update row in selected table
    updateSelectedRow(id);
    // Update stock display
    row.dataset.stock = stock - qte;
    row.querySelector('.badge').textContent = stock - qte;
    qteInput.value = '';
    toast('Quantité mise à jour','ok');
    recalcTotaux();
    return;
  }

  // Add new product
  selectedProducts.push({
    id: id,
    designation: row.querySelector('td:nth-child(2)').textContent.trim(),
    quantite: qte,
    prix_vente: pv,
    montant: roundTo2(qte * pv)
  });
  
  // Update stock in product row
  row.dataset.stock = stock - qte;
  row.querySelector('.badge').textContent = stock - qte;
  qteInput.value = '';
  row.classList.add('added');
  
  // Add to selected table
  renderSelectedProducts();
  recalcTotaux();
  toast('Produit ajouté à la vente','ok');
}

function roundTo2(n){
  return Math.round(n * 100) / 100;
}

function updateSelectedRow(id){
  const tr = document.getElementById('sel-'+id);
  if(!tr) return;
  const p = selectedProducts.find(pr => pr.id == id);
  if(!p) return;
  const tds = tr.querySelectorAll('td');
  tds[2].textContent = p.quantite;
  tds[4].textContent = fmtNum(p.montant)+' DA';
}

function renderSelectedProducts(){
  const tbody = document.getElementById('tbody_selected');
  const noSel = document.getElementById('no_selected');
  
  if(selectedProducts.length === 0){
    if(noSel) noSel.style.display = '';
    // Remove all rows except no_selected
    const rows = tbody.querySelectorAll('tr:not(#no_selected)');
    rows.forEach(r => r.remove());
    return;
  }
  
  if(noSel) noSel.style.display = 'none';
  
  // Remove existing rows (except no_selected)
  const rows = tbody.querySelectorAll('tr:not(#no_selected)');
  rows.forEach(r => r.remove());
  
  selectedProducts.forEach((p, index) => {
    const tr = document.createElement('tr');
    tr.id = 'sel-'+p.id;
    tr.innerHTML = `
      <td class="muted">${index+1}</td>
      <td>${p.designation}</td>
      <td class="r">${p.quantite}</td>
      <td class="r">${fmtNum(p.prix_vente)} DA</td>
      <td class="r">${fmtNum(p.montant)} DA</td>
      <td>
        <button class="btn btn-red btn-sm" onclick="retirerProduit(${p.id})">✕ Retirer</button>
      </td>
    `;
    tbody.appendChild(tr);
  });
}

function retirerProduit(id){
  const p = selectedProducts.find(pr => pr.id == id);
  if(!p) return;
  
  // Restore stock in product row
  const row = document.getElementById('prow-'+id);
  if(row){
    const currentStock = parseInt(row.dataset.stock) || 0;
    row.dataset.stock = currentStock + p.quantite;
    row.querySelector('.badge').textContent = currentStock + p.quantite;
    row.classList.remove('added');
  }
  
  selectedProducts = selectedProducts.filter(pr => pr.id != id);
  renderSelectedProducts();
  recalcTotaux();
  toast('Produit retiré de la vente','ok');
}

function recalcTotaux(){
  let tot = 0;
  selectedProducts.forEach(p => { tot += p.montant; });
  const tva = roundTo2(tot * 0.19);
  const remiseInput = document.getElementById('in_remise');
  let remise = remiseInput ? (parseFloat(remiseInput.value) || 0) : 0;
  if(remise < 0) remise = 0;
  let ttc = roundTo2(tot + tva - remise);
  if(ttc < 0) ttc = 0;
  document.getElementById('d_total').textContent = fmtDA(tot);
  document.getElementById('d_tva').textContent = fmtDA(tva);
  document.getElementById('d_ttc').textContent = fmtDA(ttc);
}

function resetForm(){
  // Reset selected products
  selectedProducts.forEach(p => {
    const row = document.getElementById('prow-'+p.id);
    if(row){
      const stock = parseInt(row.dataset.stock) || 0;
      row.dataset.stock = stock + p.quantite;
      row.querySelector('.badge').textContent = stock + p.quantite;
      row.classList.remove('added');
    }
  });
  selectedProducts = [];
  renderSelectedProducts();
  
  // Reset other fields
  document.getElementById('sel_client').value='';
  document.getElementById('client_total_ventes').value='—';
  document.getElementById('num_facture').value='';
  document.getElementById('in_remise').value='0';
  document.querySelectorAll('#tbody_new .qin').forEach(inp => inp.value = '');
  recalcTotaux();
  toast('Formulaire réinitialisé','ok');
}

function toggleCheque(){
  const v=document.getElementById('sel_reglement').value;
  document.getElementById('field_cheque').style.display = v==='cheque' ? '' : 'none';
  if(v!=='cheque') document.getElementById('num_cheque').value='';
}

function afficherTotalVentesClient(){
  const sel=document.getElementById('sel_client');
  const out=document.getElementById('client_total_ventes');
  const opt=sel.options[sel.selectedIndex];
  if(!sel.value || !opt){
    out.value='—';
    return;
  }
  const total=parseFloat(opt.dataset.totalVentes || 0);
  out.value=fmtDA(total);
}

// ================================================================
// CRÉER VENTE
// ================================================================
function creerVente(){
  const cid=document.getElementById('sel_client').value;
  if(!cid){toast('Sélectionnez un client.','err');return;}

  const dateInput=document.getElementById('vente_date').value;
  if(!dateInput){toast('Entrez une date de vente.','err');return;}

  const numFacture=document.getElementById('num_facture').value.trim();
  if(!numFacture){toast('Entrez un numéro de facture.','err');return;}

  if(selectedProducts.length === 0){toast('Aucun produit sélectionné pour cette vente.','err');return;}

  const prods = selectedProducts.map(p => ({
    id: p.id,
    quantite: p.quantite,
    prix_vente: p.prix_vente
  }));

  const total = selectedProducts.reduce((s,p) => s + p.montant, 0);

  const remiseInput = document.getElementById('in_remise');
  let remise = remiseInput ? (parseFloat(remiseInput.value) || 0) : 0;
  if(remise < 0) remise = 0;

  const fd=new FormData();
  const regl = document.getElementById('sel_reglement').value;
  const nchq = document.getElementById('num_cheque').value.trim();
  fd.append('action','creer_vente');
  fd.append('client_id',cid);
  fd.append('date_vente',dateInput);
  fd.append('produits',JSON.stringify(prods));
  fd.append('total',total.toFixed(2));
  fd.append('remise', remise.toFixed(2));
  fd.append('reglement', regl);
  fd.append('num_cheque', nchq);
  fd.append('num_facture', numFacture);

  fetch(window.location.href,{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.success){toast(d.message,'ok');setTimeout(()=>location.reload(),800);}
      else toast(d.message,'err');
    }).catch(()=>toast('Erreur réseau.','err'));
}

// ================================================================
// SUPPRIMER VENTE
// ================================================================
function supprimerVente(id){
  if(!confirm('Supprimer cette vente ? Le stock sera restauré automatiquement.')) return;
  const fd=new FormData();
  fd.append('action','supprimer_vente'); fd.append('id',id);
  fetch(window.location.href,{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.success){
        const row=document.getElementById('vrow-'+id);
        if(row){row.style.transition='opacity .4s';row.style.opacity='0';setTimeout(()=>location.reload(),500);}
        toast(d.message,'ok');
      } else toast(d.message,'err');
    });
}

// ================================================================
// MODAL CONSULTER
// ================================================================
let _modalVid=null;

function consulterVente(id){
  _modalVid=id;
  document.getElementById('modal_consulter').classList.add('open');
  document.getElementById('m_body').innerHTML='<p style="padding:20px;color:var(--muted)">Chargement…</p>';
  _chargerDetailVente(id);
}

function _chargerDetailVente(id){
  const fd=new FormData();
  fd.append('action','detail_vente'); fd.append('id',id);
  fetch(window.location.href,{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(!d.success){toast(d.message,'err');return;}
      const v=d.vente; const lg=d.lignes;

      document.getElementById('m_titre').textContent='N° '+v.id+' — '+v.nom;
      document.getElementById('m_del_btn').onclick=()=>{fermerModal();supprimerVente(id);};
      document.getElementById('m_print_btn').onclick=()=>imprimerVente(id);

      let trs='';
      lg.forEach((l,i)=>{
        trs+=`<tr id="ml-${l.id}">
          <td class="muted">${i+1}</td>
          <td>${l.designation}</td>
          <td><input type="number" class="qin" value="${l.quantite}" min="1" id="mq-${l.id}"
               style="width:65px" onchange="modLigne(${l.id})"></td>
          <td class="r">${fmtNum(l.prix_vente)} DA</td>
          <td class="r" id="mm-${l.id}">${fmtNum(l.montant)} DA</td>
          <td><button class="btn btn-red btn-sm" onclick="delLigne(${l.id})">🗑️</button></td>
        </tr>`;
      });

      const showCheque = v.reglement === 'cheque';

      document.getElementById('m_body').innerHTML=`
        <div class="info-grid">
          <div><span>Client</span><strong>${v.nom}</strong></div>
          <div>
            <span>Date de la vente</span>
            <div style="display:flex;gap:6px;align-items:center;margin-top:2px">
              <input type="date" id="m_date_input" value="${v.date_vente}"
                     style="padding:5px 9px;border:1.5px solid var(--border);border-radius:6px;font-size:.88rem;outline:none;font-family:inherit">
              <button class="btn btn-grn btn-sm" onclick="sauvegarderDate(${v.id})">💾</button>
            </div>
          </div>
          <div>
            <span>Numéro de facture</span>
            <div style="display:flex;gap:6px;align-items:center;margin-top:2px">
              <input type="text" id="m_facture_input" value="${v.num_facture||''}"
                     style="padding:5px 9px;border:1.5px solid var(--border);border-radius:6px;font-size:.88rem;outline:none;font-family:inherit;width:150px">
              <button class="btn btn-grn btn-sm" onclick="sauvegarderFacture(${v.id})">💾</button>
            </div>
          </div>
          <div>
            <span>Remise (DA)</span>
            <div style="display:flex;gap:6px;align-items:center;margin-top:2px">
              <input type="number" id="m_remise_input" value="${v.remise||0}" min="0" step="0.01"
                     style="padding:5px 9px;border:1.5px solid var(--border);border-radius:6px;font-size:.88rem;outline:none;font-family:inherit;width:120px">
              <button class="btn btn-grn btn-sm" onclick="sauvegarderRemise(${v.id})">💾</button>
            </div>
          </div>
          ${showCheque ? `
          <div>
            <span>Numéro de chèque</span>
            <div style="display:flex;gap:6px;align-items:center;margin-top:2px">
              <input type="text" id="m_cheque_input" value="${v.num_cheque||''}"
                     style="padding:5px 9px;border:1.5px solid var(--border);border-radius:6px;font-size:.88rem;outline:none;font-family:inherit;width:150px">
              <button class="btn btn-grn btn-sm" onclick="sauvegarderCheque(${v.id})">💾</button>
            </div>
          </div>
          ` : `<div><span>Mode de règlement</span><strong>${v.reglement}</strong></div>`}
          <div><span>Adresse</span>${v.adresse||'—'}</div>
          <div><span>N.I.F</span>${v.nif||'—'}</div>
          <div><span>N.R.S</span>${v.nrs||'—'}</div>
          <div><span>N.I.S</span>${v.nis||'—'}</div>
          <div><span>N.I.N</span>${v.nin||'—'}</div>
        </div>
        <div class="tbl-wrap">
        <table>
          <thead><tr>
            <th>N°</th><th>Désignation</th><th>Qté</th>
            <th style="text-align:right">P.U HT</th>
            <th style="text-align:right">Montant</th>
            <th></th>
          </tr></thead>
          <tbody id="m_tbody">${trs}</tbody>
        </table></div>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:12px;padding:12px;background:#f0f9ff;border-radius:8px;border:1px solid #bfdbfe;font-size:.9rem">
          <div>Total HT : <strong id="m_tot">${fmtDA(v.total)}</strong></div>
          <div>TVA 19% : <strong id="m_tva">${fmtDA(v.tva)}</strong></div>
          <div>Remise : <strong id="m_remise">${fmtDA(v.remise||0)}</strong></div>
          <div style="color:var(--pri)">Total TTC : <strong id="m_ttc" style="font-size:1.05rem">${fmtDA(v.total_ttc)}</strong></div>
        </div>`;
    });
}

// ================================================================
// SAUVEGARDER DATE
// ================================================================
function sauvegarderDate(vid){
  const dateVal=document.getElementById('m_date_input').value;
  if(!dateVal){toast('Date invalide.','err');return;}
  const fd=new FormData();
  fd.append('action','modifier_date'); fd.append('id',vid); fd.append('date_vente',dateVal);
  fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      const vrow=document.getElementById('vrow-'+vid);
      if(vrow){
        const dateAff=new Date(dateVal).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'});
        vrow.querySelectorAll('td')[2].textContent=dateAff;
      }
      toast(d.message,'ok');
    } else toast(d.message,'err');
  }).catch(()=>toast('Erreur réseau.','err'));
}

// ================================================================
// SAUVEGARDER NUMERO DE FACTURE
// ================================================================
function sauvegarderFacture(vid){
  const factureVal=document.getElementById('m_facture_input').value.trim();
  if(!factureVal){toast('Le numéro de facture ne peut pas être vide.','err');return;}
  const fd=new FormData();
  fd.append('action','modifier_num_facture'); fd.append('id',vid); fd.append('num_facture',factureVal);
  fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      toast(d.message,'ok');
    } else toast(d.message,'err');
  }).catch(()=>toast('Erreur réseau.','err'));
}

// ================================================================
// SAUVEGARDER NUMERO DE CHEQUE
// ================================================================
function sauvegarderCheque(vid){
  const chequeVal=document.getElementById('m_cheque_input').value.trim();
  const fd=new FormData();
  fd.append('action','modifier_num_cheque'); fd.append('id',vid); fd.append('num_cheque',chequeVal);
  fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      toast(d.message,'ok');
    } else toast(d.message,'err');
  }).catch(()=>toast('Erreur réseau.','err'));
}

// ================================================================
// SAUVEGARDER LA REMISE
// ================================================================
function sauvegarderRemise(vid){
  let remiseVal = parseFloat(document.getElementById('m_remise_input').value) || 0;
  if(remiseVal < 0){toast('La remise ne peut pas être négative.','err');return;}
  const fd=new FormData();
  fd.append('action','modifier_remise'); fd.append('id',vid); fd.append('remise',remiseVal.toFixed(2));
  fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      document.getElementById('m_remise').textContent=fmtDA(d.remise);
      document.getElementById('m_ttc').textContent=fmtDA(d.ttc);
      const vrow=document.getElementById('vrow-'+vid);
      if(vrow){
        const tds=vrow.querySelectorAll('td');
        tds[5].querySelector('.badge').textContent=fmtNum(d.ttc)+' DA';
      }
      toast(d.message,'ok');
    } else toast(d.message,'err');
  }).catch(()=>toast('Erreur réseau.','err'));
}

function fermerModal(){
  document.getElementById('modal_consulter').classList.remove('open');
  _modalVid=null;
}

function modLigne(vpid){
  const q=parseInt(document.getElementById('mq-'+vpid).value)||0;
  if(q<=0){toast('Quantité doit être ≥ 1','err');return;}
  const fd=new FormData();
  fd.append('action','modifier_ligne'); fd.append('vp_id',vpid); fd.append('quantite',q);
  fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      document.getElementById('mm-'+vpid).textContent=fmtNum(d.montant)+' DA';
      document.getElementById('m_tot').textContent=fmtDA(d.total);
      document.getElementById('m_tva').textContent=fmtDA(d.tva);
      document.getElementById('m_ttc').textContent=fmtDA(d.ttc);
      const vrow=document.getElementById('vrow-'+_modalVid);
      if(vrow){
        const tds=vrow.querySelectorAll('td');
        tds[3].textContent=fmtNum(d.total)+' DA';
        tds[4].textContent=fmtNum(d.tva)+' DA';
        tds[5].querySelector('.badge').textContent=fmtNum(d.ttc)+' DA';
      }
      toast(d.message,'ok');
    } else toast(d.message,'err');
  });
}

function delLigne(vpid){
  if(!confirm('Retirer ce produit de la vente ? Le stock sera restauré.')) return;
  const fd=new FormData();
  fd.append('action','supprimer_ligne'); fd.append('vp_id',vpid);
  fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.success){
      const lr=document.getElementById('ml-'+vpid);
      lr.style.transition='opacity .3s'; lr.style.opacity='0';
      setTimeout(()=>lr.remove(),300);
      document.getElementById('m_tot').textContent=fmtDA(d.total);
      document.getElementById('m_tva').textContent=fmtDA(d.tva);
      document.getElementById('m_ttc').textContent=fmtDA(d.ttc);
      toast(d.message,'ok');
    } else toast(d.message,'err');
  });
}

document.getElementById('modal_consulter').addEventListener('click',function(e){
  if(e.target===this) fermerModal();
});

// ================================================================
// IMPRIMER FACTURE (WITH PAGINATION)
// ================================================================
function imprimerVente(id){
  const fd=new FormData();
  fd.append('action','detail_vente'); fd.append('id',id);
  fetch(window.location.href,{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(!d.success){toast(d.message,'err');return;}
      const v=d.vente; const lg=d.lignes;

      const numFacture = v.num_facture || String(v.id);
      const dateAff = new Date(v.date_vente).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'});
      const totalProducts = lg.length;

      // Capacités de lignes par type de page (à ajuster si besoin selon la mise en page réelle) :
      // - SINGLE_PAGE_ROWS : facture qui tient sur UNE seule page (entête complet + pied de page) → toujours 38 lignes affichées
      // - ROWS_FIRST_PAGE  : 1ère page d'une facture multi-pages (entête complet société/client, SANS pied de page)
      // - ROWS_OTHER_PAGE  : pages intermédiaires (pas d'entête complet, juste un petit bandeau "suite", SANS pied de page)
      // - ROWS_LAST_PAGE   : dernière page d'une facture multi-pages (bandeau "suite" + pied de page, PAS d'entête complet)
      const SINGLE_PAGE_ROWS = 38;
      const ROWS_FIRST_PAGE  = 44;
      const ROWS_OTHER_PAGE  = 50;
      const ROWS_LAST_PAGE   = 42;
      const fixedRows = SINGLE_PAGE_ROWS; // conservé pour compat avec le remplissage de la page unique

      function enLettres(n){
        const u=['','un','deux','trois','quatre','cinq','six','sept','huit','neuf','dix','onze','douze','treize','quatorze','quinze','seize','dix-sept','dix-huit','dix-neuf'];
        const t=['','','vingt','trente','quarante','cinquante','soixante','soixante','quatre-vingt','quatre-vingt'];
        function c(x){
          if(!x) return '';
          if(x<20) return u[x];
          if(x<100){const d=Math.floor(x/10),r=x%10;if(d===7||d===9)return t[d]+'-'+u[10+r];return t[d]+(r?(r===1&&d!==8?'-et-':'-')+u[r]:'');}
          if(x<1000){const h=Math.floor(x/100),r=x%100;return(h===1?'cent':u[h]+'-cent')+(r?'-'+c(r):'');}
          if(x<1000000){const m=Math.floor(x/1000),r=x%1000;return(m===1?'mille':c(m)+'-mille')+(r?'-'+c(r):'');}
          return x+'';
        }
        const ent=Math.floor(n), dec=Math.round((n-ent)*100);
        let s=c(ent)+' Dinar'+(ent>1?'s':'');
        if(dec>0) s+=' et '+c(dec)+' Centime'+(dec>1?'s':'');
        return s.charAt(0).toUpperCase()+s.slice(1)+'.';
      }

      const ttc=parseFloat(v.total_ttc);

      const S={
        nom:     '<?= addslashes($societe["nom"]) ?>',
        act:     '<?= addslashes($societe["activite"]) ?>',
        adr:     '<?= addslashes($societe["adresse"]) ?>',
        rc:      '<?= addslashes($societe["rc"]) ?>',
        compte:  '<?= addslashes($societe["compte"]) ?>',
        nif:     '<?= addslashes($societe["nif"]) ?>',
        nis:     '<?= addslashes($societe["nis"]) ?>',
        nin:     '<?= addslashes($societe["nin"]) ?>',
        article: '<?= addslashes($societe["article"]) ?>',
        tel:     '<?= addslashes($societe["tel"]) ?>',
        wilaya:  '<?= addslashes($societe["wilaya"]) ?>',
      };

      const reglement = v.reglement || 'RIB';
      const numCheque = v.num_cheque || '';
      const ribLine   = reglement === 'cheque'
        ? `Chèque N° : <strong style="font-size:9pt">${numCheque}</strong>`
        : `RIB : <strong>032 002134439911208 07</strong>`;

      const headerHTML = `
        <div style="border:1.5px solid #000;padding:3px 7px;text-align:center;margin-bottom:3px;">
          <div style="font-weight:bold;font-size:11pt;text-transform:uppercase;letter-spacing:.5px;line-height:1.3;">${S.nom}</div>
          <div style="font-size:7.5pt;margin:1px 0;">${S.act}</div>
          <div style="font-size:7pt;">${S.adr}</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:2px 10px;font-size:7pt;font-weight:bold;border-top:1px solid #000;margin-top:3px;padding-top:3px;">
            <div style="text-align:left;">RC N° : ${S.rc}</div>
            <div style="text-align:center;">NIF : ${S.nif}</div>
            <div style="text-align:right;">Article d'imposition N : ${S.article}</div>
            <div style="text-align:left;">Compte n° : ${S.compte}</div>
            <div style="text-align:center;">NIS : ${S.nis}</div>
            <div style="text-align:right;">Tél : ${S.tel}</div>
            <div style="text-align:left;grid-column:1/2;">NIN : ${S.nin}</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:100px 1fr;border:1.5px solid #000;margin-bottom:3px;">
          <div style="padding:4px 6px;border-right:1.5px solid #000;font-weight:900;font-size:15pt;line-height:1.2;display:flex;flex-direction:column;justify-content:center;">
            FACTURE<br><span style="font-size:8.5pt;font-weight:700;">N° :${numFacture}</span>
          </div>
          <div style="padding:2px 7px;font-size:7.5pt;line-height:1.55;">
            <div style="font-weight:bold;text-align:right;font-size:7.5pt;margin-bottom:1px;">${S.wilaya} le : ${dateAff}</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 4px;margin-top:2px;">
              <div><strong>Doit :</strong> <b>${v.nom||''}</b></div>
              <div><strong>N.R.S :</strong> <b>${v.nrs||''}</b></div>
              <div><strong>N.A.I :</strong> <b>${v.nai||''}</b></div>
              <div><strong>Adresse :</strong> <b>${v.adresse||''}</b></div>
              <div><strong>N.I.S :</strong> <b>${v.nis||''}</b></div>
              <div><strong>N.I.F :</strong> <b>${v.nif||''}</b></div>
              <div style="grid-column:1/2;"><strong>N.I.N :</strong> <b>${v.nin||''}</b></div>
            </div>
          </div>
        </div>`;

      function generateTableRows(start, end, blankRowsToAdd = 0) {
        let rows = '';
        let productCounter = start + 1;
        for(let i = start; i < end; i++){
          const bg = (i - start) % 2 === 0 ? '#fff' : '#f9f9f9';
          const l = lg[i];
          rows += `<tr style="background:${bg}">
            <td style="border:1px solid #000;text-align:center;padding:2px 4px;font-size:7.5pt;">${productCounter}</td>
            <td style="border:1px solid #000;padding:2px 6px;font-size:7.5pt;">${l.designation}</td>
            <td style="border:1px solid #000;text-align:center;padding:2px 4px;font-size:7.5pt;">U</td>
            <td style="border:1px solid #000;text-align:center;padding:2px 4px;font-size:7.5pt;">${l.quantite}</td>
            <td style="border:1px solid #000;text-align:right;padding:2px 6px;font-size:7.5pt;">${parseFloat(l.prix_vente).toFixed(2)}</td>
            <td style="border:1px solid #000;text-align:right;padding:2px 6px;font-size:7.5pt;">${parseFloat(l.montant).toFixed(2)}</td>
          </tr>`;
          productCounter++;
        }
        for(let b = 0; b < blankRowsToAdd; b++){
          const bg = (end - start + b) % 2 === 0 ? '#fff' : '#f9f9f9';
          rows += `<tr style="background:${bg}">
            <td style="border:1px solid #000;text-align:center;padding:2px 4px;font-size:7.5pt;">&nbsp;</td>
            <td style="border:1px solid #000;padding:2px 6px;font-size:7.5pt;">&nbsp;</td>
            <td style="border:1px solid #000;text-align:center;padding:2px 4px;font-size:7.5pt;">&nbsp;</td>
            <td style="border:1px solid #000;text-align:center;padding:2px 4px;font-size:7.5pt;">&nbsp;</td>
            <td style="border:1px solid #000;text-align:right;padding:2px 6px;font-size:7.5pt;">&nbsp;</td>
            <td style="border:1px solid #000;text-align:right;padding:2px 6px;font-size:7.5pt;">&nbsp;</td>
          </tr>`;
        }
        return rows;
      }

      // Découpe la liste des produits en pages, en tenant compte du fait que
      // seule la dernière page doit contenir le pied de page (totaux, RIB, etc.)
      function computePages(total){
        if (total <= SINGLE_PAGE_ROWS) {
          return [{ start: 0, end: total, isFirst: true, isLast: true }];
        }
        const pages = [];
        let start = 0;
        let pageIndex = 0;
        while (start < total) {
          const remaining = total - start;
          const capacityThisPage = pageIndex === 0 ? ROWS_FIRST_PAGE : ROWS_OTHER_PAGE;
          // Une page en position 0 ne peut être la dernière que si elle tient dans
          // la limite d'une facture "page unique" (entête complet + pied de page).
          // Comme on est ici forcément dans le cas total > SINGLE_PAGE_ROWS, cette
          // page ne sera donc jamais la dernière : c'est voulu.
          const lastPageCapacity = pageIndex === 0 ? SINGLE_PAGE_ROWS : ROWS_LAST_PAGE;
          if (remaining <= lastPageCapacity) {
            pages.push({ start, end: total, isFirst: pageIndex === 0, isLast: true });
            start = total;
          } else {
            const end = Math.min(start + capacityThisPage, total);
            pages.push({ start, end, isFirst: pageIndex === 0, isLast: false });
            start = end;
          }
          pageIndex++;
        }
        // Filet de sécurité : si la dernière page créée n'a pas le pied de page
        // (cas où une page a épuisé tous les produits restants sans être "last"),
        // on ajoute une page finale dédiée au pied de page (totaux, RIB, etc.).
        if (pages.length && !pages[pages.length - 1].isLast) {
          pages.push({ start: total, end: total, isFirst: false, isLast: true });
        }
        return pages;
      }

      function generateFooter() {
        return `
          <div style="border:1.5px solid #000;padding:3px 8px;margin-bottom:2px;">
            <div style="display:flex;justify-content:space-between;">
              <div style="font-weight:bold;font-size:8.5pt;">Total HT</div>
              <div style="font-weight:bold;font-size:8.5pt;">${parseFloat(v.total).toFixed(2)}</div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:2px;">
              <div style="font-weight:bold;font-size:8.5pt;">TVA 19%</div>
              <div style="font-weight:bold;font-size:8.5pt;">${parseFloat(v.tva).toFixed(2)}</div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:2px;">
              <div style="font-weight:bold;font-size:8.5pt;">Remise</div>
              <div style="font-weight:bold;font-size:8.5pt;">${parseFloat(v.remise||0).toFixed(2)}</div>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:2px;background:#c8c8c8;padding:3px 4px;">
              <div style="font-weight:bold;font-size:9.5pt;">Total TTC</div>
              <div style="font-weight:bold;font-size:9.5pt;">${ttc.toFixed(2)}</div>
            </div>
          </div>
          <div style="display:flex;gap:8px;">
            <div style="flex:0 0 60%;">
              <div style="border:1.5px solid #000;padding:3px 7px;font-size:7.5pt;margin-bottom:2px;line-height:1.4;">
                <span style="font-weight:bold;">Arrêter la présente facture à la somme de :</span><br>
                <em>${enLettres(ttc)}</em>
              </div>
              <div style="border:1.5px solid #000;padding:3px 7px;font-size:8.5pt;text-align:left;margin-bottom:2px;font-weight:bold;">
                ${ribLine}
              </div>
            </div>
            <div style="flex:0 0 40%;"><!-- Espace réservé au tampon de l'entreprise --></div>
          </div>
        `;
      }

      const pages = computePages(totalProducts);
      const totalPages = pages.length;

      const invoiceHtml = pages.map((pg, idx) => {
        const pageNum = idx + 1;

        // Ne remplir de lignes vides que sur une page unique, pour garder le
        // pied de page bien calé comme avant. Sur une facture multi-pages,
        // on ne force pas le remplissage : le tableau s'arrête naturellement
        // un peu avant le bas de la feuille, laissant la place au numéro de page.
        const blankRowsToAdd = (totalPages === 1) ? Math.max(0, SINGLE_PAGE_ROWS - totalProducts) : 0;
        const rowsHtml = generateTableRows(pg.start, pg.end, blankRowsToAdd);

        const headerBlock = pg.isFirst
          ? headerHTML
          : `<div style="display:flex;justify-content:space-between;align-items:baseline;font-size:7.5pt;font-weight:bold;border-bottom:1px solid #000;padding-bottom:3px;">
               <span>${S.wilaya} le : ${dateAff}</span>
               <span>Facture N° : ${numFacture} — Suite</span>
             </div>`;

        const footerBlock = pg.isLast ? generateFooter() : '';

        const pageNumberBlock = totalPages > 1
          ? `<div class="page-number-footer">Page ${pageNum}/${totalPages}</div>`
          : '';

        return `
          <div class="invoice-page">
            <div class="invoice-header">${headerBlock}</div>
            <div class="invoice-body${pg.isFirst ? '' : ' invoice-body-continued'}">
              <table style="width:100%;border-collapse:collapse;font-size:7.5pt;line-height:1.3;border:1.5px solid #000;">
                <thead>
                  <tr style="background:#e0e0e0;">
                    <th style="border:1.5px solid #000;padding:4px 4px;text-align:center;width:20px;font-weight:bold;">N°</th>
                    <th style="border:1.5px solid #000;padding:4px 8px;text-align:left;font-weight:bold;">DÉSIGNATION</th>
                    <th style="border:1.5px solid #000;padding:4px 4px;text-align:center;width:30px;font-weight:bold;">UNITÉ</th>
                    <th style="border:1.5px solid #000;padding:4px 4px;text-align:center;width:35px;font-weight:bold;">QTÉ</th>
                    <th style="border:1.5px solid #000;padding:4px 6px;text-align:right;width:55px;font-weight:bold;">PU HT</th>
                    <th style="border:1.5px solid #000;padding:4px 6px;text-align:right;width:65px;font-weight:bold;">MONTANT</th>
                  </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
              </table>
            </div>
            <div class="invoice-footer">${footerBlock}</div>
            ${pageNumberBlock}
          </div>
        `;
      }).join('');

      document.getElementById('facture-print').innerHTML = invoiceHtml;
      setTimeout(()=>window.print(),300);
    });
}
</script>
</body>
</html>
<?php
// purchases.php - Gestion des Achats (Admin/Staff) — mobile UX aligned with sales.php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'] ?? '';
$user_id = $_SESSION['user_id'];

// Only Admin/staff can access this page (clients never manage purchases).
if ($is_client) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

function reverse_purchase_stock($conn, $purchase_id) {
    $stmt = $conn->prepare("SELECT product_id, nombre_de_carton, quantité, montant FROM purchase_facture WHERE purchases_id = ?");
    $stmt->bind_param('i', $purchase_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $restore_cartons = (int)$item['nombre_de_carton'];
        if ($restore_cartons > 0) {
            $update = $conn->prepare("UPDATE product SET nombre_de_carton = GREATEST(0, nombre_de_carton - ?) WHERE id = ?");
            $update->bind_param('ii', $restore_cartons, $item['product_id']);
            $update->execute();
            $update->close();
        }
    }
    $stmt->close();
}

function refresh_fournisseur_totals($conn, $fournisseur_id) {
    if ($fournisseur_id <= 0) {
        return;
    }
    $row = $conn->query("SELECT IFNULL(SUM(mon_versement), 0) AS total_versement FROM purchases WHERE fournisseur_id = $fournisseur_id")->fetch_assoc();
    $totalVersement = isset($row['total_versement']) ? (float)$row['total_versement'] : 0.0;
    $row = $conn->query("SELECT IFNULL(credit_actuel, 0) AS credit_actuel FROM purchases WHERE fournisseur_id = $fournisseur_id ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $currentCredit = isset($row['credit_actuel']) ? (float)$row['credit_actuel'] : 0.0;
    $stmt = $conn->prepare("UPDATE fournisseur SET mon_versement_total = ?, mon_credit = ? WHERE id = ?");
    $stmt->bind_param('ddi', $totalVersement, $currentCredit, $fournisseur_id);
    $stmt->execute();
    $stmt->close();
}

/** Creates a purchase from array-style POST fields (mirrors sales.php's create_sale array format). */
function create_purchase($conn, $fournisseur_id, $product_ids, $cartons_list, $prix_unites, $supergross_list, $gross_list, $detail_list, $remise, $mon_versement, $staff_id, &$success, &$error) {
    $fournisseur_id = (int)$fournisseur_id;
    $remise = round((float)$remise, 2);
    $mon_versement = round((float)$mon_versement, 2);

    if ($fournisseur_id <= 0) {
        $error = 'يرجى اختيار مورّد.';
        return null;
    }
    if (!is_array($product_ids) || count($product_ids) === 0) {
        $error = 'يرجى إضافة منتج واحد على الأقل.';
        return null;
    }
    if ($staff_id <= 0) {
        $error = 'مستخدم غير صالح.';
        return null;
    }

    $stmt = $conn->prepare("SELECT nom, mon_credit FROM fournisseur WHERE id = ?");
    $stmt->bind_param('i', $fournisseur_id);
    $stmt->execute();
    $resultF = $stmt->get_result();
    $rowF = $resultF->fetch_assoc();
    $stmt->close();
    if (!$rowF) {
        $error = 'المورّد غير موجود.';
        return null;
    }
    $fournisseurName = $rowF['nom'];
    $mon_credit = (float)$rowF['mon_credit'];

    $query = "INSERT INTO purchases (stuff_id, fournisseur_id, total_montant, mon_credit, total_actuel, remise, mon_versement, credit_actuel) VALUES (?, ?, 0, ?, 0, ?, ?, 0)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iiddd', $staff_id, $fournisseur_id, $mon_credit, $remise, $mon_versement);
    $stmt->execute();
    $purchase_id = $stmt->insert_id;
    $stmt->close();

    $total_montant = 0;
    $lines_inserted = 0;

    for ($i = 0; $i < count($product_ids); $i++) {
        $product_id = (int)$product_ids[$i];
        $nombre_de_carton = (int)($cartons_list[$i] ?? 0);
        $prix_unite = (float)($prix_unites[$i] ?? 0);
        $supergross_prix = round((float)($supergross_list[$i] ?? 0), 2);
        $gross_prix = round((float)($gross_list[$i] ?? 0), 2);
        $detail_prix = round((float)($detail_list[$i] ?? 0), 2);

        if ($product_id <= 0 || $nombre_de_carton <= 0 || $prix_unite <= 0) {
            continue;
        }

        $prod = $conn->query("SELECT reference, unité_par_carton FROM product WHERE id = $product_id")->fetch_assoc();
        if (!$prod) {
            continue;
        }
        $nombre_unite_par_carton = (int)$prod['unité_par_carton'];
        $quantite = $nombre_de_carton * $nombre_unite_par_carton;
        $montant = $quantite * $prix_unite;

        $stmt = $conn->prepare("INSERT INTO purchase_facture (purchases_id, product_id, product_reference, nombre_de_carton, nombre_unite_par_carton, quantité, prix_unite, montant) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iisiiidd', $purchase_id, $product_id, $prod['reference'], $nombre_de_carton, $nombre_unite_par_carton, $quantite, $prix_unite, $montant);
        $stmt->execute();
        $stmt->close();

        $stmtUpdate = $conn->prepare("UPDATE product SET nombre_de_carton = nombre_de_carton + ?, fournisseur = ?, prix_achat = ?, supergross_prix = ?, gross_prix = ?, detail_prix = ? WHERE id = ?");
        $stmtUpdate->bind_param('isddddi', $nombre_de_carton, $fournisseurName, $prix_unite, $supergross_prix, $gross_prix, $detail_prix, $product_id);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        $total_montant += $montant;
        $lines_inserted++;
    }

    if ($lines_inserted === 0) {
        $conn->query("DELETE FROM purchases WHERE id = $purchase_id");
        $error = 'لم يُضف أي منتج صالح للشراء.';
        return null;
    }

    $total_actuel = $total_montant + $mon_credit - $remise;
    $credit_actuel = max($total_actuel - $mon_versement, 0.0);
    $stmt = $conn->prepare("UPDATE purchases SET total_montant = ?, total_actuel = ?, credit_actuel = ? WHERE id = ?");
    $stmt->bind_param('dddi', $total_montant, $total_actuel, $credit_actuel, $purchase_id);
    $stmt->execute();
    $stmt->close();

    refresh_fournisseur_totals($conn, $fournisseur_id);
    $success = 'تم إنشاء الشراء بنجاح!';
    return $purchase_id;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_purchase') {
        $new_purchase_id = create_purchase(
            $conn,
            $_POST['fournisseur_id'] ?? 0,
            $_POST['product_id'] ?? [],
            $_POST['nombre_de_carton'] ?? [],
            $_POST['prix_unite'] ?? [],
            $_POST['supergross_prix'] ?? [],
            $_POST['gross_prix'] ?? [],
            $_POST['detail_prix'] ?? [],
            $_POST['remise'] ?? 0,
            $_POST['mon_versement'] ?? 0,
            (int)$user_id,
            $success,
            $error
        );
        if ($success && $new_purchase_id) {
            header('Location: purchases.php?purchase_id=' . $new_purchase_id);
            exit;
        }
    } elseif ($action === 'delete_purchase') {
        $purchase_id = (int)($_POST['purchase_id'] ?? 0);
        if ($purchase_id > 0) {
            $exists = $conn->query("SELECT id, fournisseur_id FROM purchases WHERE id = $purchase_id")->fetch_assoc();
            if ($exists) {
                reverse_purchase_stock($conn, $purchase_id);
                $conn->query("DELETE FROM purchase_facture WHERE purchases_id = $purchase_id");
                $stmt = $conn->prepare("DELETE FROM purchases WHERE id = ?");
                $stmt->bind_param('i', $purchase_id);
                $stmt->execute();
                $stmt->close();
                if (!empty($exists['fournisseur_id'])) {
                    refresh_fournisseur_totals($conn, (int)$exists['fournisseur_id']);
                }
                $success = 'تم حذف الشراء واستعادة المخزون.';
            } else {
                $error = 'الشراء غير موجود.';
            }
        } else {
            $error = 'معرّف الشراء غير صالح.';
        }
    } elseif ($action === 'validate_purchase') {
        $purchase_id = (int)($_POST['purchase_id'] ?? 0);
        if ($purchase_id > 0) {
            $stmt = $conn->prepare("UPDATE purchases SET status = 'validé' WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('i', $purchase_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = 'تم اعتماد الشراء.';
            } else {
                $error = 'هذا الشراء معتمد مسبقاً أو غير موجود.';
            }
            $stmt->close();
        } else {
            $error = 'معرّف الشراء غير صالح.';
        }
    } else {
        $error = 'إجراء غير صالح.';
    }
}

// --- Data for the page ---
$products = [];
$products_result = $conn->query("SELECT id, nom, reference, unité_par_carton, prix_achat, supergross_prix, gross_prix, detail_prix, nombre_de_carton, marque FROM product ORDER BY nom");
while ($row = $products_result->fetch_assoc()) {
    $products[] = $row;
}

$fournisseurs = [];
$fournisseurs_result = $conn->query("SELECT id, nom, mon_credit FROM fournisseur ORDER BY nom");
while ($row = $fournisseurs_result->fetch_assoc()) {
    $fournisseurs[] = $row;
}

$purchases_list = [];
$purchases_result = $conn->query(
    "SELECT p.id, p.time, f.nom AS fournisseur_nom, p.total_montant, p.status
     FROM purchases p LEFT JOIN fournisseur f ON f.id = p.fournisseur_id
     ORDER BY p.time DESC"
);
if ($purchases_result) {
    $purchases_list = $purchases_result->fetch_all(MYSQLI_ASSOC);
}

$today_purchases = [];
$today_result = $conn->query(
    "SELECT p.id, p.time, f.nom AS fournisseur_nom, p.total_montant, p.status
     FROM purchases p LEFT JOIN fournisseur f ON f.id = p.fournisseur_id
     WHERE DATE(p.time) = CURDATE() ORDER BY p.time DESC"
);
if ($today_result) {
    $today_purchases = $today_result->fetch_all(MYSQLI_ASSOC);
}

$purchase_detail = null;
$purchase_lines = [];
if (isset($_GET['purchase_id'])) {
    $view_purchase_id = (int)$_GET['purchase_id'];
    if ($view_purchase_id > 0) {
        $stmt = $conn->prepare(
            "SELECT p.*, f.nom AS fournisseur_nom, a.nom AS admin_nom
             FROM purchases p
             LEFT JOIN fournisseur f ON f.id = p.fournisseur_id
             LEFT JOIN admin a ON a.id = p.stuff_id
             WHERE p.id = ?"
        );
        $stmt->bind_param('i', $view_purchase_id);
        $stmt->execute();
        $purchase_detail = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($purchase_detail) {
            $stmt = $conn->prepare("SELECT pf.*, pr.nom AS product_nom FROM purchase_facture pf LEFT JOIN product pr ON pr.id = pf.product_id WHERE pf.purchases_id = ? ORDER BY pf.id");
            $stmt->bind_param('i', $view_purchase_id);
            $stmt->execute();
            $purchase_lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

// Decide which of the 3 mobile screens (menu / create / list / detail) should be visible on load.
$initial_screen = 'menu';
if ($purchase_detail) {
    $initial_screen = 'detail';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    if ($post_action === 'create_purchase') {
        $initial_screen = $success ? 'detail' : 'create';
    } elseif (in_array($post_action, ['delete_purchase', 'validate_purchase'], true)) {
        $initial_screen = 'list';
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>المشتريات — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1e3a5f">

    <style>
        :root {
            --primary: #1e3a5f;
            --primary-dark: #0f2a45;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --success-dark: #059669;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f0f9ff;
            --border: #b8d4f0;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
            --radius-lg: 0.75rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body.nc-app-body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
        }

        header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: white;
            padding: 1rem 2rem;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
            box-shadow: var(--shadow-lg);
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg);
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid var(--border);
        }

        .card:hover {
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        .card h2 {
            color: var(--primary);
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card h2::before {
            content: '';
            width: 4px;
            height: 20px;
            background: var(--primary-light);
            border-radius: 2px;
        }

        .card h3 {
            color: var(--primary);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #d1fae5;
            border-left: 4px solid var(--success);
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.875rem;
        }

        .form-group label.required::after {
            content: '*';
            color: var(--danger);
            margin-left: 0.25rem;
        }

        input, select {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.875rem;
            transition: all 0.2s;
            background: white;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        button, .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        button:hover, .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, var(--success-dark) 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, var(--warning-dark) 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            color: white;
        }

        .btn-red {
            background: var(--danger);
            color: white;
        }

        .btn-green {
            background: var(--success);
            color: white;
        }

        .product-line {
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1rem;
            margin-bottom: 1rem;
            position: relative;
            background: var(--light);
        }

        .remove-product-line {
            background: var(--danger);
            color: white;
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }

        /* Staff: إنشاء بيع — layout + combobox + pending table */
        .card--sale-create {
            padding: 1rem 1.25rem;
            overflow: visible;
        }

        .staff-sale-form-inner {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .staff-draft-line {
            margin-bottom: 0;
            padding: 0.75rem;
            overflow: visible;
            position: relative;
            z-index: 20;
        }

        .staff-draft-line.dropdown-open {
            /* Push action buttons lower while dropdown is open to avoid tap-through */
            padding-bottom: 9rem;
        }

        .staff-draft-line .staff-sale-two-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.65rem 1rem;
            align-items: start;
            overflow: visible;
        }

        .staff-col {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            min-width: 0;
            overflow: visible;
        }

        .staff-col .form-group {
            margin-bottom: 0;
        }

        .staff-col .form-group input,
        .staff-col .form-group select {
            padding: 0.45rem 0.55rem;
            font-size: 0.875rem;
            width: 100%;
            box-sizing: border-box;
        }

        .product-combobox-wrap {
            position: relative;
            z-index: 1;
        }

        .product-combobox-wrap.is-open {
            z-index: 6000;
        }

        .product-combobox-wrap .product-search {
            position: relative;
            z-index: 9600;
            background: #fff;
        }

        /* While the picker is open, the field itself pins to the top of the screen so it's
           always visible above the results list — never covered by it, never covering it. */
        .product-combobox-wrap.is-open .product-search {
            position: fixed;
            top: 8px;
            left: 10px;
            right: 10px;
            width: auto;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.2);
        }

        .staff-combobox-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: transparent;
            z-index: 8990;
        }
        .staff-combobox-backdrop.is-open { display: block; }

        .product-combobox-list {
            display: none;
            position: fixed;
            left: 10px;
            right: 10px;
            bottom: 8px;
            z-index: 9000;
            margin: 0;
            padding: 0.4rem;
            list-style: none;
            max-height: 42vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg) var(--radius-lg) 0.5rem 0.5rem;
            box-shadow: 0 -10px 30px rgba(15, 23, 42, 0.25);
            touch-action: manipulation;
            pointer-events: auto;
        }

        .product-combobox-list.is-open {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            align-content: flex-start;
        }

        .product-combobox-list li {
            flex: 0 0 calc(50% - 0.24rem);
            box-sizing: border-box;
            padding: 0.7rem 0.5rem;
            cursor: pointer;
            font-size: 0.88rem;
            font-weight: 700;
            line-height: 1.28;
            text-align: center;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--light);
            overflow-wrap: break-word;
            word-break: break-word;
            white-space: normal;
        }

        .product-combobox-list li.combobox-noresult {
            flex: 1 1 100%;
        }

        .product-combobox-list li:hover,
        .product-combobox-list li:active,
        .product-combobox-list li[aria-selected="true"] {
            background: #e0f2fe;
            border-color: var(--primary-light);
        }

        .staff-draft-footer {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.65rem;
            margin-top: 0.5rem;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .staff-draft-footer .btn {
            width: 100%;
            padding: 0.7rem 0.9rem;
            font-size: 0.85rem;
            min-height: 46px;
        }

        .staff-submit-wrap {
            margin-top: 0.25rem;
            position: relative;
            z-index: 1;
        }

        .staff-submit-wrap .btn-success {
            width: 100%;
            padding: 0.78rem 1rem;
            min-height: 48px;
        }

        .staff-meta-hint {
            margin-top: 2px;
            font-size: 0.72rem;
            color: var(--gray);
            line-height: 1.25;
        }

        .staff-pending-wrap {
            margin-top: 0.5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            background: #f8fafc;
        }

        .staff-pending-wrap .table-responsive {
            max-height: min(38vh, 300px);
            overflow: auto;
        }

        .staff-pending-wrap h3 {
            margin: 0;
            padding: 0.5rem 0.75rem;
            font-size: 0.9rem;
            background: #e0f2fe;
            border-bottom: 1px solid var(--border);
        }

        .staff-pending-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            font-size: 0.8rem;
        }

        .staff-pending-table th,
        .staff-pending-table td {
            padding: 0.45rem 0.5rem;
            border-bottom: 1px solid var(--border);
            text-align: right;
        }

        .staff-pending-table th {
            background: #f1f5f9;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .staff-pending-table .pending-actions {
            white-space: nowrap;
        }

        .staff-pending-table .pending-actions .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            margin-left: 0.25rem;
        }

        .staff-pending-empty {
            padding: 0.75rem;
            text-align: center;
            color: var(--gray);
            font-size: 0.85rem;
        }

        /* Phone: same two columns as PC, smaller controls so the draft form fits on screen */
        @media (max-width: 640px) {
            .card--sale-create {
                padding: 0.3rem 0.35rem;
            }

            .card--sale-create h2 {
                font-size: 0.8rem;
                margin-bottom: 0.3rem;
            }

            .staff-sale-form-inner {
                gap: 0.3rem;
            }

            .product-line.staff-draft-line {
                padding: 0.25rem 0.3rem;
            }

            .staff-draft-line .staff-sale-two-cols {
                grid-template-columns: 1fr 1fr;
                gap: 0.22rem 0.28rem;
            }

            .staff-col {
                gap: 0.22rem;
            }

            .staff-col .form-group input,
            .staff-col .form-group select {
                padding: 0.22rem 0.26rem;
                font-size: 0.62rem;
            }

            .product-combobox-list {
                max-height: min(38vh, 200px);
                font-size: 0.85rem;
            }

            .product-combobox-list li {
                padding: 0.65rem 0.55rem;
                font-size: 0.85rem;
                font-weight: 700;
            }

            .staff-meta-hint {
                font-size: 0.52rem;
            }

            .staff-draft-footer {
                gap: 0.25rem;
                margin-top: 0.25rem;
            }

            .staff-draft-footer .btn {
                padding: 0.5rem 0.45rem;
                font-size: 0.62rem;
                min-height: 36px;
            }

            .staff-submit-wrap .btn-success {
                padding: 0.56rem 0.55rem;
                font-size: 0.65rem;
                min-height: 38px;
            }

            .staff-pending-wrap h3 {
                padding: 0.25rem 0.35rem;
                font-size: 0.68rem;
            }

            .staff-pending-empty {
                padding: 0.3rem;
                font-size: 0.6rem;
            }

            .staff-pending-wrap .table-responsive {
                max-height: min(20vh, 120px);
            }

            .staff-pending-table {
                font-size: 0.58rem;
            }

            .staff-pending-table th,
            .staff-pending-table td {
                padding: 0.18rem 0.24rem;
            }

            .staff-pending-table .pending-actions .btn {
                padding: 0.12rem 0.22rem;
                font-size: 0.52rem;
            }
        }

        @media (max-width: 768px) {
            .staff-sale-form-inner {
                gap: 0.2rem;
            }

            .staff-draft-line {
                padding: 0.4rem 0.45rem;
            }

            .staff-draft-line .staff-sale-two-cols {
                gap: 0.2rem 0.3rem;
            }

            .staff-draft-line .form-group {
                margin-top: 0;
                margin-bottom: 0;
            }

            .staff-draft-line .form-group label {
                margin-bottom: 0.1rem;
                line-height: 1.1;
            }

            .staff-draft-footer {
                margin-top: 0.2rem;
                gap: 0.35rem;
            }

            .staff-submit-wrap {
                margin-top: 0.2rem;
            }

            .staff-draft-footer .btn,
            .staff-submit-wrap .btn-success {
                min-height: 50px;
                padding-top: 0.78rem;
                padding-bottom: 0.78rem;
                line-height: 1.2;
            }

            .staff-draft-line.dropdown-open {
                padding-bottom: 10.8rem;
            }
        }

        @media (max-width: 520px) {
            .card--sale-create {
                padding: 0.25rem 0.28rem;
            }

            .card--sale-create h2 {
                font-size: 0.75rem;
                margin-bottom: 0.25rem;
            }

            .staff-sale-form-inner {
                gap: 0.24rem;
            }

            .product-line.staff-draft-line {
                padding: 0.22rem 0.26rem;
            }

            .staff-draft-line .staff-sale-two-cols {
                grid-template-columns: 1fr 1fr;
                gap: 0.18rem 0.24rem;
            }

            .staff-col {
                gap: 0.18rem;
            }

            .staff-col .form-group input,
            .staff-col .form-group select {
                padding: 0.18rem 0.22rem;
                font-size: 0.58rem;
            }

            .staff-meta-hint {
                font-size: 0.48rem;
            }

            .staff-draft-footer {
                gap: 0.2rem;
                margin-top: 0.2rem;
            }

            .staff-draft-footer .btn {
                min-height: 50px;
                padding: 0.62rem 0.42rem;
                font-size: 0.58rem;
                line-height: 1.2;
            }

            .staff-submit-wrap .btn-success {
                min-height: 50px;
                padding: 0.66rem 0.5rem;
                font-size: 0.6rem;
                line-height: 1.2;
            }

            .staff-draft-line.dropdown-open {
                padding-bottom: 10.8rem;
            }
        }

        @media (max-width: 380px) {
            .card--sale-create {
                padding: 0.2rem 0.22rem;
            }

            .card--sale-create h2 {
                font-size: 0.7rem;
                margin-bottom: 0.2rem;
            }

            .staff-sale-form-inner {
                gap: 0.18rem;
            }

            .product-line.staff-draft-line {
                padding: 0.18rem 0.2rem;
            }

            .staff-draft-line .staff-sale-two-cols {
                grid-template-columns: 1fr 1fr;
                gap: 0.15rem 0.2rem;
            }

            .staff-col {
                gap: 0.15rem;
            }

            .staff-col .form-group input,
            .staff-col .form-group select {
                padding: 0.15rem 0.18rem;
                font-size: 0.54rem;
            }

            .staff-meta-hint {
                font-size: 0.45rem;
            }

            .staff-draft-footer {
                gap: 0.15rem;
                margin-top: 0.15rem;
            }

            .staff-draft-footer .btn {
                min-height: 50px;
                padding: 0.6rem 0.4rem;
                font-size: 0.54rem;
                line-height: 1.2;
            }

            .staff-submit-wrap .btn-success {
                min-height: 50px;
                padding: 0.64rem 0.48rem;
                font-size: 0.56rem;
                line-height: 1.2;
            }

            .staff-draft-line.dropdown-open {
                padding-bottom: 10.8rem;
            }
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 1rem;
        }

        thead th {
            background: #e0f2fe;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 0.875rem;
            border-bottom: 2px solid var(--border);
        }

        tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }

        tbody tr:hover {
            background: var(--light);
        }

        .sale-row-light {
            background: #f8fafc;
        }

        .sale-row-dark {
            background: #0f172a;
            color: #f8fafc;
        }

        .sale-row-dark td {
            color: #f8fafc;
            border-color: rgba(255,255,255,0.08);
        }

        /* Affichage produit : graisse (normal / gras / fin), plus de couleurs */
        .product-name-normal {
            color: inherit;
            font-weight: 500;
        }

        .product-name-red {
            color: inherit;
            font-weight: 800;
        }

        .product-name-blue {
            color: inherit;
            font-weight: 300;
            font-size: 0.92em;
        }

        .product-name-green {
            color: inherit;
            font-weight: 500;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status.en_cours {
            background: #fef3c7;
            color: var(--warning-dark);
        }

        .status.pending {
            background: #fef3c7;
            color: var(--warning-dark);
        }

        .status.validé {
            background: #d1fae5;
            color: var(--success-dark);
        }

        .status.preparé {
            background: #e0f2fe;
            color: #075985;
        }

        .status.pret {
            background: #dcfce7;
            color: #166534;
        }

        .status.annulé {
            background: #fee2e2;
            color: var(--danger-dark);
        }

        .small-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }

        .small-table th, .small-table td {
            padding: 0.5rem;
            border: 1px solid var(--border);
            font-size: 0.75rem;
        }

        .small-table th {
            background: #e0f2fe;
        }

        .search-form {
            margin-bottom: 1rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-form input {
            flex: 1;
            min-width: 200px;
        }

        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-link a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.15);
            border-radius: 50px;
            transition: all 0.2s;
        }

        .back-link a:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        .flex-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        small {
            color: var(--gray);
            font-size: 0.75rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .card {
                padding: 1rem;
            }

            .product-line {
                padding: 0.75rem;
            }

            .card--sale-create .product-line.staff-draft-line {
                padding: 0.4rem 0.5rem;
            }

            .flex-buttons {
                flex-direction: column;
            }

            .flex-buttons button,
            .flex-buttons .btn {
                width: 100%;
            }

            thead th, tbody td {
                padding: 0.5rem;
            }

            .search-form {
                flex-direction: column;
            }

            .search-form button {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            header {
                font-size: 1.2rem;
                padding: 0.75rem;
            }

            .card h2 {
                font-size: 1rem;
            }
        }

        /* Mobile overrides: keep 2-column sale form, compact controls and table */
        @media (max-width: 768px) {
            body,
            .container,
            .card,
            .card--sale-create {
                margin: 0;
                padding: 0.35rem;
            }

            .staff-sale-form-inner {
                gap: 0.2rem;
            }

            .product-line.staff-draft-line {
                padding: 0.32rem 0.38rem;
            }

            .staff-draft-line .staff-sale-two-cols.form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.3rem 0.35rem;
                align-items: start;
            }

            .staff-draft-line .staff-sale-two-cols {
                gap: 0.2rem 0.28rem;
            }

            .staff-draft-line .form-group {
                margin-top: 0;
                margin-bottom: 0;
            }

            .staff-draft-line .form-group label {
                margin-bottom: 0.08rem;
                line-height: 1.1;
            }

            .staff-col {
                gap: 0.3rem;
                min-width: 0;
            }

            .staff-col .form-group {
                margin: 0;
            }

            .staff-col .form-group input,
            .staff-col .form-group select {
                height: 30px;
                min-height: 30px;
                padding: 0.2rem 0.35rem;
                font-size: 0.68rem;
                line-height: 1.1;
            }

            .staff-meta-hint {
                font-size: 0.58rem;
                line-height: 1.1;
            }

            .staff-draft-footer {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.3rem;
                margin-top: 0.2rem;
            }

            .staff-draft-footer .btn,
            .staff-submit-wrap .btn-success {
                min-height: 80px;
                padding: 1rem 0.7rem;
                font-size: 0.66rem;
                line-height: 1.2;
                white-space: nowrap;
            }

            .staff-submit-wrap {
                margin-top: 0.2rem;
            }

            .staff-draft-line.dropdown-open {
                padding-bottom: 14rem;
            }

            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .staff-pending-table {
                width: 100%;
                min-width: 620px;
                table-layout: fixed;
                font-size: 0.64rem;
                border-collapse: collapse;
            }

            .staff-pending-table th,
            .staff-pending-table td {
                padding: 0.2rem 0.25rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .staff-pending-table .pending-actions {
                white-space: nowrap;
            }

            .staff-pending-table .pending-actions .btn {
                height: 24px;
                min-height: 24px;
                padding: 0.1rem 0.25rem;
                font-size: 0.58rem;
                line-height: 1;
                margin-left: 0.15rem;
                white-space: nowrap;
            }

            .staff-pending-table .pending-actions .btn i,
            .staff-pending-table .pending-actions .btn svg {
                width: 11px;
                height: 11px;
            }
        }

        @media (max-width: 520px) {
            .card--sale-create {
                padding: 0.25rem;
            }

            .staff-draft-line .staff-sale-two-cols.form-grid {
                gap: 0.24rem 0.28rem;
            }

            .staff-col .form-group input,
            .staff-col .form-group select {
                height: 28px;
                min-height: 28px;
                font-size: 0.62rem;
                padding: 0.16rem 0.28rem;
            }

            .staff-draft-footer .btn,
            .staff-submit-wrap .btn-success {
                min-height: 80px;
                font-size: 0.62rem;
                padding: 0.95rem 0.58rem;
                line-height: 1.2;
            }

            .staff-draft-line.dropdown-open {
                padding-bottom: 14rem;
            }

            .staff-pending-table {
                min-width: 580px;
                font-size: 0.6rem;
            }

            .staff-pending-table .pending-actions .btn {
                height: 22px;
                font-size: 0.54rem;
                padding: 0.08rem 0.2rem;
            }
        }

        @media (max-width: 380px) {
            .staff-draft-line .staff-sale-two-cols.form-grid {
                gap: 0.2rem 0.22rem;
            }

            .staff-col .form-group input,
            .staff-col .form-group select {
                height: 26px;
                min-height: 26px;
                font-size: 0.58rem;
                padding: 0.12rem 0.22rem;
            }

            .staff-pending-table {
                min-width: 540px;
                font-size: 0.56rem;
            }

            .staff-draft-footer .btn,
            .staff-submit-wrap .btn-success {
                min-height: 80px;
                padding: 0.9rem 0.5rem;
                line-height: 1.15;
            }

            .staff-draft-line.dropdown-open {
                padding-bottom: 14rem;
            }
        }
    </style>
    <style>
        /* ===================== NC MOBILE SCREENS UX ===================== */
        .nc-screen { display: none; }
        .nc-screen.active { display: block; animation: nc-fade-in 0.18s ease; }
        @keyframes nc-fade-in {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .nc-screen-nav {
            position: sticky;
            top: 0;
            z-index: 20;
            margin-bottom: 0.75rem;
        }
        .nc-back-btn {
            width: 100%;
        }

        /* ---- Menu screen ---- */
        .nc-menu-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        @media (min-width: 640px) {
            .nc-menu-grid { grid-template-columns: repeat(3, 1fr); }
        }
        .nc-menu-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            border: none;
            border-radius: var(--radius-lg);
            padding: 1.1rem 1.25rem;
            font-size: 1.05rem;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            transition: transform 0.12s ease, filter 0.12s ease;
        }
        .nc-menu-btn:active { transform: scale(0.98); filter: brightness(0.95); }
        .nc-menu-btn-icon { font-size: 1.6rem; line-height: 1; }
        .nc-menu-btn-label { flex: 1; text-align: start; }
        .nc-menu-btn--dashboard { background: linear-gradient(135deg, var(--secondary), var(--dark)); }
        .nc-menu-btn--create { background: linear-gradient(135deg, var(--success), var(--success-dark)); }
        .nc-menu-btn--list { background: linear-gradient(135deg, var(--primary-light), var(--primary)); }
        .nc-menu-btn--return { background: linear-gradient(135deg, #f59e0b, #b45309); }

        /* ---- POS-style create sale card ---- */
        .nc-pos-card { padding: 0.85rem; margin-bottom: 0; }
        .nc-pos-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            background: var(--primary-dark);
            border-radius: var(--radius-lg);
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
        }
        .nc-pos-total-box { display: flex; flex-direction: column; }
        .nc-pos-total-label { color: #bcd4ee; font-size: 0.75rem; }
        .nc-pos-total-value { color: #fff; font-size: 1.9rem; font-weight: 800; line-height: 1.15; }
        .nc-pos-count-box { display: flex; flex-direction: column; align-items: center; }
        .nc-pos-count-label { color: #bcd4ee; font-size: 0.7rem; }
        .nc-pos-count-value {
            color: #fff;
            font-size: 1.2rem;
            font-weight: 800;
            background: rgba(255,255,255,0.15);
            border-radius: 999px;
            min-width: 2rem;
            padding: 0.1rem 0.6rem;
            text-align: center;
        }

        .nc-pos-product-group {
            margin-bottom: 0.6rem;
        }
        .nc-pos-row3 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
            overflow: hidden;
            max-height: 200px;
            opacity: 1;
            transition: max-height 0.2s ease, opacity 0.2s ease, margin 0.2s ease;
        }
        .nc-pos-row3.nc-hidden {
            max-height: 0;
            opacity: 0;
            margin: 0;
            pointer-events: none;
        }
        @media (max-width: 640px) {
            .nc-pos-row3 { grid-template-columns: 1fr 1fr; }
        }
        .nc-pos-qty-group input { background: var(--light); }
        .nc-pos-row3 .line-amount { border-color: var(--primary-light); }

        .nc-pos-bottom-bar {
            position: sticky;
            bottom: 0;
            z-index: 15;
            display: flex;
            align-items: flex-end;
            gap: 0.6rem;
            background: #fff;
            border-top: 1px solid var(--border);
            padding: 0.75rem 0.25rem 0.25rem;
            margin-top: 1rem;
        }
        .nc-pos-client-group { flex: 1; margin-bottom: 0; }
        .nc-pos-confirm-btn { white-space: nowrap; padding: 0.7rem 1.25rem; }
        .nc-pos-cancel-btn { white-space: nowrap; padding: 0.7rem 1rem; }

        /* ---- Confirm modal ---- */
        .nc-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            z-index: 100;
        }
        @media (min-width: 640px) {
            .nc-modal-overlay { align-items: center; }
        }
        .nc-modal {
            background: #fff;
            width: 100%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            padding: 1.25rem;
            box-shadow: var(--shadow-lg);
        }
        @media (min-width: 640px) {
            .nc-modal { border-radius: var(--radius-lg); }
        }
        .nc-modal h3 { margin-bottom: 0.75rem; }
        .nc-modal-row {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        .nc-modal-row--strong { font-size: 1rem; font-weight: 700; }

        /* ---- Product entry modal (price + quantity) ---- */
        .nc-pm-stock-info {
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }
        .nc-pm-big-input {
            width: 100%;
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
            padding: 0.6rem;
        }
    </style>

    <script>
        const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
        const fournisseurs = <?= json_encode($fournisseurs, JSON_UNESCAPED_UNICODE) ?>;
        window.__initialScreen = <?= json_encode($initial_screen, JSON_UNESCAPED_UNICODE) ?>;

        function escapeHtmlP(s) {
            const t = document.createElement('textarea');
            t.textContent = s == null ? '' : String(s);
            return t.innerHTML;
        }

        function findProductById(id) {
            return products.find((p) => Number(p.id) === Number(id)) || null;
        }

        function findFournisseurById(id) {
            return fournisseurs.find((f) => Number(f.id) === Number(id)) || null;
        }

        /* ===================== SCREENS ===================== */
        function showScreen(name) {
            document.querySelectorAll('.nc-screen').forEach((el) => el.classList.remove('active'));
            const el = document.getElementById('screen-' + name);
            if (el) el.classList.add('active');
            try { localStorage.setItem('nc_purchases_screen', name); } catch (e) { /* ignore */ }
            window.scrollTo(0, 0);
        }

        function initScreens() {
            let target = window.__initialScreen || 'menu';
            if (target === 'menu') {
                try {
                    const saved = localStorage.getItem('nc_purchases_screen');
                    if (saved && document.getElementById('screen-' + saved)) target = saved;
                } catch (e) { /* ignore */ }
            }
            if (!document.getElementById('screen-' + target)) target = 'menu';
            showScreen(target);
        }

        /* ===================== PENDING PURCHASE ITEMS ===================== */
        let pendingItems = [];
        let editingIndex = null;
        let modalProduct = null;

        function draftLineEl() {
            return document.querySelector('.purchase-draft-line');
        }

        function clearDraft() {
            const line = draftLineEl();
            if (!line) return;
            line.querySelector('.product-search').value = '';
            line.querySelector('.product-id').value = '';
            line.querySelector('.product-units').textContent = '0';
            const marque = line.querySelector('.product-marque');
            if (marque) marque.textContent = '-';
            editingIndex = null;
        }

        function getPendingCartonsForProduct(productId, excludeIndex = null) {
            let total = 0;
            pendingItems.forEach((row, index) => {
                if (excludeIndex !== null && index === excludeIndex) return;
                if (Number(row.productId) === Number(productId)) total += parseInt(row.cartons || '0', 10);
            });
            return total;
        }

        function updateLiveTotal() {
            const el = document.getElementById('purchase_live_total');
            const countEl = document.getElementById('purchase_live_count');
            const total = pendingItems.reduce((sum, row) => sum + (Number(row.montant) || 0), 0);
            if (el) el.textContent = total.toFixed(2);
            if (countEl) countEl.textContent = String(pendingItems.length);
        }

        function renderPendingTable() {
            const tbody = document.querySelector('#purchase_pending_tbody');
            const empty = document.querySelector('#purchase_pending_empty');
            updateLiveTotal();
            if (!tbody) return;
            tbody.innerHTML = '';
            if (pendingItems.length === 0) {
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';
            pendingItems.forEach((r, i) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${escapeHtmlP(r.nom)}</td>
                    <td>${escapeHtmlP(r.reference)}</td>
                    <td>${r.cartons}</td>
                    <td>${r.quantite}</td>
                    <td>${Number(r.unitPrice).toFixed(2)}</td>
                    <td>${Number(r.montant).toFixed(2)}</td>
                    <td class="pending-actions">
                        <button type="button" class="btn btn-warning" data-idx="${i}">✏️ تعديل</button>
                        <button type="button" class="btn btn-danger" data-del="${i}">🗑️ حذف</button>
                    </td>`;
                tbody.appendChild(tr);
            });
            tbody.querySelectorAll('button[data-idx]').forEach((btn) => {
                btn.addEventListener('click', () => editPendingRow(parseInt(btn.getAttribute('data-idx'), 10)));
            });
            tbody.querySelectorAll('button[data-del]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (confirm('حذف هذا المنتج من القائمة؟')) {
                        deletePendingRow(parseInt(btn.getAttribute('data-del'), 10));
                    }
                });
            });
        }

        function deletePendingRow(index) {
            pendingItems.splice(index, 1);
            if (editingIndex !== null) {
                if (editingIndex === index) editingIndex = null;
                else if (editingIndex > index) editingIndex -= 1;
            }
            renderPendingTable();
        }

        function editPendingRow(index) {
            const row = pendingItems[index];
            if (!row) return;
            editingIndex = index;
            const p = findProductById(row.productId);
            openProductModal(row.productId, row);
        }

        function fillHiddenInputsForSubmit() {
            const box = document.getElementById('purchase-hidden-inputs');
            if (!box) return;
            box.innerHTML = '';
            const addField = (name, value) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                box.appendChild(input);
            };
            pendingItems.forEach((r) => {
                addField('product_id[]', String(r.productId));
                addField('nombre_de_carton[]', String(r.cartons));
                addField('prix_unite[]', Number(r.unitPrice).toFixed(2));
                addField('supergross_prix[]', Number(r.supergross || 0).toFixed(2));
                addField('gross_prix[]', Number(r.gross || 0).toFixed(2));
                addField('detail_prix[]', Number(r.detail || 0).toFixed(2));
            });
        }

        /* ===================== PRODUCT COMBOBOX (keyboard-aware, like sales.php) ===================== */
        function bindProductCombobox() {
            const line = draftLineEl();
            const dropdown = document.getElementById('product_combobox_list');
            const wrap = line?.querySelector('.product-combobox-wrap');
            const backdrop = document.getElementById('combobox_backdrop');
            if (!line || !dropdown || !wrap) return;
            const input = line.querySelector('.product-search');
            if (!input) return;
            let suppressClickUntil = 0;

            const closeDropdown = () => {
                dropdown.classList.remove('is-open');
                wrap.classList.remove('is-open');
                if (backdrop) backdrop.classList.remove('is-open');
            };

            const positionAboveKeyboard = () => {
                if (!dropdown.classList.contains('is-open')) return;
                const inputRect = input.getBoundingClientRect();
                const topOffset = inputRect.bottom + 8;
                dropdown.style.top = topOffset + 'px';
                const vv = window.visualViewport;
                if (vv) {
                    const keyboardOverlap = Math.max(0, window.innerHeight - (vv.height + vv.offsetTop));
                    dropdown.style.bottom = (keyboardOverlap + 8) + 'px';
                    const available = window.innerHeight - keyboardOverlap - topOffset - 8;
                    dropdown.style.maxHeight = Math.max(120, available) + 'px';
                } else {
                    dropdown.style.bottom = '8px';
                    const available = window.innerHeight - topOffset - 8;
                    dropdown.style.maxHeight = Math.max(120, available) + 'px';
                }
            };

            const filterList = (q) => {
                const n = (q || '').trim().toLowerCase();
                if (!n) return products.slice(0, 100);
                return products.filter((p) => {
                    const name = String(p.nom || '').toLowerCase();
                    const ref = String(p.reference || '').toLowerCase();
                    return name.includes(n) || ref.includes(n);
                }).slice(0, 100);
            };

            const render = () => {
                const list = filterList(input.value);
                if (list.length === 0) {
                    dropdown.innerHTML = '<li class="combobox-noresult" style="cursor:default;color:var(--gray);pointer-events:none">لا نتائج</li>';
                } else {
                    dropdown.innerHTML = list.map((p) =>
                        `<li role="option" tabindex="0" data-pid="${escapeHtmlP(String(p.id))}">${escapeHtmlP(p.nom)}</li>`
                    ).join('');
                }
                dropdown.classList.add('is-open');
                wrap.classList.add('is-open');
                if (backdrop) backdrop.classList.add('is-open');
                positionAboveKeyboard();
            };

            const pickById = (id) => {
                const p = findProductById(id);
                if (!p) return;
                input.value = p.nom;
                line.querySelector('.product-id').value = String(p.id);
                line.querySelector('.product-units').textContent = p.unité_par_carton;
                const marque = line.querySelector('.product-marque');
                if (marque) marque.textContent = p.marque || '-';
                closeDropdown();
                input.blur();
                openProductModal(id);
            };

            input.addEventListener('focus', () => render());
            input.addEventListener('input', () => render());
            input.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDropdown(); });

            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', positionAboveKeyboard);
                window.visualViewport.addEventListener('scroll', positionAboveKeyboard);
            }

            if (backdrop) {
                backdrop.addEventListener('pointerdown', (e) => {
                    e.preventDefault();
                    closeDropdown();
                    input.blur();
                });
            }

            dropdown.addEventListener('pointerup', (e) => {
                const li = e.target.closest('[data-pid]');
                if (!li) return;
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                suppressClickUntil = Date.now() + 450;
                pickById(li.getAttribute('data-pid'));
            });

            dropdown.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            }, true);

            dropdown.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            }, true);

            document.addEventListener('pointerdown', (e) => {
                if (wrap.contains(e.target)) return;
                closeDropdown();
            }, true);

            document.addEventListener('click', (e) => {
                if (Date.now() <= suppressClickUntil) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                }
            }, true);
        }

        /* ===================== 2-STEP PRODUCT MODAL ===================== */
        // Step 1: quantité (carton) + prix d'achat (auto-filled from product.prix_achat, editable)
        // Step 2: سوبر جملة / جملة / تجزئة (auto-filled from product's current sale prices, editable)
        function openProductModal(productId, prefillRow = null) {
            const p = findProductById(productId);
            if (!p) return;
            modalProduct = p;
            document.getElementById('pm1_title').textContent = p.nom;
            document.getElementById('pm1_stock_info').textContent =
                `المخزون الحالي: ${p.nombre_de_carton || 0} كرتون × ${p.unité_par_carton || 0} وحدة/كرتون`;
            document.getElementById('pm1_cartons').value = prefillRow ? prefillRow.cartons : '';
            document.getElementById('pm1_price').value = prefillRow
                ? Number(prefillRow.unitPrice).toFixed(2)
                : Number(p.prix_achat || 0).toFixed(2);
            document.getElementById('purchase-modal-step1').style.display = 'flex';
        }

        function closeProductModalStep1() {
            document.getElementById('purchase-modal-step1').style.display = 'none';
            modalProduct = null;
            const line = draftLineEl();
            if (line) {
                const search = line.querySelector('.product-search');
                const hidden = line.querySelector('.product-id');
                if (search) search.value = '';
                if (hidden) hidden.value = '';
            }
        }

        function goToProductModalStep2() {
            if (!modalProduct) return;
            const cartonsRaw = document.getElementById('pm1_cartons').value.trim();
            const cartons = cartonsRaw === '' ? 1 : parseInt(cartonsRaw, 10);
            const price = parseFloat(document.getElementById('pm1_price').value || '0');
            if (!Number.isFinite(cartons) || cartons <= 0) {
                alert('أدخل عدد كراتين صحيح.');
                return;
            }
            if (!Number.isFinite(price) || price <= 0) {
                alert('أدخل سعر شراء صحيحاً.');
                return;
            }
            modalProduct.__cartons = cartons;
            modalProduct.__prixUnite = price;

            const p = modalProduct;
            document.getElementById('pm2_title').textContent = p.nom;
            document.getElementById('pm2_supergross').value = Number(p.supergross_prix || 0).toFixed(2);
            document.getElementById('pm2_gross').value = Number(p.gross_prix || 0).toFixed(2);
            document.getElementById('pm2_detail').value = Number(p.detail_prix || 0).toFixed(2);

            document.getElementById('purchase-modal-step1').style.display = 'none';
            document.getElementById('purchase-modal-step2').style.display = 'flex';
        }

        function backToProductModalStep1() {
            document.getElementById('purchase-modal-step2').style.display = 'none';
            document.getElementById('purchase-modal-step1').style.display = 'flex';
        }

        function closeProductModalStep2() {
            document.getElementById('purchase-modal-step2').style.display = 'none';
            modalProduct = null;
            const line = draftLineEl();
            if (line) {
                const search = line.querySelector('.product-search');
                const hidden = line.querySelector('.product-id');
                if (search) search.value = '';
                if (hidden) hidden.value = '';
            }
        }

        function saveProductModalStep2() {
            if (!modalProduct) return;
            const p = modalProduct;
            const supergross = parseFloat(document.getElementById('pm2_supergross').value || '0');
            const gross = parseFloat(document.getElementById('pm2_gross').value || '0');
            const detail = parseFloat(document.getElementById('pm2_detail').value || '0');
            const cartons = p.__cartons;
            const unitPrice = p.__prixUnite;
            const quantite = cartons * Number(p.unité_par_carton || 0);

            const row = {
                productId: p.id,
                nom: p.nom,
                reference: p.reference,
                cartons,
                unitPrice,
                quantite,
                montant: quantite * unitPrice,
                supergross,
                gross,
                detail
            };

            if (editingIndex !== null && editingIndex >= 0 && editingIndex < pendingItems.length) {
                pendingItems[editingIndex] = row;
            } else {
                pendingItems.push(row);
            }
            editingIndex = null;

            renderPendingTable();
            clearDraft();
            document.getElementById('purchase-modal-step2').style.display = 'none';
            modalProduct = null;
        }

        /* ===================== FOURNISSEUR FIELD ===================== */
        function syncFournisseurInput(input) {
            const value = input.value.trim();
            const hidden = document.getElementById('fournisseur_id');
            if (!hidden) return;
            if (!value) {
                hidden.value = '';
                return;
            }
            const normalized = value.toLowerCase();
            const parsedId =
                (normalized.match(/^id\s*[:#]?\s*(\d+)$/i)?.[1]) ||
                (normalized.match(/\|\s*id\s*[:#]?\s*(\d+)\s*$/i)?.[1]) ||
                (normalized.match(/^(\d+)$/)?.[1]) ||
                null;
            const match = fournisseurs.find((f) => {
                const name = String(f.nom || '').toLowerCase();
                const labelA = `${name} | id: ${f.id}`;
                return (parsedId !== null && String(f.id) === String(parsedId)) || name === normalized || labelA === normalized || name.includes(normalized);
            });
            hidden.value = match ? match.id : '';
        }

        /* ===================== CONFIRM MODAL (remise / mon_versement) ===================== */
        function openConfirmModal() {
            if (pendingItems.length === 0) {
                alert('أضف منتجاً واحداً على الأقل قبل التأكيد.');
                return;
            }
            const fid = document.getElementById('fournisseur_id');
            const fSearch = document.getElementById('fournisseur_search');
            if (!fid || !fid.value) {
                alert('اختر مورّداً صالحاً من القائمة.');
                return;
            }
            const fournisseur = findFournisseurById(fid.value);
            document.getElementById('cm_fournisseur_name').textContent = fournisseur ? fournisseur.nom : (fSearch ? fSearch.value : '-');
            document.getElementById('cm_credit_ancien').dataset.value = fournisseur ? Number(fournisseur.mon_credit || 0) : 0;
            document.getElementById('cm_remise').value = '0';
            document.getElementById('cm_versement').value = '0';
            recalcConfirmModal();
            document.getElementById('confirm-modal').style.display = 'flex';
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirm-modal');
            if (modal) modal.style.display = 'none';
        }

        function recalcConfirmModal() {
            const montant = pendingItems.reduce((sum, row) => sum + (Number(row.montant) || 0), 0);
            const creditAncienEl = document.getElementById('cm_credit_ancien');
            const creditAncien = Number(creditAncienEl.dataset.value || 0);
            const remise = Math.max(0, parseFloat(document.getElementById('cm_remise').value || '0'));
            const versement = Math.max(0, parseFloat(document.getElementById('cm_versement').value || '0'));
            const totalActuel = montant + creditAncien - remise;
            const creditActuel = Math.max(totalActuel - versement, 0);
            document.getElementById('cm_montant').textContent = montant.toFixed(2) + ' دج';
            creditAncienEl.textContent = creditAncien.toFixed(2) + ' دج';
            document.getElementById('cm_total_actuel').textContent = totalActuel.toFixed(2) + ' دج';
            document.getElementById('cm_credit_actuel').textContent = creditActuel.toFixed(2) + ' دج';
        }

        function submitConfirmedPurchase() {
            const remise = Math.max(0, parseFloat(document.getElementById('cm_remise').value || '0'));
            const versement = Math.max(0, parseFloat(document.getElementById('cm_versement').value || '0'));
            document.getElementById('purchase_remise_input').value = remise.toFixed(2);
            document.getElementById('purchase_versement_input').value = versement.toFixed(2);
            fillHiddenInputsForSubmit();
            closeConfirmModal();
            const form = document.getElementById('purchase-form');
            if (form) form.requestSubmit();
        }

        /* ===================== CANCEL CURRENT PURCHASE ===================== */
        function cancelCurrentPurchase() {
            const hasItems = pendingItems.length > 0;
            const fSearch = document.getElementById('fournisseur_search');
            const hasFournisseur = fSearch && fSearch.value.trim() !== '';
            if ((hasItems || hasFournisseur) && !confirm('هل تريد إلغاء؟ سيتم حذف كل المنتجات المضافة.')) {
                return;
            }
            pendingItems = [];
            editingIndex = null;
            clearDraft();
            renderPendingTable();
            if (fSearch) fSearch.value = '';
            const fid = document.getElementById('fournisseur_id');
            if (fid) fid.value = '';
            showScreen('menu');
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('purchase-form')) {
                bindProductCombobox();
                clearDraft();
                renderPendingTable();
            }
            initScreens();
        });
    </script>
</head>
<body class="nc-app-body">

    <header>📦 المشتريات</header>

    <div class="container">

        <?php if ($success): ?>
            <div class="alert alert-success">✓ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div id="screen-menu" class="nc-screen">
            <div class="nc-menu-grid">
                <button type="button" class="nc-menu-btn nc-menu-btn--dashboard" onclick="location.href='dashboard.php'">
                    <span class="nc-menu-btn-icon">🏠</span>
                    <span class="nc-menu-btn-label">لوحة التحكم</span>
                </button>
                <button type="button" class="nc-menu-btn nc-menu-btn--create" onclick="showScreen('create')">
                    <span class="nc-menu-btn-icon">➕</span>
                    <span class="nc-menu-btn-label">إنشاء شراء</span>
                </button>
                <button type="button" class="nc-menu-btn nc-menu-btn--list" onclick="showScreen('list')">
                    <span class="nc-menu-btn-icon">📋</span>
                    <span class="nc-menu-btn-label">المشتريات</span>
                </button>
            </div>

            <div class="card">
                <h2>🗓️ مشتريات اليوم</h2>
                <?php if (count($today_purchases) === 0): ?>
                    <p>لا توجد مشتريات اليوم بعد.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>الوقت</th>
                                    <th>المورّد</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_purchases as $tp): ?>
                                    <tr>
                                        <td>#<?= (int)$tp['id'] ?></td>
                                        <td><?= htmlspecialchars($tp['time']) ?></td>
                                        <td><?= htmlspecialchars($tp['fournisseur_nom'] ?? '-') ?></td>
                                        <td><?= number_format((float)$tp['total_montant'], 2) ?> دج</td>
                                        <td><span class="status <?= htmlspecialchars($tp['status']) ?>"><?= htmlspecialchars(ucfirst($tp['status'])) ?></span></td>
                                        <td class="actions">
                                            <a class="btn btn-primary" href="purchases.php?purchase_id=<?= (int)$tp['id'] ?>" style="padding:0.375rem 0.75rem; font-size:0.75rem;">📋 تفاصيل</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="screen-create" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('menu')">→ رجوع للقائمة</button>
            </div>
            <div class="card card--sale-create nc-pos-card">
                <div class="nc-pos-header">
                    <div class="nc-pos-total-box">
                        <span class="nc-pos-total-label">المبلغ الإجمالي</span>
                        <span class="nc-pos-total-value" id="purchase_live_total">0.00</span>
                    </div>
                    <div class="nc-pos-count-box">
                        <span class="nc-pos-count-label">عدد المنتجات</span>
                        <span class="nc-pos-count-value" id="purchase_live_count">0</span>
                    </div>
                </div>

                <form method="POST" id="purchase-form" class="staff-sale-form-inner" autocomplete="off">
                    <input type="hidden" name="action" value="create_purchase">
                    <input type="hidden" name="remise" id="purchase_remise_input" value="0">
                    <input type="hidden" name="mon_versement" id="purchase_versement_input" value="0">
                    <div id="purchase-hidden-inputs"></div>

                    <div class="product-line purchase-draft-line nc-pos-draft-line">
                        <div class="form-group nc-pos-product-group">
                            <label>منتج — ابحث بالاسم أو المرجع</label>
                            <div class="product-combobox-wrap">
                                <input type="text" class="product-search" placeholder="🔍 اكتب اسم المنتج أو المرجع…" autocomplete="off" aria-label="المنتج" autocapitalize="off" spellcheck="false">
                                <div id="combobox_backdrop" class="staff-combobox-backdrop"></div>
                                <ul id="product_combobox_list" class="product-combobox-list" role="listbox"></ul>
                            </div>
                            <input type="hidden" class="product-id" value="">
                            <div class="staff-meta-hint">وحدة/كرتون: <span class="product-units">0</span> | علامة: <span class="product-marque">-</span></div>
                        </div>
                    </div>

                    <div class="staff-pending-wrap">
                        <h3>📋 منتجات هذا الشراء</h3>
                        <p id="purchase_pending_empty" class="staff-pending-empty">لم تُضف منتجات بعد. اختر منتجاً من البحث أعلاه.</p>
                        <div class="table-responsive">
                            <table class="staff-pending-table">
                                <thead>
                                    <tr>
                                        <th>منتج</th>
                                        <th>مرجع</th>
                                        <th>كراتين</th>
                                        <th>كمية</th>
                                        <th>سعر الشراء</th>
                                        <th>المبلغ</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="purchase_pending_tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="nc-pos-bottom-bar">
                        <div class="form-group nc-pos-client-group">
                            <label>المورّد</label>
                            <input type="text" id="fournisseur_search" list="fournisseurOptions" placeholder="🔍 اسم المورّد أو المعرّف" autocomplete="off" required oninput="syncFournisseurInput(this)" aria-label="المورّد">
                            <input type="hidden" id="fournisseur_id" name="fournisseur_id">
                        </div>
                        <button type="button" class="btn btn-danger nc-pos-cancel-btn" onclick="cancelCurrentPurchase()">✖️ إلغاء</button>
                        <button type="button" class="btn btn-success nc-pos-confirm-btn" onclick="openConfirmModal()">✅ تأكيد</button>
                    </div>
                </form>

                <datalist id="fournisseurOptions">
                    <?php foreach ($fournisseurs as $f): ?>
                        <option value="<?= htmlspecialchars($f['nom'] . ' | ID: ' . $f['id']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <!-- Step 1: quantité (carton) + سعر الشراء -->
            <div id="purchase-modal-step1" class="nc-modal-overlay" style="display:none;">
                <div class="nc-modal">
                    <h3 id="pm1_title">-</h3>
                    <p id="pm1_stock_info" class="nc-pm-stock-info"></p>
                    <div class="form-group">
                        <label>الكمية (عدد الكراتين)</label>
                        <input type="number" id="pm1_cartons" class="nc-pm-big-input" min="1" placeholder="1" inputmode="numeric">
                    </div>
                    <div class="form-group">
                        <label>سعر الشراء (دج)</label>
                        <input type="number" step="0.01" id="pm1_price" class="nc-pm-big-input" inputmode="decimal">
                    </div>
                    <div class="flex-buttons" style="margin-top:1rem;">
                        <button type="button" class="btn btn-success" onclick="goToProductModalStep2()">التالي ➡️</button>
                        <button type="button" class="btn btn-secondary" onclick="closeProductModalStep1()">↩️ الرجوع للخلف</button>
                    </div>
                </div>
            </div>

            <!-- Step 2: أسعار البيع (سوبر جملة / جملة / تجزئة) -->
            <div id="purchase-modal-step2" class="nc-modal-overlay" style="display:none;">
                <div class="nc-modal">
                    <h3 id="pm2_title">-</h3>
                    <p class="nc-pm-stock-info">حدّث أسعار بيع هذا المنتج (تُطبَّق على كل مبيعاته القادمة):</p>
                    <div class="form-group">
                        <label>سوبر جملة (دج)</label>
                        <input type="number" step="0.01" id="pm2_supergross" class="nc-pm-big-input" inputmode="decimal">
                    </div>
                    <div class="form-group">
                        <label>جملة (دج)</label>
                        <input type="number" step="0.01" id="pm2_gross" class="nc-pm-big-input" inputmode="decimal">
                    </div>
                    <div class="form-group">
                        <label>تجزئة (دج)</label>
                        <input type="number" step="0.01" id="pm2_detail" class="nc-pm-big-input" inputmode="decimal">
                    </div>
                    <div class="flex-buttons" style="margin-top:1rem;">
                        <button type="button" class="btn btn-success" onclick="saveProductModalStep2()">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="backToProductModalStep1()">⬅️ رجوع</button>
                        <button type="button" class="btn btn-danger" onclick="closeProductModalStep2()">❌ إلغاء</button>
                    </div>
                </div>
            </div>

            <!-- Confirm modal: remise + mon_versement -->
            <div id="confirm-modal" class="nc-modal-overlay" style="display:none;">
                <div class="nc-modal">
                    <h3>✅ تأكيد الشراء</h3>
                    <div class="nc-modal-row"><span>المورّد</span><strong id="cm_fournisseur_name">—</strong></div>
                    <div class="nc-modal-row"><span>المبلغ (هذا الشراء)</span><strong id="cm_montant">0.00 دج</strong></div>
                    <div class="nc-modal-row"><span>الدين السابق للمورّد</span><strong id="cm_credit_ancien">0.00 دج</strong></div>
                    <div class="form-group">
                        <label>الخصم (remise)</label>
                        <input type="number" step="0.01" min="0" id="cm_remise" value="0" inputmode="decimal" oninput="recalcConfirmModal()">
                    </div>
                    <div class="form-group">
                        <label>الدفعة (mon_versement)</label>
                        <input type="number" step="0.01" min="0" id="cm_versement" value="0" inputmode="decimal" oninput="recalcConfirmModal()">
                    </div>
                    <div class="nc-modal-row nc-modal-row--strong"><span>الإجمالي الحالي</span><strong id="cm_total_actuel">0.00 دج</strong></div>
                    <div class="nc-modal-row nc-modal-row--strong"><span>الدين الحالي</span><strong id="cm_credit_actuel">0.00 دج</strong></div>
                    <div class="flex-buttons" style="margin-top:1rem;">
                        <button type="button" class="btn btn-success" onclick="submitConfirmedPurchase()">💾 حفظ الشراء</button>
                        <button type="button" class="btn btn-danger" onclick="closeConfirmModal()">❌ إلغاء</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="screen-list" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('menu')">→ رجوع للقائمة</button>
            </div>
            <div class="card">
                <h2>📄 كل المشتريات</h2>
                <?php if (count($purchases_list) === 0): ?>
                    <p>لا توجد مشتريات مسجّلة بعد.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>الوقت</th>
                                    <th>المورّد</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchases_list as $pu): ?>
                                    <tr>
                                        <td>#<?= (int)$pu['id'] ?></td>
                                        <td><?= htmlspecialchars($pu['time']) ?></td>
                                        <td><?= htmlspecialchars($pu['fournisseur_nom'] ?? '-') ?></td>
                                        <td><?= number_format((float)$pu['total_montant'], 2) ?> دج</td>
                                        <td><span class="status <?= htmlspecialchars($pu['status']) ?>"><?= htmlspecialchars(ucfirst($pu['status'])) ?></span></td>
                                        <td class="actions">
                                            <a class="btn btn-primary" href="purchases.php?purchase_id=<?= (int)$pu['id'] ?>" style="padding:0.375rem 0.75rem; font-size:0.75rem;">📋 تفاصيل</a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('هل تريد حذف هذا الشراء؟ سيُخصم المخزون المُضاف عبره.');">
                                                <input type="hidden" name="action" value="delete_purchase">
                                                <input type="hidden" name="purchase_id" value="<?= (int)$pu['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="padding:0.375rem 0.75rem; font-size:0.75rem;">🗑️ حذف</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($purchase_detail): ?>
        <div id="screen-detail" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('list')">→ رجوع لقائمة المشتريات</button>
            </div>
            <div class="card">
                <h2>🧾 تفاصيل الشراء #<?= (int)$purchase_detail['id'] ?></h2>
                <p><strong>المورّد:</strong> <?= htmlspecialchars($purchase_detail['fournisseur_nom'] ?? '-') ?></p>
                <p><strong>التاريخ:</strong> <?= htmlspecialchars($purchase_detail['time']) ?></p>
                <p><strong>الموظف:</strong> <?= htmlspecialchars($purchase_detail['admin_nom'] ?? '-') ?></p>
                <p><strong>الحالة:</strong> <?= htmlspecialchars(ucfirst($purchase_detail['status'])) ?></p>
                <p><strong>المبلغ</strong> <?= number_format((float)$purchase_detail['total_montant'], 2) ?> دج</p>
                <p><strong>الدين السابق</strong> <?= number_format((float)$purchase_detail['mon_credit'], 2) ?> دج</p>
                <p><strong>الخصم</strong> <?= number_format((float)$purchase_detail['remise'], 2) ?> دج</p>
                <p><strong>الإجمالي الحالي</strong> <?= number_format((float)$purchase_detail['total_actuel'], 2) ?> دج</p>
                <p><strong>الدفعة</strong> <?= number_format((float)$purchase_detail['mon_versement'], 2) ?> دج</p>
                <p><strong>الدين الحالي</strong> <?= number_format((float)$purchase_detail['credit_actuel'], 2) ?> دج</p>

                <div class="table-responsive" style="margin-top:1rem;">
                    <table>
                        <thead>
                            <tr>
                                <th>منتج</th>
                                <th>مرجع</th>
                                <th>كراتين</th>
                                <th>وحدة/كرتون</th>
                                <th>كمية</th>
                                <th>سعر وحدة</th>
                                <th>مبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchase_lines as $pl): ?>
                                <tr>
                                    <td><?= htmlspecialchars($pl['product_nom'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($pl['product_reference']) ?></td>
                                    <td><?= (int)$pl['nombre_de_carton'] ?></td>
                                    <td><?= (int)$pl['nombre_unite_par_carton'] ?></td>
                                    <td><?= (int)$pl['quantité'] ?></td>
                                    <td><?= number_format((float)$pl['prix_unite'], 2) ?> دج</td>
                                    <td><?= number_format((float)$pl['montant'], 2) ?> دج</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex-buttons" style="margin-top:1rem;">
                    <button type="button" class="btn btn-primary" onclick="imprimerFactureAchat()">🖨️ طباعة</button>
                    <?php if ($purchase_detail['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="validate_purchase">
                            <input type="hidden" name="purchase_id" value="<?= (int)$purchase_detail['id'] ?>">
                            <button type="submit" class="btn btn-success">✅ اعتماد</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('هل تريد حذف هذا الشراء؟ سيُخصم المخزون المُضاف عبره.');">
                        <input type="hidden" name="action" value="delete_purchase">
                        <input type="hidden" name="purchase_id" value="<?= (int)$purchase_detail['id'] ?>">
                        <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>

<style>
    @media print {
        .no-print, .nc-screen-nav, .nc-pos-bottom-bar, form:not(.print-form), .actions {
            display: none !important;
        }
        body {
            background: white !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        .container {
            padding: 0 !important;
            margin: 0 !important;
            max-width: 100% !important;
        }
    }
</style>

<script>
/**
 * Impression thermique (RawBT / Bluetooth) en mode image — même mécanisme que sales.php.
 */
function isAndroidDevice() {
    return /android/i.test(navigator.userAgent || '');
}

const PAPER_WIDTH_PX = 576; // 80mm @ 203dpi

function dessinerTicketAchatCanvas(data) {
    const W = PAPER_WIDTH_PX;
    const PAD = 16;
    const contentW = W - PAD * 2;

    const measureCanvas = document.createElement('canvas');
    const mctx = measureCanvas.getContext('2d');

    const FONT_NORMAL = '26px Arial, sans-serif';
    const FONT_BOLD = 'bold 28px Arial, sans-serif';
    const FONT_SMALL = '22px Arial, sans-serif';
    const FONT_TITLE = 'bold 30px Arial, sans-serif';
    const LINE_H = 36;
    const LINE_H_SMALL = 28;

    function wrapText(ctx, text, maxWidth) {
        const words = String(text ?? '').split(' ');
        const lines = [];
        let current = '';
        words.forEach((word) => {
            const test = current ? current + ' ' + word : word;
            if (ctx.measureText(test).width > maxWidth && current) {
                lines.push(current);
                current = word;
            } else {
                current = test;
            }
        });
        if (current) lines.push(current);
        return lines.length ? lines : [''];
    }

    function truncateText(ctx, text, maxWidth) {
        let str = String(text ?? '');
        if (ctx.measureText(str).width <= maxWidth) return str;
        while (str.length > 1 && ctx.measureText(str + '…').width > maxWidth) {
            str = str.slice(0, -1);
        }
        return str + '…';
    }

    const ops = [];
    function addLine(text, font, height, align = 'right') { ops.push({ type: 'text', text, font, height, align }); }
    function addSeparator(height = 14, thick = false) { ops.push({ type: 'sep', height, thick }); }
    function addSpace(height) { ops.push({ type: 'space', height }); }
    function addRow(leftLabel, rightValue, font, height) { ops.push({ type: 'row', leftLabel, rightValue, font, height }); }

    mctx.font = FONT_TITLE;
    wrapText(mctx, data.titre1, contentW).forEach((l) => addLine(l, FONT_TITLE, LINE_H + 4, 'center'));
    mctx.font = FONT_SMALL;
    wrapText(mctx, data.titre2, contentW).forEach((l) => addLine(l, FONT_SMALL, LINE_H_SMALL, 'center'));
    addSeparator(16, true);

    addLine(`وصل شراء #${data.purchaseId || '-'}`, FONT_BOLD, LINE_H + 2, 'center');
    addLine(`${data.time || '-'}`, FONT_NORMAL, LINE_H, 'center');
    mctx.font = FONT_BOLD;
    wrapText(mctx, `المورّد: ${data.fournisseurName || '-'}`, contentW).forEach((l) => addLine(l, FONT_BOLD, LINE_H, 'center'));
    addSeparator(16, true);

    const FONT_TABLE_HEAD = 'bold 20px Arial, sans-serif';
    const FONT_TABLE_ROW = '20px Arial, sans-serif';
    const LINE_H_TABLE = 30;

    const COL_PRODUIT_W = Math.round(contentW * 0.40);
    const COL_CARTONS_W = Math.round(contentW * 0.13);
    const COL_UNITE_W = Math.round(contentW * 0.15);
    const COL_PRIX_W = Math.round(contentW * 0.16);
    const COL_TOTAL_W = contentW - COL_PRODUIT_W - COL_CARTONS_W - COL_UNITE_W - COL_PRIX_W;

    const tableCols = [
        { key: 'nom', label: 'المنتج', width: COL_PRODUIT_W, align: 'right' },
        { key: 'cartons', label: 'كراتين', width: COL_CARTONS_W, align: 'center' },
        { key: 'unite', label: 'وحدة/كرتون', width: COL_UNITE_W, align: 'center' },
        { key: 'prixUnite', label: 'سعر', width: COL_PRIX_W, align: 'center' },
        { key: 'montant', label: 'المجموع', width: COL_TOTAL_W, align: 'left' }
    ];

    addLine('المنتجات', FONT_BOLD, LINE_H, 'right');
    addSeparator();
    ops.push({ type: 'table-header', cols: tableCols, font: FONT_TABLE_HEAD, height: LINE_H_TABLE });
    addSeparator();
    data.items.forEach((item) => {
        ops.push({ type: 'table-row', cols: tableCols, values: item, font: FONT_TABLE_ROW, height: LINE_H_TABLE });
    });
    addSeparator(16, true);

    addRow('عدد المنتجات', data.productCount, FONT_NORMAL, LINE_H);
    addRow('المبلغ', data.total, FONT_BOLD, LINE_H + 2);
    addRow('الدين السابق', data.creditAncien, FONT_NORMAL, LINE_H);
    addRow('الخصم', data.remise, FONT_NORMAL, LINE_H);
    addRow('الإجمالي الحالي', data.totalActuel, FONT_NORMAL, LINE_H);
    addRow('الدفعة', data.versement, FONT_NORMAL, LINE_H);
    addSeparator(16, true);
    addRow('الدين الحالي', data.creditActuel, FONT_BOLD, LINE_H + 4);
    addSeparator(16, true);
    addSpace(40);

    let totalHeight = 20;
    ops.forEach((op) => { totalHeight += op.height; });

    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = totalHeight;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, totalHeight);
    ctx.fillStyle = '#000000';
    ctx.direction = 'rtl';

    let y = 20;
    ops.forEach((op) => {
        if (op.type === 'text') {
            ctx.font = op.font;
            ctx.textAlign = op.align === 'center' ? 'center' : 'right';
            const x = op.align === 'center' ? W / 2 : (W - PAD);
            ctx.fillText(op.text, x, y + op.height * 0.7);
        } else if (op.type === 'sep') {
            ctx.save();
            ctx.lineWidth = op.thick ? 2 : 1;
            ctx.beginPath();
            ctx.moveTo(PAD, y + op.height / 2);
            ctx.lineTo(W - PAD, y + op.height / 2);
            ctx.stroke();
            ctx.restore();
        } else if (op.type === 'row') {
            ctx.font = op.font;
            ctx.textAlign = 'right';
            ctx.fillText(op.leftLabel, W - PAD, y + op.height * 0.7);
            ctx.textAlign = 'left';
            ctx.fillText(String(op.rightValue), PAD, y + op.height * 0.7);
        } else if (op.type === 'table-header' || op.type === 'table-row') {
            ctx.font = op.font;
            let xCursor = W - PAD;
            op.cols.forEach((col) => {
                const colRight = xCursor;
                const colLeft = xCursor - col.width;
                const rawText = op.type === 'table-header' ? col.label : String(op.values[col.key] ?? '-');
                const text = truncateText(ctx, rawText, col.width - 10);
                if (col.align === 'right') {
                    ctx.textAlign = 'right';
                    ctx.fillText(text, colRight - 4, y + op.height * 0.68);
                } else if (col.align === 'left') {
                    ctx.textAlign = 'left';
                    ctx.fillText(text, colLeft + 4, y + op.height * 0.68);
                } else {
                    ctx.textAlign = 'center';
                    ctx.fillText(text, colLeft + col.width / 2, y + op.height * 0.68);
                }
                xCursor = colLeft;
            });
        }
        y += op.height;
    });

    return canvas;
}

function canvasToEscPosBase64(canvas) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    const imgData = ctx.getImageData(0, 0, w, h).data;

    const widthBytes = Math.ceil(w / 8);
    const bitmap = new Uint8Array(widthBytes * h);

    for (let yy = 0; yy < h; yy++) {
        for (let xx = 0; xx < w; xx++) {
            const idx = (yy * w + xx) * 4;
            const r = imgData[idx], g = imgData[idx + 1], b = imgData[idx + 2], a = imgData[idx + 3];
            const luminance = (r * 0.299 + g * 0.587 + b * 0.114);
            const isBlack = a > 50 && luminance < 180;
            if (isBlack) {
                const byteIndex = yy * widthBytes + (xx >> 3);
                const bitIndex = 7 - (xx % 8);
                bitmap[byteIndex] |= (1 << bitIndex);
            }
        }
    }

    const xL = widthBytes & 0xFF;
    const xH = (widthBytes >> 8) & 0xFF;
    const yL = h & 0xFF;
    const yH = (h >> 8) & 0xFF;

    const header = new Uint8Array([0x1D, 0x76, 0x30, 0x00, xL, xH, yL, yH]);
    const full = new Uint8Array(header.length + bitmap.length);
    full.set(header, 0);
    full.set(bitmap, header.length);

    let binary = '';
    for (let i = 0; i < full.length; i++) binary += String.fromCharCode(full[i]);
    return btoa(binary);
}

function sendEscPosToRawBT(base64Data) {
    const intentUrl = 'intent:base64,' + base64Data + '#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
    window.location.href = intentUrl;
}

function imprimerFactureAchat() {
    function escapeHtml(s) {
        const t = document.createElement('textarea');
        t.textContent = s;
        return t.innerHTML;
    }

    const detailCard = document.querySelector('#screen-detail .card');
    if (!detailCard) return;

    const getValueByLabel = (label) => {
        const pList = detailCard.querySelectorAll('p');
        for (const p of pList) {
            const strong = p.querySelector('strong');
            if (!strong) continue;
            const key = (strong.textContent || '').replace(':', '').trim();
            if (key === label) {
                return p.textContent.replace(strong.textContent, '').trim();
            }
        }
        return '-';
    };

    const purchaseTitle = detailCard.querySelector('h2')?.textContent || 'شراء';
    const idMatch = purchaseTitle.match(/#(\d+)/);
    const purchaseId = idMatch ? Number(idMatch[1]) : 0;
    const fournisseurName = getValueByLabel('المورّد');
    const time = getValueByLabel('التاريخ');
    const total = getValueByLabel('المبلغ');
    const creditAncien = getValueByLabel('الدين السابق');
    const remise = getValueByLabel('الخصم');
    const totalActuel = getValueByLabel('الإجمالي الحالي');
    const versement = getValueByLabel('الدفعة');
    const creditActuel = getValueByLabel('الدين الحالي');

    const table = detailCard.querySelector('table');
    if (!table) return;
    const originalHeaders = Array.from(table.querySelectorAll('thead th')).map((th) => (th.textContent || '').trim());
    const headerMap = { 'منتج': 'المنتج', 'مرجع': 'مرجع', 'كراتين': 'كراتين', 'وحدة/كرتون': 'وحدة/كرتون', 'كمية': 'كمية', 'سعر وحدة': 'سعر الوحدة', 'مبلغ': 'المبلغ' };
    const keepIndexes = [];
    const finalHeaders = [];
    originalHeaders.forEach((h, i) => {
        if (headerMap[h]) {
            keepIndexes.push(i);
            finalHeaders.push(headerMap[h]);
        }
    });
    const bodyRows = Array.from(table.querySelectorAll('tbody tr')).map((tr) => {
        const cells = Array.from(tr.querySelectorAll('td'));
        if (!cells.length) return '';
        const filtered = keepIndexes.map((idx) => `<td>${cells[idx] ? cells[idx].innerHTML : ''}</td>`).join('');
        return `<tr>${filtered}</tr>`;
    }).join('');
    const printTableHtml = `<thead><tr>${finalHeaders.map((h) => `<th>${h}</th>`).join('')}</tr></thead><tbody>${bodyRows}</tbody>`;
    const productCount = Array.from(table.querySelectorAll('tbody tr')).filter((tr) => tr.querySelectorAll('td').length > 0).length;

    if (isAndroidDevice()) {
        const dataRows = Array.from(table.querySelectorAll('tbody tr')).map((tr) => {
            const cells = Array.from(tr.querySelectorAll('td'));
            if (!cells.length) return null;
            const row = {};
            keepIndexes.forEach((idx, i) => { row[finalHeaders[i]] = (cells[idx]?.textContent || '').trim(); });
            return row;
        }).filter(Boolean);

        function cleanPrice(val) {
            return String(val || '0').replace(/دج/g, '').replace(/,/g, '').trim();
        }

        const items = dataRows.map((row) => ({
            nom: row['المنتج'] || '-',
            cartons: row['كراتين'] || '-',
            unite: row['وحدة/كرتون'] || '-',
            prixUnite: cleanPrice(row['سعر الوحدة']),
            montant: cleanPrice(row['المبلغ'])
        }));

        const ticketData = {
            titre1: 'تجارة بالجملة لمواد التجميل و التنظيف',
            titre2: 'وصل شراء بضاعة من المورّد',
            purchaseId,
            time,
            fournisseurName,
            items,
            productCount,
            total: cleanPrice(total) || '0',
            creditAncien: cleanPrice(creditAncien) || '0',
            remise: cleanPrice(remise) || '0',
            totalActuel: cleanPrice(totalActuel) || '0',
            versement: cleanPrice(versement) || '0',
            creditActuel: cleanPrice(creditActuel) || '0'
        };

        const canvas = dessinerTicketAchatCanvas(ticketData);
        const base64Data = canvasToEscPosBase64(canvas);
        sendEscPosToRawBT(base64Data);
        return;
    }

    const w = window.open('', '_blank');
    w.document.write(`
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>وصل شراء رقم #${purchaseId || 0}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 10px; color: #111; direction: rtl; }
        .top-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .top-table td { border: 1px solid #333; vertical-align: top; padding: 4px 8px; font-size: 11px; line-height: 1.4; width: 33.33%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .items th, .items td { border: 1px solid #bbb; padding: 3px 6px; font-size: 10px; text-align: right; line-height: 1.2; }
        .items th { background: #f1f5f9; height: 20px; }
        .items tr { height: 18px; }
        .footer-container { display: flex; justify-content: space-between; align-items: stretch; margin-top: 10px; padding: 10px; border-radius: 4px; background-color: #fcfdfe; }
        .summary-left { width:30%; display: flex; flex-direction: column; justify-content: center; border: 2px solid #007bff; padding: 10px; border-radius: 4px; transform: rotate(-4deg); }
        .summary-left strong { color: blue; font-size: 13px; margin-bottom: 4px; }
        .summary { width: 50%; }
        .summary-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 2px 0; font-size: 11px; }
        .summary-row:last-child { border-bottom: none; }
        .summary-row strong { white-space: nowrap; margin-left: 8px; }
        @media print { body { margin: 0; } .footer-container { -webkit-print-color-adjust: exact; } }
    </style>
</head>
<body>
    <table class="top-table">
        <tr>
            <td>وصل شراء بضاعة<br>الرقم التسلسلي: #${purchaseId || '-'}</td>
            <td>الوقت: ${escapeHtml(String(time || '-'))}</td>
            <td>المورّد: ${escapeHtml(String(fournisseurName || '-'))}</td>
        </tr>
    </table>
    <table class="items">${printTableHtml}</table>
    <div class="footer-container">
        <div class="summary-left">
            <strong>تجارة بالجملة لمواد التجميل و التنظيف</strong>
            <strong>وصل شراء بضاعة من المورّد</strong>
        </div>
        <div class="summary">
            <div class="summary-row"><strong>عدد المنتجات</strong><span>${productCount}</span></div>
            <div class="summary-row"><strong>المبلغ</strong><span>${escapeHtml(String(total || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الدين السابق</strong><span>${escapeHtml(String(creditAncien || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الخصم</strong><span>${escapeHtml(String(remise || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الإجمالي الحالي</strong><span>${escapeHtml(String(totalActuel || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الدفعة</strong><span>${escapeHtml(String(versement || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الدين الحالي</strong><span>${escapeHtml(String(creditActuel || '0.00 دج'))}</span></div>
        </div>
    </div>
    <script>
        window.onload = function() {
            window.print();
            setTimeout(function() { window.close(); }, 500);
        };
    <\/script>
</body>
</html>
`);
    w.document.close();
}
</script>
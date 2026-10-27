<?php
// products.php - Shared Products Page (list / add / stats / print views)
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    // Public view (read only) when accessed without login
    $is_logged_in = false;
    $is_client = false;
    $user_type = '';
} else {
    $is_logged_in = true;
    $is_client = $_SESSION['is_client'] ?? false;
    $user_type = $_SESSION['user_type'];
}

if ($is_logged_in && !$is_client && $user_type !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

$is_admin_view = $is_logged_in && !$is_client;
$view = $_GET['view'] ?? 'list';
if (!in_array($view, ['list', 'add', 'stats', 'print'], true)) {
    $view = 'list';
}
// Only admins get the add/stats/print views — anyone else falls back to the list/catalog.
if (!$is_admin_view && $view !== 'list') {
    $view = 'list';
}

$brands = [];
$brandResult = $conn->query("SELECT DISTINCT marque FROM product WHERE marque <> '' ORDER BY marque");
while ($row = $brandResult->fetch_assoc()) {
    $brands[] = $row['marque'];
}

$fournisseurs = [];
$fournisseurResult = $conn->query("SELECT nom FROM fournisseur ORDER BY nom");
while ($row = $fournisseurResult->fetch_assoc()) {
    $fournisseurs[] = $row['nom'];
}

// Handle AJAX requests for JSON data
if (isset($_GET['action']) && $_GET['action'] === 'get_products') {
    header('Content-Type: application/json');
    $result = $conn->query("SELECT id, reference, nom, description, image, marque, fournisseur, low_stock, quantité, unité_par_carton, prix_achat, supergross_prix, gross_prix, detail_prix, display_mode, nombre_de_vente, montant_de_vente, benefit, (prix_achat * nombre_de_vente) AS cout_achat_calcule FROM product ORDER BY reference ASC");
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    echo json_encode($products);
    exit();
}

// Handle Add / Edit / Delete (only for Admin)
$error = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged_in && $user_type === 'admin') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        // Récupérer et sauvegarder les données du formulaire
        $reference = trim($_POST['reference']);
        $nom = trim($_POST['nom']);
        $description = trim($_POST['description'] ?? '');
        $marque = trim($_POST['marque'] ?? '');
        $fournisseur = trim($_POST['fournisseur'] ?? '');
        $low_stock = (int)($_POST['low_stock'] ?? 0);
        $prix_achat = (float)($_POST['prix_achat'] ?? 0);
        $nombre_de_carton = (int)$_POST['nombre_de_carton'];
        $unité_par_carton = (int)$_POST['unité_par_carton'];
        $supergross = (float)$_POST['supergross_prix'];
        $gross = (float)$_POST['gross_prix'];
        $detail = (float)$_POST['detail_prix'];
        $display_mode = in_array($_POST['display_mode'] ?? 'normal', ['normal', 'red', 'blue'], true) ? $_POST['display_mode'] : 'normal';

        // Sauvegarder les données pour les réafficher en cas d'erreur
        $form_data = [
            'reference' => $reference,
            'nom' => $nom,
            'description' => $description,
            'marque' => $marque,
            'fournisseur' => $fournisseur,
            'low_stock' => $low_stock,
            'prix_achat' => $prix_achat,
            'nombre_de_carton' => $nombre_de_carton,
            'unité_par_carton' => $unité_par_carton,
            'supergross_prix' => $supergross,
            'gross_prix' => $gross,
            'detail_prix' => $detail,
            'display_mode' => $display_mode
        ];

        // Vérification si la référence existe déjà (SEULEMENT pour l'ajout)
        if ($action === 'add') {
            $stmtCheck = $conn->prepare("SELECT id FROM product WHERE reference = ? LIMIT 1");
            $stmtCheck->bind_param('s', $reference);
            $stmtCheck->execute();
            $resultCheck = $stmtCheck->get_result();

            if ($resultCheck->num_rows > 0) {
                $error = "⚠️ خطأ: المرجع '{$reference}' موجود بالفعل في النظام! الرجاء استخدام مرجع مختلف.";
            }
            $stmtCheck->close();
        }

        // Si pas d'erreur, procéder à l'insertion/mise à jour
        if ($error === '') {
            $imageFilename = trim($_POST['existing_image'] ?? '');
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/products';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                if (in_array($extension, $allowedExtensions, true)) {
                    $imageFilename = uniqid('product_', true) . '.' . $extension;
                    move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . '/' . $imageFilename);
                }
            }

            if ($fournisseur !== '') {
                $stmtF = $conn->prepare("SELECT id FROM fournisseur WHERE LOWER(nom) = LOWER(?) LIMIT 1");
                $stmtF->bind_param('s', $fournisseur);
                $stmtF->execute();
                $resultF = $stmtF->get_result();
                if ($resultF->num_rows === 0) {
                    $stmtInsertF = $conn->prepare("INSERT INTO fournisseur (nom, telephone, mon_credit, mon_versement_total) VALUES (?, NULL, 0.00, 0.00)");
                    $stmtInsertF->bind_param('s', $fournisseur);
                    $stmtInsertF->execute();
                    $stmtInsertF->close();
                }
                $stmtF->close();
            }

            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO product (reference, nom, description, image, marque, fournisseur, low_stock, nombre_de_carton, unité_par_carton, prix_achat, supergross_prix, gross_prix, detail_prix, display_mode)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssiiidddds", $reference, $nom, $description, $imageFilename, $marque, $fournisseur, $low_stock, $nombre_de_carton, $unité_par_carton, $prix_achat, $supergross, $gross, $detail, $display_mode);
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("UPDATE product SET reference = ?, nom = ?, description = ?, image = ?, marque = ?, fournisseur = ?, low_stock = ?, nombre_de_carton = ?, unité_par_carton = ?, prix_achat = ?, supergross_prix = ?, gross_prix = ?, detail_prix = ?, display_mode = ? WHERE id = ?");
                $stmt->bind_param("ssssssiiiddddsi", $reference, $nom, $description, $imageFilename, $marque, $fournisseur, $low_stock, $nombre_de_carton, $unité_par_carton, $prix_achat, $supergross, $gross, $detail, $display_mode, $id);
            }
            if ($stmt->execute()) {
                $message = $action === 'add' ? 'تمت إضافة المنتج بنجاح!' : 'تم تحديث المنتج بنجاح!';
                $redirectView = ($action === 'add' && !empty($_POST['add_another'])) ? 'add' : 'list';
                echo "<script>alert('{$message}'); window.location.href='products.php?view={$redirectView}';</script>";
                exit();
            }
            $stmt->close();
        }
    }

    if ($action === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM product WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            echo "<script>alert('تم حذف المنتج!'); window.location.href='products.php?view=list';</script>";
            exit();
        }
    }

    if ($action === 'set_display_mode' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $display_mode = in_array($_POST['display_mode'] ?? 'normal', ['normal', 'red', 'blue'], true) ? $_POST['display_mode'] : 'normal';
        header('Content-Type: application/json');
        $stmt = $conn->prepare("UPDATE product SET display_mode = ? WHERE id = ?");
        $stmt->bind_param('si', $display_mode, $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'تعذّر تحديث وضع العرض.']);
        }
        exit();
    }
}

/** Marge sur les ventes confirmées dans la période : Σ (montant ligne − prix_achat × quantité) — reused from stats.php */
function getPeriodBenefit(mysqli $conn, string $start, string $end): float {
    $sql = "SELECT COALESCE(SUM(si.montant - (p.prix_achat * si.quantité)), 0) AS b
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            INNER JOIN product p ON p.id = si.product_id
            WHERE s.status IN ('validé', 'pret') AND s.time >= ? AND s.time <= ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0.0;
    }
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['b'] : 0.0;
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', ' ') . ' دج';
}

// --- Data needed by the "stats" view (product-related figures from stats.php) ---
$lowStockProducts = [];
$topSellingProduct = null;
$topBenefitProduct = null;
$benefitMonth = 0.0;
$benefitYear = 0.0;

if ($view === 'stats') {
    $now = new DateTimeImmutable('now');
    $monthStart = $now->format('Y-m-01 00:00:00');
    $monthEnd = $now->format('Y-m-t 23:59:59');
    $yearStart = $now->format('Y-01-01 00:00:00');
    $yearEnd = $now->format('Y-12-31 23:59:59');

    $lowStockResult = $conn->query("SELECT * FROM product WHERE nombre_de_carton <= low_stock ORDER BY nombre_de_carton ASC, id ASC");
    $lowStockProducts = $lowStockResult ? $lowStockResult->fetch_all(MYSQLI_ASSOC) : [];

    $topSellingResult = $conn->query("SELECT * FROM product ORDER BY nombre_de_vente DESC, id ASC LIMIT 1");
    $topSellingProduct = $topSellingResult ? $topSellingResult->fetch_assoc() : null;

    $topBenefitResult = $conn->query("SELECT * FROM product ORDER BY montant_de_vente DESC, id ASC LIMIT 1");
    $topBenefitProduct = $topBenefitResult ? $topBenefitResult->fetch_assoc() : null;

    $benefitMonth = getPeriodBenefit($conn, $monthStart, $monthEnd);
    $benefitYear = getPeriodBenefit($conn, $yearStart, $yearEnd);
}

// --- Data needed by the "print" view (custom catalog facture) ---
$printProducts = [];
$printPriceType = $_GET['price_type'] ?? 'detail_prix';
if (!in_array($printPriceType, ['supergross_prix', 'gross_prix', 'detail_prix'], true)) {
    $printPriceType = 'detail_prix';
}
$printMode = $_GET['print_mode'] ?? '';

if ($view === 'print' && $printMode !== '') {
    if ($printMode === 'all') {
        $r = $conn->query("SELECT id, reference, nom, marque, supergross_prix, gross_prix, detail_prix FROM product ORDER BY reference ASC");
        $printProducts = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    } elseif ($printMode === 'refs') {
        $rawRefs = trim($_GET['refs'] ?? '');
        $refList = array_filter(array_map('trim', explode(',', $rawRefs)), fn($v) => $v !== '');
        if (count($refList) > 0) {
            $placeholders = implode(',', array_fill(0, count($refList), '?'));
            $types = str_repeat('s', count($refList));
            $stmt = $conn->prepare("SELECT id, reference, nom, marque, supergross_prix, gross_prix, detail_prix FROM product WHERE reference IN ($placeholders) ORDER BY reference ASC");
            $stmt->bind_param($types, ...$refList);
            $stmt->execute();
            $printProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } elseif ($printMode === 'range') {
        $rangeFrom = trim($_GET['range_from'] ?? '');
        $rangeTo = trim($_GET['range_to'] ?? '');
        if ($rangeFrom !== '' && $rangeTo !== '' && is_numeric($rangeFrom) && is_numeric($rangeTo)) {
            $lo = min((float)$rangeFrom, (float)$rangeTo);
            $hi = max((float)$rangeFrom, (float)$rangeTo);
            // Compares the numeric portion of the reference (works whether references are plain numbers or prefixed codes).
            $stmt = $conn->prepare("SELECT id, reference, nom, marque, supergross_prix, gross_prix, detail_prix FROM product WHERE CAST(REGEXP_REPLACE(reference, '[^0-9]', '') AS UNSIGNED) BETWEEN ? AND ? ORDER BY reference ASC");
            $stmt->bind_param('dd', $lo, $hi);
            $stmt->execute();
            $printProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } elseif ($printMode === 'names') {
        $ids = $_GET['product_ids'] ?? [];
        $ids = array_filter(array_map('intval', is_array($ids) ? $ids : []), fn($v) => $v > 0);
        if (count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $stmt = $conn->prepare("SELECT id, reference, nom, marque, supergross_prix, gross_prix, detail_prix FROM product WHERE id IN ($placeholders) ORDER BY reference ASC");
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $printProducts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>المنتجات — NettoClean</title>
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
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
            --radius-lg: 0.75rem;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body.nc-app-body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            padding: 2rem 1rem;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header { margin-bottom: 2rem; text-align: center; }
        .header h1 { font-size: 2rem; color: white; margin-bottom: 0.5rem; font-weight: 700; text-shadow: 2px 2px 4px rgba(0,0,0,0.1); }
        .header p { color: rgba(255,255,255,0.9); font-size: 0.95rem; }

        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover { box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1); }
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .card-header h2 { font-size: 1.25rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
        .card-header h2::before { content: ''; width: 4px; height: 20px; background: #3b82f6; border-radius: 2px; }
        .card-body { padding: 1.5rem; }

        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--dark); font-size: 0.875rem; }
        .form-group label.required::after { content: '*'; color: var(--danger); margin-left: 0.25rem; }
        .form-group small.hint { display: block; margin-top: 0.35rem; color: var(--gray); font-size: 0.75rem; }

        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem 1.25rem; }
        .form-grid .form-group { margin-bottom: 0; }
        .form-section { margin-bottom: 1.75rem; }
        .form-section-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 0.9rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            transition: all 0.2s;
            background: white;
            font-family: inherit;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        textarea { resize: vertical; min-height: 80px; }

        .btn {
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
            background: transparent;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color: white; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, var(--success-dark) 100%); color: white; }
        .btn-success:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
        .btn-warning { background: linear-gradient(135deg, var(--warning) 0%, var(--warning-dark) 100%); color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%); color: white; }
        .btn-secondary { background: var(--secondary); color: white; }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--dark); }
        .btn-outline:hover { background: var(--light); }
        .btn-outline.active { background: var(--primary-light); color: white; border-color: var(--primary-light); }

        .display-mode-buttons { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .form-actions { display: flex; gap: 1rem; margin-top: 1.5rem; flex-wrap: wrap; }

        /* ===================== MAIN 3-BUTTON NAV ===================== */
        .main-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .main-action-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1.1rem 1.25rem;
            border: none;
            border-radius: var(--radius-lg);
            font-size: 1.02rem;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            text-decoration: none;
            transition: transform 0.12s ease, filter 0.12s ease;
        }
        .main-action-btn:active { transform: scale(0.98); filter: brightness(0.95); }
        .main-action-btn .icon { font-size: 1.6rem; line-height: 1; }
        .main-action-btn--add { background: linear-gradient(135deg, var(--success), var(--success-dark)); }
        .main-action-btn--stats { background: linear-gradient(135deg, #7c3aed, #5b21b6); }
        .main-action-btn--print { background: linear-gradient(135deg, var(--primary-light), var(--primary)); }
        .back-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 1.25rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            background: var(--secondary);
            color: #fff;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }

        @media (max-width: 720px) {
            .main-actions { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; }
            input, select, textarea { padding: 0.55rem 0.75rem; font-size: 0.85rem; }
            .display-mode-buttons { gap: 0.35rem; }
        }
        @media (max-width: 640px) {
            .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.45rem; }
            .card { margin-bottom: 0.8rem; }
            .card-body { padding: 0.9rem; }
        }
        @media (max-width: 520px) {
            .form-grid { grid-template-columns: 1fr; gap: 0.6rem; }
            input, select, textarea { padding: 0.45rem 0.65rem; font-size: 0.82rem; }
            textarea { min-height: 48px; }
            .display-mode-buttons { gap: 0.3rem; }
            .display-mode-buttons .btn { padding: 0.35rem 0.55rem; font-size: 0.78rem; }
            .form-actions { width: 100%; gap: 0.5rem; }
            .form-actions .btn { flex: 1; padding: 0.5rem 0.75rem; font-size: 0.8rem; }
            .search-bar { font-size: 0.9rem; }
        }

        /* ===================== SEARCH BAR ===================== */
        .search-bar {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--border);
            border-radius: 50px;
            font-size: 1rem;
            transition: all 0.2s;
            margin-bottom: 1.5rem;
        }
        .search-bar:focus { outline: none; border-color: var(--primary-light); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

        /* ===================== PRODUCTS GRID (public/client view) ===================== */
        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem; }
        .product-card { background: white; border-radius: var(--radius-lg); padding: 1.25rem; box-shadow: var(--shadow); transition: all 0.3s ease; border: 1px solid var(--border); }
        .product-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
        .product-ref { font-size: 0.75rem; color: var(--gray); text-transform: uppercase; letter-spacing: 0.5px; }
        .product-name { font-size: 1.1rem; margin: 0.5rem 0; color: var(--dark); }
        .product-display-normal, .product-table .product-display-normal { font-weight: 500; }
        .product-display-red, .product-table .product-display-red { font-weight: 800; }
        .product-display-blue, .product-table .product-display-blue { font-weight: 300; font-size: 0.92em; }
        .product-description { font-size: 0.8rem; color: var(--gray); margin-bottom: 0.75rem; line-height: 1.4; }
        .stock { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; margin: 0.5rem 0; }
        .in-stock { background: #d1fae5; color: var(--success-dark); }
        .out-stock { background: #fee2e2; color: var(--danger-dark); }
        .product-price { font-size: 1.25rem; font-weight: 700; color: var(--primary); margin: 0.75rem 0; }
        .product-price small { font-size: 0.7rem; font-weight: normal; color: var(--gray); }

        /* ===================== TABLE STYLES ===================== */
        .table-responsive { overflow-x: auto; }
        .product-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .product-table th { background: #e0f2fe; padding: 1rem; text-align: left; font-weight: 600; color: var(--primary-dark); border-bottom: 2px solid var(--border); white-space: nowrap; }
        .product-table td { padding: 1rem; border-bottom: 1px solid var(--border); }
        .product-table tr:hover { background: var(--light); }
        .action-buttons { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
        .low-stock-badge { color: var(--danger-dark); font-weight: 700; }

        .action-bar { margin-bottom: 1.5rem; display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap; }
        .empty-state { text-align: center; padding: 3rem; }
        .empty-state svg { width: 80px; height: 80px; margin-bottom: 1rem; opacity: 0.5; stroke: var(--primary); }
        .empty-state p { color: var(--gray); margin-bottom: 1rem; }

        /* ===================== EDIT MODAL ===================== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 2000;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 2rem 1rem;
        }
        .modal-overlay.is-open { display: flex; }
        .modal-box {
            background: #fff;
            width: 100%;
            max-width: 760px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .modal-box .card-header { display: flex; align-items: center; justify-content: space-between; }
        .modal-close-btn { background: transparent; border: none; color: #fff; font-size: 1.4rem; cursor: pointer; line-height: 1; }

        /* ===================== STATS VIEW ===================== */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
        .stat-tile {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            border-top: 4px solid var(--primary-light);
        }
        .stat-tile h3 { font-size: 0.85rem; color: var(--gray); margin-bottom: 0.5rem; font-weight: 600; }
        .stat-tile p { font-size: 1.5rem; font-weight: 800; color: var(--primary-dark); }
        .stat-tile.highlight { border-top-color: var(--success); }
        .stat-tile.warn { border-top-color: var(--warning); }

        /* ===================== PRINT VIEW ===================== */
        .radio-group { display: flex; gap: 1rem; flex-wrap: wrap; }
        .radio-pill {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.6rem 1rem;
            border: 1px solid var(--border);
            border-radius: 999px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .radio-pill input { width: auto; margin: 0; }
        .print-mode-tabs { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .print-mode-tab {
            padding: 0.6rem 1.1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--dark);
        }
        .print-mode-tab.active { background: var(--primary); color: white; border-color: var(--primary); }
        .print-mode-panel { display: none; }
        .print-mode-panel.active { display: block; }
        .names-picker { max-height: 320px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius); padding: 0.5rem; }
        .names-picker label { display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.5rem; border-radius: var(--radius); font-weight: 400; margin: 0; cursor: pointer; }
        .names-picker label:hover { background: var(--light); }
        .names-picker input { width: auto; margin: 0; }
        .names-picker-search { margin-bottom: 0.5rem; }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .header h1 { font-size: 1.5rem; }
            .card-body { padding: 1rem; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; }
            .products-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .btn-sm { width: 100%; text-align: center; }
            .product-table th, .product-table td { padding: 0.75rem 0.5rem; }
        }
        @media (max-width: 480px) {
            .card-header { padding: 1rem; }
            .card-header h2 { font-size: 1rem; }
            .product-table { font-size: 0.7rem; }
        }
    </style>
</head>
<body class="nc-app-body">

<div class="container">
    <div class="header">
        <h1>📦 إدارة المنتجات</h1>
        <p>استعرض وأدر كتالوج المنتجات</p>
    </div>

    <?php if ($is_logged_in): ?>
        <a href="dashboard.php" class="back-link-btn">← العودة إلى لوحة التحكم</a>
    <?php endif; ?>

    <?php if ($view !== 'list' && $is_admin_view): ?>
        <a href="products.php?view=list" class="back-link-btn">← رجوع لقائمة المنتجات</a>
    <?php endif; ?>

    <?php if ($view === 'list'): ?>

        <?php if ($is_admin_view): ?>
            <div class="main-actions">
                <a href="products.php?view=add" class="main-action-btn main-action-btn--add">
                    <span class="icon">➕</span> إضافة منتج
                </a>
                <a href="products.php?view=stats" class="main-action-btn main-action-btn--stats">
                    <span class="icon">📊</span> إحصائيات المنتج
                </a>
                <a href="products.php?view=print" class="main-action-btn main-action-btn--print">
                    <span class="icon">🖨️</span> طباعة المنتجات
                </a>
            </div>
        <?php endif; ?>

        <input type="text" id="searchInput" class="search-bar" placeholder="🔍 بحث بالاسم أو المرجع...">

        <div class="card">
            <div class="card-header">
                <h2>📋 كتالوج المنتجات (مرتّبة حسب المرجع)</h2>
            </div>
            <div class="card-body">
                <div id="productsContainer">
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M20 7L4 7M20 12L4 12M20 17L4 17M9 3L6 7L9 11" stroke="currentColor" stroke-linecap="round"/>
                            <path d="M15 3L18 7L15 11" stroke="currentColor" stroke-linecap="round"/>
                        </svg>
                        <p>جاري تحميل المنتجات...</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_admin_view): ?>
        <!-- Edit modal -->
        <div class="modal-overlay" id="editModalOverlay">
            <div class="modal-box">
                <div class="card-header">
                    <h2>✏️ تعديل منتج</h2>
                    <button type="button" class="modal-close-btn" onclick="closeEditModal()">✕</button>
                </div>
                <div class="card-body">
                    <div id="editErrorBox" style="display:none; background-color: #fef2f2; color: #dc2626; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.25rem; border-left: 4px solid #dc2626;"></div>
                    <form method="POST" id="editProductForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="editProductId" value="0">
                        <input type="hidden" name="existing_image" id="editExistingImage" value="">
                        <input type="hidden" name="display_mode" id="editDisplayMode" value="normal">

                        <div class="form-section">
                            <div class="form-section-title">🏷️ معلومات أساسية</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="editReference" class="required">المرجع</label>
                                    <input type="text" name="reference" id="editReference" required>
                                </div>
                                <div class="form-group">
                                    <label for="editName" class="required">اسم المنتج</label>
                                    <input type="text" name="nom" id="editName" required>
                                </div>
                                <div class="form-group">
                                    <label for="editMarque">العلامة</label>
                                    <input type="text" name="marque" id="editMarque" list="marqueOptions">
                                </div>
                                <div class="form-group">
                                    <label for="editFournisseur">المورّد</label>
                                    <input type="text" name="fournisseur" id="editFournisseur" list="fournisseurOptions">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label for="editDescription">الوصف</label>
                                    <textarea name="description" id="editDescription" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title">📦 المخزون والشراء</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="editStock" class="required">عدد الكراتين في المخزون</label>
                                    <input type="number" name="nombre_de_carton" id="editStock" required>
                                </div>
                                <div class="form-group">
                                    <label for="editUnitsPerCarton" class="required">وحدات لكل كرتون</label>
                                    <input type="number" name="unité_par_carton" id="editUnitsPerCarton" required>
                                </div>
                                <div class="form-group">
                                    <label for="editLowStock">حد تنبيه المخزون المنخفض</label>
                                    <input type="number" name="low_stock" id="editLowStock" min="0">
                                </div>
                                <div class="form-group">
                                    <label for="editPrixAchat" class="required">سعر الشراء (دج)</label>
                                    <input type="number" step="0.01" name="prix_achat" id="editPrixAchat" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title">💰 أسعار البيع</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="editSupergross" class="required">سعر السوبر جملة (دج)</label>
                                    <input type="number" step="0.01" name="supergross_prix" id="editSupergross" required>
                                </div>
                                <div class="form-group">
                                    <label for="editGross" class="required">سعر الجملة (دج)</label>
                                    <input type="number" step="0.01" name="gross_prix" id="editGross" required>
                                </div>
                                <div class="form-group">
                                    <label for="editDetail" class="required">سعر التجزئة (دج)</label>
                                    <input type="number" step="0.01" name="detail_prix" id="editDetail" required>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title">🎨 العرض والصورة</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>وضع العرض (سمك النص)</label>
                                    <div class="display-mode-buttons">
                                        <button type="button" class="btn btn-outline btn-sm" id="editModeNormalBtn" onclick="setEditDisplayMode('normal')">عادي</button>
                                        <button type="button" class="btn btn-outline btn-sm" id="editModeRedBtn" onclick="setEditDisplayMode('red')">عريض</button>
                                        <button type="button" class="btn btn-outline btn-sm" id="editModeBlueBtn" onclick="setEditDisplayMode('blue')">رفيع</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="editImage">صورة المنتج</label>
                                    <input type="file" name="image" id="editImage" accept="image/png,image/jpeg,image/jpg,image/gif">
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-success">✅ حفظ التعديلات</button>
                            <button type="button" class="btn btn-outline" onclick="closeEditModal()">إلغاء</button>
                            <button type="button" class="btn btn-danger" style="margin-inline-start:auto;" onclick="deleteProductFromModal()">🗑️ حذف المنتج</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <?php if ($view === 'add' && $is_admin_view): ?>
        <div class="card">
            <div class="card-header">
                <h2>➕ إضافة منتج جديد</h2>
            </div>
            <div class="card-body">
                <?php if ($error !== ''): ?>
                    <div style="background-color: #fef2f2; color: #dc2626; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border-left: 4px solid #dc2626;">
                        <strong>❌ خطأ:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="addProductForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="existing_image" value="">
                    <input type="hidden" name="display_mode" id="addDisplayMode" value="normal">

                    <div class="form-section">
                        <div class="form-section-title">🏷️ معلومات أساسية</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="addReference" class="required">المرجع</label>
                                <input type="text" name="reference" id="addReference" placeholder="مثال: 101" value="<?= isset($form_data['reference']) ? htmlspecialchars($form_data['reference']) : '' ?>" required autofocus>
                                <small class="hint">رمز فريد لتمييز المنتج — يُستخدم أيضاً في فرز الجدول والطباعة.</small>
                            </div>
                            <div class="form-group">
                                <label for="addName" class="required">اسم المنتج</label>
                                <input type="text" name="nom" id="addName" placeholder="اسم المنتج" value="<?= isset($form_data['nom']) ? htmlspecialchars($form_data['nom']) : '' ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="addMarque">العلامة</label>
                                <input type="text" name="marque" id="addMarque" list="marqueOptions" placeholder="مثال: Nettoclean" value="<?= isset($form_data['marque']) ? htmlspecialchars($form_data['marque']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label for="addFournisseur">المورّد</label>
                                <input type="text" name="fournisseur" id="addFournisseur" list="fournisseurOptions" placeholder="اسم المورّد" value="<?= isset($form_data['fournisseur']) ? htmlspecialchars($form_data['fournisseur']) : '' ?>">
                                <small class="hint">إن كان المورّد جديداً سيُضاف تلقائياً لقائمة الموردين.</small>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label for="addDescription">الوصف</label>
                                <textarea name="description" id="addDescription" rows="2" placeholder="وصف مختصر للمنتج (اختياري)"><?= isset($form_data['description']) ? htmlspecialchars($form_data['description']) : '' ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">📦 المخزون والشراء</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="addStock" class="required">عدد الكراتين في المخزون</label>
                                <input type="number" name="nombre_de_carton" id="addStock" min="0" placeholder="0" value="<?= isset($form_data['nombre_de_carton']) ? $form_data['nombre_de_carton'] : '' ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="addUnitsPerCarton" class="required">وحدات لكل كرتون</label>
                                <input type="number" name="unité_par_carton" id="addUnitsPerCarton" min="1" placeholder="1" value="<?= isset($form_data['unité_par_carton']) ? $form_data['unité_par_carton'] : '1' ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="addLowStock">حد تنبيه المخزون المنخفض</label>
                                <input type="number" name="low_stock" id="addLowStock" min="0" placeholder="0" value="<?= isset($form_data['low_stock']) ? $form_data['low_stock'] : '0' ?>">
                                <small class="hint">سيظهر المنتج ضمن "منتجات قريبة من النفاد" عند وصول المخزون لهذا الحد.</small>
                            </div>
                            <div class="form-group">
                                <label for="addPrixAchat" class="required">سعر الشراء (دج)</label>
                                <input type="number" step="0.01" name="prix_achat" id="addPrixAchat" min="0" placeholder="0.00" value="<?= isset($form_data['prix_achat']) ? $form_data['prix_achat'] : '' ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">💰 أسعار البيع</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="addSupergross" class="required">سعر السوبر جملة (دج)</label>
                                <input type="number" step="0.01" name="supergross_prix" id="addSupergross" min="0" placeholder="0.00" value="<?= isset($form_data['supergross_prix']) ? $form_data['supergross_prix'] : '' ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="addGross" class="required">سعر الجملة (دج)</label>
                                <input type="number" step="0.01" name="gross_prix" id="addGross" min="0" placeholder="0.00" value="<?= isset($form_data['gross_prix']) ? $form_data['gross_prix'] : '' ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="addDetail" class="required">سعر التجزئة (دج)</label>
                                <input type="number" step="0.01" name="detail_prix" id="addDetail" min="0" placeholder="0.00" value="<?= isset($form_data['detail_prix']) ? $form_data['detail_prix'] : '' ?>" required>
                            </div>
                        </div>
                        <div id="marginPreview" class="form-group" style="margin-top:1rem; background: var(--light); border-radius: var(--radius); padding: 0.85rem 1rem; display:none;">
                            <strong style="font-size:0.85rem; color:var(--primary-dark);">📈 هامش الربح المتوقع للوحدة الواحدة</strong>
                            <div id="marginPreviewBody" style="display:flex; gap:1.5rem; flex-wrap:wrap; margin-top:0.5rem; font-size:0.85rem;"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">🎨 العرض والصورة</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>وضع العرض (سمك النص)</label>
                                <div class="display-mode-buttons">
                                    <button type="button" class="btn btn-outline btn-sm active" id="addModeNormalBtn" onclick="setAddDisplayMode('normal')">عادي</button>
                                    <button type="button" class="btn btn-outline btn-sm" id="addModeRedBtn" onclick="setAddDisplayMode('red')">عريض</button>
                                    <button type="button" class="btn btn-outline btn-sm" id="addModeBlueBtn" onclick="setAddDisplayMode('blue')">رفيع</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="addImage">صورة المنتج</label>
                                <input type="file" name="image" id="addImage" accept="image/png,image/jpeg,image/jpg,image/gif">
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">➕ إضافة المنتج</button>
                        <label style="display:flex; align-items:center; gap:0.4rem; font-weight:500; font-size:0.85rem; color:var(--dark);">
                            <input type="checkbox" id="addAnotherToggle" name="add_another" value="1" style="width:auto;" checked>
                            إضافة منتج آخر بعد الحفظ
                        </label>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($view === 'stats' && $is_admin_view): ?>
        <div class="card">
            <div class="card-header">
                <h2>📊 إحصائيات المنتجات</h2>
            </div>
            <div class="card-body">
                <div class="stats-grid">
                    <div class="stat-tile highlight">
                        <h3>إجمالي ربح المنتجات (الشهر الحالي)</h3>
                        <p><?= formatMoney($benefitMonth) ?></p>
                    </div>
                    <div class="stat-tile highlight">
                        <h3>إجمالي ربح المنتجات (السنة الحالية)</h3>
                        <p><?= formatMoney($benefitYear) ?></p>
                    </div>
                    <div class="stat-tile warn">
                        <h3>منتجات قريبة من النفاد</h3>
                        <p><?= count($lowStockProducts) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>⚠️ منتجات قريبة من النفاد</h2></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>مرجع</th>
                                <th>اسم</th>
                                <th>مخزون كراتين</th>
                                <th>حد تنبيه</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lowStockProducts) > 0): ?>
                                <?php foreach ($lowStockProducts as $product): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($product['reference']) ?></strong></td>
                                        <td><?= htmlspecialchars($product['nom']) ?></td>
                                        <td class="low-stock-badge"><?= (int)$product['nombre_de_carton'] ?></td>
                                        <td><?= (int)$product['low_stock'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4">لا يوجد منتج قريب من النفاد. 🎉</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>🏆 أكثر منتج مبيعاً</h2></div>
            <div class="card-body">
                <?php if ($topSellingProduct): ?>
                    <div class="table-responsive">
                        <table class="product-table">
                            <thead>
                                <tr>
                                    <th>مرجع</th>
                                    <th>اسم</th>
                                    <th>كمية بيع</th>
                                    <th>مبلغ بيع</th>
                                    <th>ربح</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?= htmlspecialchars($topSellingProduct['reference']) ?></strong></td>
                                    <td><?= htmlspecialchars($topSellingProduct['nom']) ?></td>
                                    <td><?= (int)$topSellingProduct['nombre_de_vente'] ?></td>
                                    <td><?= formatMoney($topSellingProduct['montant_de_vente']) ?></td>
                                    <td><?= formatMoney($topSellingProduct['benefit']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>لا يوجد منتج.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>💰 أعلى مبلغ بيع لمنتج</h2></div>
            <div class="card-body">
                <?php if ($topBenefitProduct): ?>
                    <div class="table-responsive">
                        <table class="product-table">
                            <thead>
                                <tr>
                                    <th>مرجع</th>
                                    <th>اسم</th>
                                    <th>كمية بيع</th>
                                    <th>مبلغ بيع</th>
                                    <th>ربح</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?= htmlspecialchars($topBenefitProduct['reference']) ?></strong></td>
                                    <td><?= htmlspecialchars($topBenefitProduct['nom']) ?></td>
                                    <td><?= (int)$topBenefitProduct['nombre_de_vente'] ?></td>
                                    <td><?= formatMoney($topBenefitProduct['montant_de_vente']) ?></td>
                                    <td><?= formatMoney($topBenefitProduct['benefit']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>لا يوجد منتج.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($view === 'print' && $is_admin_view): ?>
        <div class="card">
            <div class="card-header"><h2>🖨️ طباعة قائمة المنتجات</h2></div>
            <div class="card-body">
                <form method="GET" id="printFilterForm">
                    <input type="hidden" name="view" value="print">

                    <div class="print-mode-tabs">
                        <button type="button" class="print-mode-tab" data-mode="all" onclick="setPrintMode('all')">📦 كل المنتجات</button>
                        <button type="button" class="print-mode-tab" data-mode="refs" onclick="setPrintMode('refs')">🔢 قائمة مراجع</button>
                        <button type="button" class="print-mode-tab" data-mode="range" onclick="setPrintMode('range')">↔️ فترة مراجع</button>
                        <button type="button" class="print-mode-tab" data-mode="names" onclick="setPrintMode('names')">🔍 اختيار بالاسم</button>
                    </div>

                    <input type="hidden" name="print_mode" id="printModeInput" value="<?= htmlspecialchars($printMode ?: 'all') ?>">

                    <div class="print-mode-panel" data-panel="all">
                        <p style="color:var(--gray); font-size:0.9rem;">سيتم تضمين جميع منتجات المخزون في الفاتورة.</p>
                    </div>

                    <div class="print-mode-panel" data-panel="refs">
                        <div class="form-group">
                            <label for="refsInput">أدخل مراجع المنتجات مفصولة بفاصلة</label>
                            <input type="text" name="refs" id="refsInput" placeholder="مثال: 3, 4, 10" value="<?= htmlspecialchars($_GET['refs'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="print-mode-panel" data-panel="range">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="rangeFromInput">من مرجع</label>
                                <input type="number" name="range_from" id="rangeFromInput" placeholder="مثال: 4" value="<?= htmlspecialchars($_GET['range_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="rangeToInput">إلى مرجع</label>
                                <input type="number" name="range_to" id="rangeToInput" placeholder="مثال: 45" value="<?= htmlspecialchars($_GET['range_to'] ?? '') ?>">
                            </div>
                        </div>
                        <small class="hint">تُقارَن الأجزاء الرقمية من المرجع (مثال: من 4 إلى 45 يشمل كل المراجع بين هاتين القيمتين).</small>
                    </div>

                    <div class="print-mode-panel" data-panel="names">
                        <div class="form-group">
                            <input type="text" class="names-picker-search" id="namesPickerSearch" placeholder="🔍 اكتب لتصفية القائمة...">
                            <div class="names-picker" id="namesPicker">
                                <?php
                                $allProductsForPicker = $conn->query("SELECT id, reference, nom FROM product ORDER BY reference ASC");
                                $selectedIds = array_map('intval', is_array($_GET['product_ids'] ?? null) ? $_GET['product_ids'] : []);
                                while ($pp = $allProductsForPicker->fetch_assoc()):
                                    $checked = in_array((int)$pp['id'], $selectedIds, true) ? 'checked' : '';
                                ?>
                                    <label data-label="<?= htmlspecialchars(mb_strtolower($pp['reference'] . ' ' . $pp['nom'])) ?>">
                                        <input type="checkbox" name="product_ids[]" value="<?= (int)$pp['id'] ?>" <?= $checked ?>>
                                        <span><strong><?= htmlspecialchars($pp['reference']) ?></strong> — <?= htmlspecialchars($pp['nom']) ?></span>
                                    </label>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:1.25rem;">
                        <label>السعر الذي سيظهر في الفاتورة</label>
                        <div class="radio-group">
                            <label class="radio-pill">
                                <input type="radio" name="price_type" value="supergross_prix" <?= $printPriceType === 'supergross_prix' ? 'checked' : '' ?>>
                                سوبر جملة
                            </label>
                            <label class="radio-pill">
                                <input type="radio" name="price_type" value="gross_prix" <?= $printPriceType === 'gross_prix' ? 'checked' : '' ?>>
                                جملة
                            </label>
                            <label class="radio-pill">
                                <input type="radio" name="price_type" value="detail_prix" <?= $printPriceType === 'detail_prix' ? 'checked' : '' ?>>
                                تجزئة
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">🔍 عرض النتائج</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($printMode !== ''): ?>
            <div class="card">
                <div class="card-header"><h2>📋 المعاينة (<?= count($printProducts) ?> منتج)</h2></div>
                <div class="card-body">
                    <?php if (count($printProducts) === 0): ?>
                        <p>لا توجد منتجات مطابقة لهذا الفلتر.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="product-table" id="printPreviewTable">
                                <thead>
                                    <tr>
                                        <th>المرجع</th>
                                        <th>اسم المنتج</th>
                                        <th>العلامة</th>
                                        <th>السعر (دج)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($printProducts as $pp): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($pp['reference']) ?></strong></td>
                                            <td><?= htmlspecialchars($pp['nom']) ?></td>
                                            <td><?= htmlspecialchars($pp['marque'] ?? '') ?></td>
                                            <td><?= number_format((float)$pp[$printPriceType], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-success" onclick="printCatalogFacture()">🖨️ طباعة الفاتورة</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <datalist id="marqueOptions">
        <?php foreach ($brands as $brand): ?>
            <option value="<?= htmlspecialchars($brand) ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <datalist id="fournisseurOptions">
        <?php foreach ($fournisseurs as $fournisseur): ?>
            <option value="<?= htmlspecialchars($fournisseur) ?>"></option>
        <?php endforeach; ?>
    </datalist>

</div>

<script>
    const currentView = <?= json_encode($view) ?>;
    const isAdminView = <?= $is_admin_view ? 'true' : 'false' ?>;
    const isClientView = <?= $is_logged_in && $is_client ? 'true' : 'false' ?>;
    let allProducts = [];

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function (m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    function productDisplayClass(dm) {
        let m = dm === 'green' ? 'normal' : (dm || 'normal');
        if (!['normal', 'red', 'blue'].includes(m)) m = 'normal';
        return 'product-display-' + m;
    }

    /* ===================== LIST VIEW ===================== */
    async function loadProducts() {
        try {
            const res = await fetch('products.php?action=get_products');
            allProducts = await res.json();
            renderProducts(allProducts);
        } catch (error) {
            console.error('خطأ في تحميل المنتجات:', error);
            document.getElementById('productsContainer').innerHTML = `
                <div class="empty-state"><p>❌ تعذّر تحميل المنتجات</p></div>
            `;
        }
    }

    function getStockHTML(quantity) {
        if (quantity > 0) {
            return `<span class="stock in-stock">✅ متوفر (${quantity} وحدة)</span>`;
        }
        return `<span class="stock out-stock">⚠️ نفاد المخزون</span>`;
    }

    function renderProducts(products) {
        const container = document.getElementById('productsContainer');
        if (!container) return;

        if (!products || products.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M20 7L4 7M20 12L4 12M20 17L4 17M9 3L6 7L9 11" stroke="currentColor" stroke-linecap="round"/>
                        <path d="M15 3L18 7L15 11" stroke="currentColor" stroke-linecap="round"/>
                    </svg>
                    <p>لا يوجد منتج</p>
                </div>
            `;
            return;
        }

        if (isAdminView) {
            const table = `
                <div class="table-responsive">
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>صورة</th>
                                <th>مرجع</th>
                                <th>اسم</th>
                                <th>علامة</th>
                                <th>مورّد</th>
                                <th>كراتين</th>
                                <th>وحدات</th>
                                <th>حد تنبيه</th>
                                <th>شراء</th>
                                <th>تجزئة</th>
                                <th>كمية بيع</th>
                                <th>مبلغ بيع</th>
                                <th>ربح</th>
                                <th>عرض</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${products.map((p) => `
                                <tr>
                                    <td>${p.image ? `<img src="uploads/products/${encodeURIComponent(p.image)}" alt="${escapeHtml(p.nom)}" style="width:50px;height:auto;border-radius:4px;">` : ''}</td>
                                    <td><strong>${escapeHtml(p.reference)}</strong></td>
                                    <td><span class="${productDisplayClass(p.display_mode)}">${escapeHtml(p.nom)}</span></td>
                                    <td>${escapeHtml(p.marque || '')}</td>
                                    <td>${escapeHtml(p.fournisseur || '')}</td>
                                    <td>${p.quantité / p.unité_par_carton}</td>
                                    <td>${p.quantité}</td>
                                    <td>${p.low_stock}</td>
                                    <td>${parseFloat(p.prix_achat).toFixed(2)}</td>
                                    <td>${parseFloat(p.detail_prix).toFixed(2)}</td>
                                    <td>${p.nombre_de_vente}</td>
                                    <td>${parseFloat(p.montant_de_vente).toFixed(2)}</td>
                                    <td>${parseFloat(p.benefit).toFixed(2)}</td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-outline btn-sm ${(p.display_mode === 'normal' || p.display_mode === 'green') ? 'active' : ''}" onclick="setProductDisplayMode(${p.id}, 'normal')">عادي</button>
                                            <button type="button" class="btn btn-outline btn-sm ${p.display_mode === 'red' ? 'active' : ''}" onclick="setProductDisplayMode(${p.id}, 'red')">عريض</button>
                                            <button type="button" class="btn btn-outline btn-sm ${p.display_mode === 'blue' ? 'active' : ''}" onclick="setProductDisplayMode(${p.id}, 'blue')">رفيع</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="editProduct(${p.id})" class="btn btn-warning btn-sm">✏️ تعديل</button>
                                            <button onclick="deleteProduct(${p.id})" class="btn btn-danger btn-sm">🗑️ حذف</button>
                                        </div>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
            container.innerHTML = table;
            return;
        }

        const grid = `
            <div class="products-grid">
                ${products.map((p) => `
                    <div class="product-card">
                        <div class="product-ref">Ref: ${escapeHtml(p.reference)}</div>
                        <div class="product-name ${productDisplayClass(p.display_mode)}">${escapeHtml(p.nom)}</div>
                        ${p.description ? `<div class="product-description">${escapeHtml(p.description.substring(0, 80))}${p.description.length > 80 ? '...' : ''}</div>` : ''}
                        ${getStockHTML(p.quantité)}
                        <div class="product-price">
                            ${parseFloat(p.detail_prix).toFixed(2)} DA
                            <small>/وحدة</small>
                        </div>
                        ${isClientView ? `<button onclick="orderNow(${p.id})" class="btn btn-success">🛒 اطلب الآن</button>` : ''}
                    </div>
                `).join('')}
            </div>
        `;
        container.innerHTML = grid;
    }

    function orderNow(productId) {
        window.location.href = `sales.php?product_id=${productId}`;
    }

    if (document.getElementById('searchInput')) {
        document.getElementById('searchInput').addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            const filtered = allProducts.filter((p) =>
                p.nom.toLowerCase().includes(term) || p.reference.toLowerCase().includes(term)
            );
            renderProducts(filtered);
        });
    }

    /* ===================== EDIT MODAL ===================== */
    function editProduct(id) {
        const product = allProducts.find((p) => Number(p.id) === Number(id));
        if (!product) return;

        document.getElementById('editProductId').value = product.id;
        document.getElementById('editReference').value = product.reference;
        document.getElementById('editName').value = product.nom;
        document.getElementById('editDescription').value = product.description || '';
        document.getElementById('editExistingImage').value = product.image || '';
        document.getElementById('editMarque').value = product.marque || '';
        document.getElementById('editFournisseur').value = product.fournisseur || '';
        document.getElementById('editLowStock').value = product.low_stock || 0;
        document.getElementById('editPrixAchat').value = product.prix_achat || 0;
        document.getElementById('editStock').value = product.nombre_de_carton || 0;
        document.getElementById('editUnitsPerCarton').value = product.unité_par_carton;
        document.getElementById('editSupergross').value = product.supergross_prix;
        document.getElementById('editGross').value = product.gross_prix;
        document.getElementById('editDetail').value = product.detail_prix;
        document.getElementById('editDisplayMode').value = product.display_mode || 'normal';
        setEditDisplayMode(product.display_mode || 'normal');
        document.getElementById('editErrorBox').style.display = 'none';

        document.getElementById('editModalOverlay').classList.add('is-open');
    }

    function closeEditModal() {
        document.getElementById('editModalOverlay').classList.remove('is-open');
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeEditModal();
    });
    document.addEventListener('click', (e) => {
        if (e.target && e.target.id === 'editModalOverlay') closeEditModal();
    });

    function setEditDisplayMode(mode) {
        document.getElementById('editDisplayMode').value = mode;
        document.getElementById('editModeNormalBtn').classList.toggle('active', mode === 'normal');
        document.getElementById('editModeRedBtn').classList.toggle('active', mode === 'red');
        document.getElementById('editModeBlueBtn').classList.toggle('active', mode === 'blue');
    }

    function deleteProductFromModal() {
        const id = document.getElementById('editProductId').value;
        deleteProduct(id);
    }

    function setProductDisplayMode(id, mode) {
        fetch('products.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'set_display_mode', id: id, display_mode: mode })
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    loadProducts();
                } else {
                    alert(data.error || 'تعذّر تحديث وضع العرض.');
                }
            })
            .catch(() => alert('تعذّر تحديث وضع العرض.'));
    }

    function deleteProduct(id) {
        if (confirm('⚠️ هل أنت متأكد من حذف هذا المنتج؟ لا يمكن التراجع.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    if (currentView === 'list') {
        window.addEventListener('DOMContentLoaded', loadProducts);
    }
</script>

<script>
    /* ===================== ADD VIEW ===================== */
    function setAddDisplayMode(mode) {
        const input = document.getElementById('addDisplayMode');
        if (!input) return;
        input.value = mode;
        document.getElementById('addModeNormalBtn').classList.toggle('active', mode === 'normal');
        document.getElementById('addModeRedBtn').classList.toggle('active', mode === 'red');
        document.getElementById('addModeBlueBtn').classList.toggle('active', mode === 'blue');
    }

    function updateMarginPreview() {
        const box = document.getElementById('marginPreview');
        const body = document.getElementById('marginPreviewBody');
        if (!box || !body) return;

        const achat = parseFloat(document.getElementById('addPrixAchat')?.value || '0');
        const superg = parseFloat(document.getElementById('addSupergross')?.value || '0');
        const gross = parseFloat(document.getElementById('addGross')?.value || '0');
        const detail = parseFloat(document.getElementById('addDetail')?.value || '0');

        if (!achat) {
            box.style.display = 'none';
            return;
        }

        const rows = [
            ['سوبر جملة', superg],
            ['جملة', gross],
            ['تجزئة', detail]
        ].map(([label, price]) => {
            const margin = price - achat;
            const pct = achat > 0 ? (margin / achat) * 100 : 0;
            const color = margin >= 0 ? 'var(--success-dark)' : 'var(--danger-dark)';
            return `<span><strong>${label}:</strong> <span style="color:${color};">${margin.toFixed(2)} دج (${pct.toFixed(0)}%)</span></span>`;
        }).join('');

        body.innerHTML = rows;
        box.style.display = 'block';
    }

    ['addPrixAchat', 'addSupergross', 'addGross', 'addDetail'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateMarginPreview);
    });
    if (document.getElementById('marginPreview')) updateMarginPreview();

    const addProductForm = document.getElementById('addProductForm');
    if (addProductForm) {
        // Small UX touch: pressing Enter in the reference/name fields jumps to the next field
        // instead of accidentally submitting the form early.
        addProductForm.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                const focusable = Array.from(addProductForm.querySelectorAll('input, textarea, select'))
                    .filter((el) => !el.disabled && el.type !== 'hidden' && el.offsetParent !== null);
                const idx = focusable.indexOf(e.target);
                if (idx > -1 && idx < focusable.length - 1) {
                    e.preventDefault();
                    focusable[idx + 1].focus();
                }
            }
        });
    }
</script>

<script>
    /* ===================== PRINT VIEW ===================== */
    function setPrintMode(mode) {
        document.getElementById('printModeInput').value = mode;
        document.querySelectorAll('.print-mode-tab').forEach((tab) => {
            tab.classList.toggle('active', tab.getAttribute('data-mode') === mode);
        });
        document.querySelectorAll('.print-mode-panel').forEach((panel) => {
            panel.classList.toggle('active', panel.getAttribute('data-panel') === mode);
        });
    }

    (function initPrintModeFromQuery() {
        const input = document.getElementById('printModeInput');
        if (!input) return;
        setPrintMode(input.value || 'all');
    })();

    const namesPickerSearch = document.getElementById('namesPickerSearch');
    if (namesPickerSearch) {
        namesPickerSearch.addEventListener('input', (e) => {
            const term = e.target.value.trim().toLowerCase();
            document.querySelectorAll('#namesPicker label').forEach((label) => {
                const haystack = label.getAttribute('data-label') || '';
                label.style.display = haystack.includes(term) ? 'flex' : 'none';
            });
        });
    }

    function printCatalogFacture() {
        const table = document.getElementById('printPreviewTable');
        if (!table) return;

        const priceLabel = { supergross_prix: 'سوبر جملة', gross_prix: 'جملة', detail_prix: 'تجزئة' }[<?= json_encode($printPriceType) ?>] || 'السعر';

        const rowsHtml = Array.from(table.querySelectorAll('tbody tr')).map((tr) => {
            const cells = Array.from(tr.querySelectorAll('td'));
            return `
                <tr>
                    <td>${cells[0] ? cells[0].innerHTML : ''}</td>
                    <td>${cells[1] ? cells[1].innerHTML : ''}</td>
                    <td>${cells[2] ? cells[2].innerHTML : ''}</td>
                    <td>${cells[3] ? cells[3].innerHTML : ''} دج</td>
                </tr>
            `;
        }).join('');

        const totalProducts = table.querySelectorAll('tbody tr').length;

        const printWin = window.open('', '_blank');
        printWin.document.write(`
            <!DOCTYPE html>
            <html lang="ar" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <title>فاتورة كتالوج المنتجات</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 16px; color: #111; direction: rtl; }
                    .top-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; table-layout: fixed; }
                    .top-table td { border: 1px solid #333; vertical-align: top; padding: 8px; font-size: 12px; line-height: 1.6; width: 33.33%; }
                    table.items { width: 100%; border-collapse: collapse; }
                    .items th, .items td { border: 1px solid #ccc; padding: 8px; font-size: 12px; text-align: right; }
                    .items th { background: #f1f5f9; }
                    .summary { width: 280px; margin-right: auto; margin-top: 14px; }
                    .summary-row { display: flex; justify-content: space-between; border-bottom: 1px solid #ddd; padding: 4px 0; font-size: 12px; }
                    @media print { body { margin: 0; } }
                </style>
            </head>
            <body>
                <table class="top-table">
                    <tr>
                        <td>
                            نباع عبد الرحمن بائع بالجملة<br>
                            رقم الهاتف 0771316625<br>
                            نباع عبد الرزاق بائع بالجملة<br>
                            رقم الهاتف 0662253103
                        </td>
                        <td>
                            فاتورة كتالوج المنتجات<br>
                            الوقت: ${new Date().toLocaleString('fr-FR')}<br>
                            نوع السعر المعروض: ${priceLabel}
                        </td>
                        <td>
                            عدد المنتجات: ${totalProducts}
                        </td>
                    </tr>
                </table>
                <table class="items">
                    <thead>
                        <tr>
                            <th>المرجع</th>
                            <th>اسم المنتج</th>
                            <th>العلامة</th>
                            <th>${priceLabel}</th>
                        </tr>
                    </thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
                <div class="summary">
                    <div class="summary-row"><strong>إجمالي عدد المنتجات</strong><span>${totalProducts}</span></div>
                </div>
                <script>
                    window.onload = function () {
                        window.print();
                        setTimeout(function () { window.close(); }, 500);
                    };
                <\/script>
            </body>
            </html>
        `);
        printWin.document.close();
    }
</script>

</body>
</html>
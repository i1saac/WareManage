<?php
// sales.php - Shared Sales Management
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'] ?? '';
$user_id   = $_SESSION['user_id'];
$is_admin_user = in_array($user_type, ['admin', 'staff_admin'], true);
$is_vendeur_user = ($user_type === 'vendeur');

$logged_in_client_type = 'detail';  // default
if ($is_client) {
    $stmt = $conn->prepare("SELECT type FROM client WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $client_info = $result->fetch_assoc();
    $stmt->close();
    if ($client_info) {
        $logged_in_client_type = $client_info['type'] ?? 'detail';
    }
}

$error = '';
$success = '';
if (($_GET['items_added'] ?? '') === '1') {
    $success = 'تمت إضافة المنتجات الجديدة إلى البيع بنجاح.';
}
$search_query = trim($_GET['q'] ?? '');

function recalculate_sale_total($conn, $sale_id) {
    $stmt = $conn->prepare("SELECT SUM(montant) AS total FROM sale_items WHERE sale_id = ?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['total'] : 0.0;
}

function get_client_credit($conn, $client_id) {
    $stmt = $conn->prepare("SELECT credit FROM client WHERE id = ?");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['credit'] : 0.0;
}

function recalculate_sale_totals($conn, $sale_id) {
    $stmt = $conn->prepare("SELECT montant, credit, remise, versement, status, client_id FROM sales WHERE id = ?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $sale = $result->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        return;
    }

    $montant = (float)$sale['montant'];
    $remise = (float)$sale['remise'];
    $versement = (float)$sale['versement'];

    if ($sale['status'] === 'en_cours') {
        $credit = get_client_credit($conn, $sale['client_id']);
    } else {
        $credit = (float)$sale['credit'];
    }

    $total_actuel = $montant + $credit - $remise;
    $credit_actuel = $total_actuel - $versement;

    if ($sale['status'] === 'en_cours') {
        $stmt = $conn->prepare("UPDATE sales SET credit = ?, total_actuel = ?, credit_actuel = ? WHERE id = ?");
        $stmt->bind_param('dddi', $credit, $total_actuel, $credit_actuel, $sale_id);
    } else {
        $stmt = $conn->prepare("UPDATE sales SET total_actuel = ?, credit_actuel = ? WHERE id = ?");
        $stmt->bind_param('ddi', $total_actuel, $credit_actuel, $sale_id);
    }
    $stmt->execute();
    $stmt->close();
}

function update_client_financials($conn, $client_id) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(versement), 0) AS total_versement FROM sales WHERE client_id = ? AND status != 'annulé'");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $versement_total = $row ? (float)$row['total_versement'] : 0.0;

    $stmt = $conn->prepare("SELECT credit_actuel FROM sales WHERE client_id = ? AND status = 'validé' ORDER BY time DESC, id DESC LIMIT 1");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $credit_actuel = $row ? (float)$row['credit_actuel'] : 0.0;

    $stmt = $conn->prepare("UPDATE client SET credit = ?, versement_total = ? WHERE id = ?");
    $stmt->bind_param('ddi', $credit_actuel, $versement_total, $client_id);
    $stmt->execute();
    $stmt->close();
}

function get_price_field($clientType = '') {
    return 'detail_prix';
}

function get_price_value($prod, $price_type = 'detail', $custom_price = null) {
    switch ($price_type) {
        case 'supergross':
            return $prod['supergross_prix'];
        case 'gross':
            return $prod['gross_prix'];
        case 'detail':
            return $prod['detail_prix'];
        case 'custom':
            return $custom_price !== null ? max(0, (float)$custom_price) : $prod['detail_prix'];
        default:
            return $prod['detail_prix'];
    }
}

function normalize_post_array($value) {
    if (is_array($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return [];
    }
    return [$value];
}

/** Classe CSS product-name-* (graisse : normal / red=gras / blue=fin ; green retiré → normal) */
function product_display_css_class($mode) {
    $m = ($mode === 'green') ? 'normal' : ($mode ?: 'normal');
    return in_array($m, ['normal', 'red', 'blue'], true) ? $m : 'normal';
}

function ensure_sale_items_marque_column($conn) {
    $result = $conn->query("SHOW COLUMNS FROM sale_items LIKE 'marque'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE sale_items ADD COLUMN marque VARCHAR(100) DEFAULT NULL AFTER product_reference");
    }
}

function sale_affects_stock($status) {
    return in_array($status, ['validé', 'preparé', 'pret'], true);
}

function is_sale_editable($status) {
    return $status !== 'annulé';
}

function validate_product_cartons_available($conn, $product_id, $cartons_needed, &$error) {
    $cartons_needed = (int)$cartons_needed;
    if ($product_id <= 0 || $cartons_needed <= 0) {
        return true;
    }

    $stmt = $conn->prepare("SELECT reference, nom, nombre_de_carton FROM product WHERE id = ?");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $prod = $result->fetch_assoc();
    $stmt->close();

    if (!$prod) {
        $error = 'المنتج غير موجود.';
        return false;
    }

    if ($cartons_needed > (int)$prod['nombre_de_carton']) {
        $label = htmlspecialchars($prod['reference'] ?: $prod['nom']);
        $error = 'المنتج «' . $label . '» متوفر فقط ' . (int)$prod['nombre_de_carton'] . ' كرتون(ات) في المخزون.';
        return false;
    }

    return true;
}

function adjust_product_stock_delta($conn, $product_id, $carton_delta) {
    $carton_delta = (int)$carton_delta;
    if ($carton_delta === 0 || $product_id <= 0) {
        return;
    }

    if ($carton_delta > 0) {
        update_product_stock($conn, $product_id, $carton_delta);
        return;
    }

    $restore_cartons = abs($carton_delta);
    $update = $conn->prepare("UPDATE product SET nombre_de_carton = nombre_de_carton + ? WHERE id = ?");
    $update->bind_param('ii', $restore_cartons, $product_id);
    $update->execute();
    $update->close();
}

function validate_sale_cartons_stock($conn, $product_ids, $cartons, &$error) {
    $product_ids = normalize_post_array($product_ids);
    $cartons = normalize_post_array($cartons);
    $requested = [];

    foreach ($product_ids as $index => $product_id) {
        $product_id = (int)$product_id;
        $nombre_de_carton = (int)($cartons[$index] ?? 0);

        if ($product_id <= 0 || $nombre_de_carton <= 0) {
            continue;
        }
        $requested[$product_id] = ($requested[$product_id] ?? 0) + $nombre_de_carton;
    }

    foreach ($requested as $product_id => $total_cartons) {
        $stmt = $conn->prepare("SELECT reference, nom, nombre_de_carton FROM product WHERE id = ?");
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $prod = $result->fetch_assoc();
        $stmt->close();

        if (!$prod) {
            continue;
        }

        if ($total_cartons > (int)$prod['nombre_de_carton']) {
            $error = 'لا يمكن إنشاء البيع: المنتج «' . htmlspecialchars($prod['reference']) . '» متوفر فقط ' . (int)$prod['nombre_de_carton'] . ' كرتون(ات) في المخزون.';
            return false;
        }
    }

    return true;
}

function add_sale_items($conn, $sale_id, $product_ids, $cartons, $price_types, $unit_prices, $is_client, $user_id, &$success, &$error) {
    $product_ids = normalize_post_array($product_ids) ?: [];
    $cartons = normalize_post_array($cartons) ?: [];
    $price_types = normalize_post_array($price_types) ?: [];
    $unit_prices = normalize_post_array($unit_prices) ?: [];

    if ($sale_id <= 0) {
        $error = 'معرّف البيع غير صالح.';
        return;
    }

    $stmt = $conn->prepare("SELECT s.status, s.client_id, c.type FROM sales s JOIN client c ON s.client_id = c.id WHERE s.id = ?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $sale = $result->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        $error = 'تعذّر إضافة منتج لهذا البيع.';
        return;
    }

    if ($is_client && $sale['status'] !== 'en_cours') {
        $error = 'تعذّر إضافة منتج لهذا البيع.';
        return;
    }

    if (!$is_client && !is_sale_editable($sale['status'])) {
        $error = 'تعذّر إضافة منتج لهذا البيع.';
        return;
    }

    $stock_locked = sale_affects_stock($sale['status']);
    if ($stock_locked && !validate_sale_cartons_stock($conn, $product_ids, $cartons, $error)) {
        return;
    }

    if ($is_client && $sale['client_id'] != $user_id) {
        $error = 'الطلب غير مسموح.';
        return;
    }

    $inserted = 0;
    $client_type = $sale['type'] ?? 'detail';
    
    foreach ($product_ids as $index => $product_id) {
        $product_id = (int)$product_id;
        $nombre_de_carton = (int)($cartons[$index] ?? 0);
        $price_type = $price_types[$index] ?? ($is_client ? $client_type : 'detail');
        $custom_price = $unit_prices[$index] ?? null;

        if ($product_id <= 0 || $nombre_de_carton <= 0) {
            continue;
        }

        $stmt = $conn->prepare("SELECT reference, marque, supergross_prix, gross_prix, detail_prix, unité_par_carton FROM product WHERE id = ?");
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $prod = $result->fetch_assoc();
        $stmt->close();

        if (!$prod) {
            continue;
        }

        if ($is_client) {
            $custom_price = null;
        }

        $prix_unite = get_price_value($prod, $price_type, $custom_price);
        $quantite = $nombre_de_carton * (int)$prod['unité_par_carton'];
        $montant = $quantite * $prix_unite;

        $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, product_reference, marque, prix_type, nombre_de_carton, nombre_unite_par_carton, quantité, prix_unite, montant) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $marque = $prod['marque'] ?? null;
        $stmt->bind_param('iisssiiidd', $sale_id, $product_id, $prod['reference'], $marque, $price_type, $nombre_de_carton, $prod['unité_par_carton'], $quantite, $prix_unite, $montant);
        $stmt->execute();
        $stmt->close();

        if ($stock_locked) {
            update_product_stock($conn, $product_id, $nombre_de_carton);
            update_product_sale_stats($conn, $product_id, $quantite, $montant);
        }

        $inserted++;
    }

    if ($inserted > 0) {
        $total_montant = recalculate_sale_total($conn, $sale_id);
        $stmt = $conn->prepare("UPDATE sales SET montant = ? WHERE id = ?");
        $stmt->bind_param('di', $total_montant, $sale_id);
        $stmt->execute();
        $stmt->close();
        recalculate_sale_totals($conn, $sale_id);
        if ($stock_locked) {
            update_client_financials($conn, $sale['client_id']);
        }
        $success = $inserted > 1 ? 'تمت إضافة المنتجات إلى الطلب.' : 'تمت إضافة المنتج إلى الطلب.';
    } else {
        $error = 'يرجى اختيار منتج وإدخال عدد الكراتين.';
    }
}

function update_sale_item_record($conn, $sale_item_id, $sale_id, $product_id, $nombre_de_carton, $price_type, $custom_price, $is_client, $user_id, &$success, &$error) {
    if ($sale_item_id <= 0 || $sale_id <= 0 || $product_id <= 0 || $nombre_de_carton <= 0) {
        $error = 'يرجى تعبئة جميع حقول التعديل.';
        return;
    }

    $stmt = $conn->prepare("SELECT s.status, s.client_id FROM sales s WHERE s.id = ?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $sale = $result->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        $error = 'الطلب غير موجود.';
        return;
    }

    if (!is_sale_editable($sale['status'])) {
        $error = 'لا يمكن تعديل هذا البيع.';
        return;
    }

    if ($is_client && $sale['client_id'] != $user_id) {
        $error = 'الطلب غير مسموح.';
        return;
    }

    $stock_locked = sale_affects_stock($sale['status']);
    $old_item = null;
    if ($stock_locked) {
        $stmt = $conn->prepare("SELECT product_id, nombre_de_carton, quantité, montant FROM sale_items WHERE id = ? AND sale_id = ?");
        $stmt->bind_param('ii', $sale_item_id, $sale_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $old_item = $result->fetch_assoc();
        $stmt->close();

        if (!$old_item) {
            $error = 'بند البيع غير موجود.';
            return;
        }

        $old_product_id = (int)$old_item['product_id'];
        $old_cartons = (int)$old_item['nombre_de_carton'];

        if ($product_id !== $old_product_id) {
            if (!validate_product_cartons_available($conn, $product_id, $nombre_de_carton, $error)) {
                return;
            }
        } elseif ($nombre_de_carton > $old_cartons) {
            if (!validate_product_cartons_available($conn, $product_id, $nombre_de_carton - $old_cartons, $error)) {
                return;
            }
        }
    }

    $stmt = $conn->prepare("SELECT reference, marque, supergross_prix, gross_prix, detail_prix, unité_par_carton FROM product WHERE id = ?");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $prod = $result->fetch_assoc();
    $stmt->close();

    if (!$prod) {
        $error = 'المنتج غير موجود.';
        return;
    }

    $prix_unite = get_price_value($prod, $price_type, $custom_price);
    $quantite = $nombre_de_carton * (int)$prod['unité_par_carton'];
    $montant = $quantite * $prix_unite;

    $stmt = $conn->prepare("UPDATE sale_items SET product_id = ?, product_reference = ?, marque = ?, prix_type = ?, nombre_de_carton = ?, nombre_unite_par_carton = ?, quantité = ?, prix_unite = ?, montant = ? WHERE id = ? AND sale_id = ?");
    $marque = $prod['marque'] ?? null;
    $stmt->bind_param('isssiiiddii', $product_id, $prod['reference'], $marque, $price_type, $nombre_de_carton, $prod['unité_par_carton'], $quantite, $prix_unite, $montant, $sale_item_id, $sale_id);
    $stmt->execute();
    $stmt->close();

    if ($stock_locked && $old_item) {
        $old_product_id = (int)$old_item['product_id'];
        $old_cartons = (int)$old_item['nombre_de_carton'];
        $old_quantite = (int)$old_item['quantité'];
        $old_montant = (float)$old_item['montant'];

        if ($product_id !== $old_product_id) {
            adjust_product_stock_delta($conn, $old_product_id, -$old_cartons);
            adjust_product_stock_delta($conn, $product_id, $nombre_de_carton);
            update_product_sale_stats($conn, $old_product_id, -$old_quantite, -$old_montant);
            update_product_sale_stats($conn, $product_id, $quantite, $montant);
        } else {
            $carton_diff = $nombre_de_carton - $old_cartons;
            if ($carton_diff !== 0) {
                adjust_product_stock_delta($conn, $product_id, $carton_diff);
            }

            $quantite_diff = $quantite - $old_quantite;
            $montant_diff = $montant - $old_montant;
            if ($quantite_diff !== 0 || $montant_diff !== 0.0) {
                update_product_sale_stats($conn, $product_id, $quantite_diff, $montant_diff);
            }
        }
    }

    $total_montant = recalculate_sale_total($conn, $sale_id);
    $stmt = $conn->prepare("UPDATE sales SET montant = ? WHERE id = ?");
    $stmt->bind_param('di', $total_montant, $sale_id);
    $stmt->execute();
    $stmt->close();
    recalculate_sale_totals($conn, $sale_id);
    if ($stock_locked) {
        update_client_financials($conn, $sale['client_id']);
    }

    $success = 'تم تحديث بند البيع.';
}

function update_product_stock($conn, $product_id, $cartons) {
    $stmt = $conn->prepare("UPDATE product SET nombre_de_carton = GREATEST(0, nombre_de_carton - ?) WHERE id = ?");
    $stmt->bind_param('ii', $cartons, $product_id);
    $stmt->execute();
    $stmt->close();
}

function update_product_sale_stats($conn, $product_id, $quantity, $montant) {
    $stmt = $conn->prepare("UPDATE product SET nombre_de_vente = GREATEST(0, nombre_de_vente + ?), montant_de_vente = GREATEST(0, montant_de_vente + ?) WHERE id = ?");
    $stmt->bind_param('idi', $quantity, $montant, $product_id);
    $stmt->execute();
    $stmt->close();
}

function restore_sale_stock($conn, $sale_id) {
    $stmt = $conn->prepare("SELECT product_id, nombre_de_carton FROM sale_items WHERE sale_id = ?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $restore_cartons = (int)$item['nombre_de_carton'];
        if ($restore_cartons > 0) {
            $update = $conn->prepare("UPDATE product SET nombre_de_carton = nombre_de_carton + ? WHERE id = ?");
            $update->bind_param('ii', $restore_cartons, $item['product_id']);
            $update->execute();
            $update->close();
        }
    }

    $stmt->close();
}

function record_sale_stats($conn, $sale_id) {
    $stmt = $conn->prepare("SELECT product_id, quantité, montant FROM sale_items WHERE sale_id = ?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $quantity = (int)$item['quantité'];
        $montant = (float)$item['montant'];
        if ($quantity > 0 || $montant > 0) {
            update_product_sale_stats($conn, $item['product_id'], $quantity, $montant);
        }
    }

    $stmt->close();
}

function restore_sale_stats($conn, $sale_id) {
    $stmt = $conn->prepare("SELECT product_id, quantité, montant FROM sale_items WHERE sale_id = ?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $quantity = (int)$item['quantité'];
        $montant = (float)$item['montant'];
        if ($quantity > 0 || $montant > 0) {
            update_product_sale_stats($conn, $item['product_id'], -$quantity, -$montant);
        }
    }

    $stmt->close();
}

/** Creates the `return` / `return_items` tables on first use if they don't exist yet,
 *  mirroring the `sales` / `sale_items` structure but without remise/versement. */
function ensure_return_tables_exist($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `return` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `admin_id` int(11) DEFAULT NULL COMMENT 'Employé qui a enregistré le retour',
        `client_id` int(11) NOT NULL,
        `montant` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Valeur totale des produits retournés',
        `credit` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Dette du client avant l''opération',
        `total_actuel` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Montant à rembourser au client si le retour dépasse sa dette',
        `credit_actuel` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Dette restante du client après l''opération',
        `source` varchar(50) NOT NULL DEFAULT 'warehouse',
        `time` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `client_id` (`client_id`),
        KEY `admin_id` (`admin_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS `return_items` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `return_id` int(11) NOT NULL,
        `product_id` int(11) NOT NULL,
        `product_reference` varchar(50) NOT NULL,
        `marque` varchar(100) DEFAULT NULL,
        `prix_type` enum('supergross','gross','detail','custom') NOT NULL DEFAULT 'custom',
        `nombre_de_carton` int(11) NOT NULL DEFAULT 0,
        `nombre_unite_par_carton` int(11) NOT NULL DEFAULT 1,
        `quantité` int(11) NOT NULL,
        `prix_unite` decimal(10,2) NOT NULL,
        `montant` decimal(12,2) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `return_id` (`return_id`),
        KEY `product_id` (`product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Registers a warehouse return: restocks each returned product, records the operation,
 *  and reduces the client's debt (crediting them back for what they brought in).
 *  credit_actuel = max(credit - montant, 0)   -> what the client still owes afterward
 *  total_actuel  = max(montant - credit, 0)   -> what the store owes back to the client, if any
 */
function create_return($conn, $client_id, $product_ids, $cartons, $unit_prices, $user_id, &$success, &$error) {
    $product_ids = normalize_post_array($product_ids) ?: [];
    $cartons = normalize_post_array($cartons) ?: [];
    $unit_prices = normalize_post_array($unit_prices) ?: [];

    $client_id = (int)$client_id;
    if ($client_id <= 0) {
        $error = 'يرجى اختيار عميل صالح.';
        return null;
    }
    if (count($product_ids) === 0) {
        $error = 'يرجى إضافة منتج واحد على الأقل إلى عملية الإرجاع.';
        return null;
    }

    $stmt = $conn->prepare("SELECT id, credit FROM client WHERE id = ?");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $client_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client_row) {
        $error = 'العميل غير موجود.';
        return null;
    }

    $lines = [];
    $total_montant = 0.0;

    foreach ($product_ids as $index => $product_id) {
        $product_id = (int)$product_id;
        $nombre_de_carton = (int)($cartons[$index] ?? 0);
        $custom_price = $unit_prices[$index] ?? null;

        if ($product_id <= 0 || $nombre_de_carton <= 0) {
            continue;
        }

        $stmt = $conn->prepare("SELECT reference, marque, unité_par_carton FROM product WHERE id = ?");
        $stmt->bind_param('i', $product_id);
        $stmt->execute();
        $prod = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$prod) {
            continue;
        }

        $prix_unite = $custom_price !== null ? max(0.0, (float)$custom_price) : 0.0;
        $unite_par_carton = (int)$prod['unité_par_carton'];
        $quantite = $nombre_de_carton * $unite_par_carton;
        $montant = $quantite * $prix_unite;

        $lines[] = [
            'product_id' => $product_id,
            'reference' => $prod['reference'],
            'marque' => $prod['marque'] ?? null,
            'cartons' => $nombre_de_carton,
            'unite_par_carton' => $unite_par_carton,
            'quantite' => $quantite,
            'prix_unite' => $prix_unite,
            'montant' => $montant,
        ];
        $total_montant += $montant;
    }

    if (count($lines) === 0) {
        $error = 'يرجى إضافة منتج واحد صالح على الأقل.';
        return null;
    }

    $credit_before = (float)$client_row['credit'];
    $credit_actuel = max($credit_before - $total_montant, 0.0);
    $total_actuel = max($total_montant - $credit_before, 0.0);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO `return` (admin_id, client_id, montant, credit, total_actuel, credit_actuel, source, time) VALUES (?, ?, ?, ?, ?, ?, 'warehouse', NOW())");
        $stmt->bind_param('iidddd', $user_id, $client_id, $total_montant, $credit_before, $total_actuel, $credit_actuel);
        $stmt->execute();
        $return_id = $stmt->insert_id;
        $stmt->close();

        foreach ($lines as $line) {
            $stmt = $conn->prepare("INSERT INTO return_items (return_id, product_id, product_reference, marque, prix_type, nombre_de_carton, nombre_unite_par_carton, quantité, prix_unite, montant) VALUES (?, ?, ?, ?, 'custom', ?, ?, ?, ?, ?)");
            $stmt->bind_param('iissiiidd', $return_id, $line['product_id'], $line['reference'], $line['marque'], $line['cartons'], $line['unite_par_carton'], $line['quantite'], $line['prix_unite'], $line['montant']);
            $stmt->execute();
            $stmt->close();

            $restore = $conn->prepare("UPDATE product SET nombre_de_carton = nombre_de_carton + ? WHERE id = ?");
            $restore->bind_param('ii', $line['cartons'], $line['product_id']);
            $restore->execute();
            $restore->close();
        }

        $stmt = $conn->prepare("UPDATE client SET credit = ? WHERE id = ?");
        $stmt->bind_param('di', $credit_actuel, $client_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $success = 'تم تسجيل عملية إرجاع المنتجات بنجاح.';
        return $return_id;
    } catch (Throwable $e) {
        $conn->rollback();
        $error = 'حدث خطأ أثناء تسجيل عملية الإرجاع.';
        return null;
    }
}

ensure_sale_items_marque_column($conn);
ensure_return_tables_exist($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($is_client) {
        if ($action === 'create_order') {
            $product_ids = $_POST['product_id'] ?? [];
            $cartons = $_POST['nombre_de_carton'] ?? [];
            $price_types = $_POST['price_type'] ?? [];
            $unit_prices = $_POST['prix_unite'] ?? [];
            $inserted = 0;
            $total_montant = 0.0;

            if (!is_array($product_ids) || count($product_ids) === 0) {
                $error = 'يرجى إضافة منتج واحد على الأقل إلى الطلب.';
            } elseif (!validate_sale_cartons_stock($conn, $product_ids, $cartons, $error)) {
                // $error is already set by the validation helper.
            } else {
                $stmt = $conn->prepare("INSERT INTO sales (admin_id, client_id, montant, status, source, credit, remise, versement, total_actuel, credit_actuel) VALUES (NULL, ?, 0.00, 'en_cours', 'online', 0.00, 0.00, 0.00, 0.00, 0.00)");
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $sale_id = $stmt->insert_id;
                $stmt->close();

                foreach ($product_ids as $index => $product_id) {
                    $product_id = (int)$product_id;
                    $nombre_de_carton = (int)($cartons[$index] ?? 0);
                    $price_type = 'detail';
                    $custom_price = null;

                    if ($product_id <= 0 || $nombre_de_carton <= 0) {
                        continue;
                    }

                    $stmt = $conn->prepare("SELECT reference, marque, supergross_prix, gross_prix, detail_prix, unité_par_carton FROM product WHERE id = ?");
                    $stmt->bind_param('i', $product_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $prod = $result->fetch_assoc();
                    $stmt->close();

                    if (!$prod) {
                        continue;
                    }

                    $prix_unite = get_price_value($prod, $price_type, $custom_price);
                    $quantite = $nombre_de_carton * $prod['unité_par_carton'];
                    $montant = $quantite * $prix_unite;

                    $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, product_reference, marque, prix_type, nombre_de_carton, nombre_unite_par_carton, quantité, prix_unite, montant) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $marque = $prod['marque'] ?? null;
                    $stmt->bind_param('iisssiiidd', $sale_id, $product_id, $prod['reference'], $marque, $price_type, $nombre_de_carton, $prod['unité_par_carton'], $quantite, $prix_unite, $montant);
                    $stmt->execute();
                    $stmt->close();

                    $total_montant += $montant;
                    $inserted++;
                }

                if ($inserted > 0) {
                    $stmt = $conn->prepare("UPDATE sales SET montant = ? WHERE id = ?");
                    $stmt->bind_param('di', $total_montant, $sale_id);
                    $stmt->execute();
                    $stmt->close();
                    recalculate_sale_totals($conn, $sale_id);

                    $success = 'تم تسجيل طلبك بنجاح وهو يظهر في سجلك.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM sales WHERE id = ?");
                    $stmt->bind_param('i', $sale_id);
                    $stmt->execute();
                    $stmt->close();

                    $error = 'لم يُضف أي منتج صالح إلى الطلب.';
                }
            }
        } elseif ($action === 'cancel_sale') {
            $sales_id = (int)($_POST['sales_id'] ?? 0);
            if ($sales_id <= 0) {
                $error = 'الطلب غير موجود.';
            } else {
                $stmt = $conn->prepare("SELECT status FROM sales WHERE id = ? AND client_id = ?");
                $stmt->bind_param('ii', $sales_id, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $sale = $result->fetch_assoc();
                $stmt->close();

                if ($sale && $sale['status'] === 'en_cours') {
                    $stmt = $conn->prepare("DELETE FROM sales WHERE id = ?");
                    $stmt->bind_param('i', $sales_id);
                    $stmt->execute();
                    $stmt->close();

                    $success = 'تم حذف طلبك.';
                } else {
                    $error = 'تعذّر حذف هذا الطلب.';
                }
            }
        } elseif ($action === 'add_sale_item') {
            $sales_id = (int)($_POST['sale_id'] ?? 0);
            $product_ids = $_POST['product_id'] ?? [];
            $cartons = $_POST['nombre_de_carton'] ?? [];
            $price_types = $_POST['price_type'] ?? [];
            $unit_prices = $_POST['prix_unite'] ?? [];
            if ($is_admin_user) {
                add_sale_items($conn, $sales_id, $product_ids, $cartons, $price_types, $unit_prices, $is_client, $user_id, $success, $error);
                if ($success && $sales_id > 0) {
                    header('Location: sales.php?sale_id=' . $sales_id . '&items_added=1');
                    exit;
                }
            } elseif ($is_client) {
                add_sale_items($conn, $sales_id, $product_ids, $cartons, $price_types, $unit_prices, true, $user_id, $success, $error);
            } else {
                $error = 'إجراء غير مسموح.';
            }
        } elseif ($action === 'update_sale_item') {
            if ($is_admin_user) {
                $sale_item_id = (int)($_POST['sale_item_id'] ?? 0);
                $sale_id = (int)($_POST['sale_id'] ?? 0);
                $product_id = (int)($_POST['product_id'] ?? 0);
                $nombre_de_carton = (int)($_POST['nombre_de_carton'] ?? 0);
                $price_type = $_POST['price_type'] ?? 'detail';
                $custom_price = isset($_POST['prix_unite']) ? $_POST['prix_unite'] : null;
                update_sale_item_record($conn, $sale_item_id, $sale_id, $product_id, $nombre_de_carton, $price_type, $custom_price, $is_client, $user_id, $success, $error);
            } else {
                $error = 'إجراء غير مسموح.';
            }
        } elseif ($action === 'delete_sale_item') {
            if (!$is_admin_user) {
                $error = 'إجراء غير مسموح.';
            } else {
                $sale_item_id = (int)($_POST['sale_item_id'] ?? 0);
                $sale_id = (int)($_POST['sale_id'] ?? 0);
                if ($sale_item_id <= 0 || $sale_id <= 0) {
                    $error = 'بيانات غير صالحة.';
                } else {
                    $stmt = $conn->prepare("SELECT s.status, si.product_id, si.nombre_de_carton, si.quantité, si.montant FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.id = ? AND si.sale_id = ?");
                    $stmt->bind_param('ii', $sale_item_id, $sale_id);
                    $stmt->execute();
                    $item_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$item_data) {
                        $error = 'الصنف غير موجود.';
                    } else {
                        $stmt = $conn->prepare("DELETE FROM sale_items WHERE id = ? AND sale_id = ?");
                        $stmt->bind_param('ii', $sale_item_id, $sale_id);
                        $stmt->execute();
                        $deleted = $stmt->affected_rows > 0;
                        $stmt->close();

                        if ($deleted) {
                            $status = (string)($item_data['status'] ?? '');
                            if (in_array($status, ['validé', 'preparé', 'pret'], true)) {
                                $restore_cartons = (int)($item_data['nombre_de_carton'] ?? 0);
                                if ($restore_cartons > 0) {
                                    $restore = $conn->prepare("UPDATE product SET nombre_de_carton = nombre_de_carton + ? WHERE id = ?");
                                    $product_id = (int)$item_data['product_id'];
                                    $restore->bind_param('ii', $restore_cartons, $product_id);
                                    $restore->execute();
                                    $restore->close();
                                }
                                update_product_sale_stats(
                                    $conn,
                                    (int)$item_data['product_id'],
                                    -((int)($item_data['quantité'] ?? 0)),
                                    -((float)($item_data['montant'] ?? 0))
                                );
                            }

                            $new_total = recalculate_sale_total($conn, $sale_id);
                            $stmt = $conn->prepare("UPDATE sales SET montant = ? WHERE id = ?");
                            $stmt->bind_param('di', $new_total, $sale_id);
                            $stmt->execute();
                            $stmt->close();
                            recalculate_sale_totals($conn, $sale_id);
                            if (sale_affects_stock($status)) {
                                $client_stmt = $conn->prepare("SELECT client_id FROM sales WHERE id = ?");
                                $client_stmt->bind_param('i', $sale_id);
                                $client_stmt->execute();
                                $client_row = $client_stmt->get_result()->fetch_assoc();
                                $client_stmt->close();
                                if ($client_row) {
                                    update_client_financials($conn, (int)$client_row['client_id']);
                                }
                            }
                            $success = 'تم حذف الصنف من البيع.';
                        } else {
                            $error = 'الصنف غير موجود.';
                        }
                    }
                }
            }
        }
    } else {
        if ($action === 'validate_sale') {
            if (!$is_admin_user) {
                $error = 'إجراء غير مسموح.';
            } else {
                $sales_id = (int)($_POST['sales_id'] ?? 0);
                $remise = max(0.0, (float)($_POST['remise'] ?? 0));
                $versement = max(0.0, (float)($_POST['versement'] ?? 0));
                if ($sales_id <= 0) {
                    $error = 'معرّف البيع غير صالح.';
                } else {
                    $stmt = $conn->prepare("SELECT status, client_id, montant, source FROM sales WHERE id = ?");
                    $stmt->bind_param('i', $sales_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $sale = $result->fetch_assoc();
                    $stmt->close();

                    if ($sale && $sale['status'] === 'en_cours') {
                        $client_credit = get_client_credit($conn, $sale['client_id']);
                        $total_actuel = (float)$sale['montant'] + $client_credit - $remise;
                        $credit_actuel = $total_actuel - $versement;

                        if (($sale['source'] ?? '') === 'online') {
                            if ($user_type === 'admin') {
                                $stmt = $conn->prepare("UPDATE sales SET status = 'validé', admin_id = ?, credit = ?, remise = ?, versement = ?, total_actuel = ?, credit_actuel = ? WHERE id = ?");
                                $stmt->bind_param('idddddi', $user_id, $client_credit, $remise, $versement, $total_actuel, $credit_actuel, $sales_id);
                            } else {
                                $stmt = $conn->prepare("UPDATE sales SET status = 'validé', credit = ?, remise = ?, versement = ?, total_actuel = ?, credit_actuel = ? WHERE id = ?");
                                $stmt->bind_param('dddddi', $client_credit, $remise, $versement, $total_actuel, $credit_actuel, $sales_id);
                            }
                        } else {
                            $stmt = $conn->prepare("UPDATE sales SET status = 'validé', credit = ?, remise = ?, versement = ?, total_actuel = ?, credit_actuel = ? WHERE id = ?");
                            $stmt->bind_param('dddddi', $client_credit, $remise, $versement, $total_actuel, $credit_actuel, $sales_id);
                        }
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT product_id, nombre_de_carton, quantité, montant FROM sale_items WHERE sale_id = ?");
                        $stmt->bind_param('i', $sales_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($item = $result->fetch_assoc()) {
                            update_product_stock($conn, $item['product_id'], $item['nombre_de_carton']);
                            update_product_sale_stats($conn, $item['product_id'], (int)$item['quantité'], (float)$item['montant']);
                        }
                        $stmt->close();
                        update_client_financials($conn, $sale['client_id']);

                        $success = 'تم تأكيد الطلب وتحديث المخزون.';
                    } else {
                        $error = 'لا يمكن تأكيد هذا الطلب.';
                    }
                }
            }
        } elseif ($action === 'update_sale_payment') {
            if (!$is_admin_user) {
                $error = 'إجراء غير مسموح.';
            } else {
                $sales_id = (int)($_POST['sales_id'] ?? 0);
                $versement = max(0.0, (float)($_POST['versement'] ?? 0));
                if ($sales_id <= 0) {
                    $error = 'معرّف البيع غير صالح.';
                } else {
                    $stmt = $conn->prepare("SELECT status, client_id, montant, credit, remise FROM sales WHERE id = ?");
                    $stmt->bind_param('i', $sales_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $sale = $result->fetch_assoc();
                    $stmt->close();

                    if ($sale && sale_affects_stock($sale['status'])) {
                        $total_actuel = (float)$sale['montant'] + (float)$sale['credit'] - (float)$sale['remise'];
                        $credit_actuel = $total_actuel - $versement;

                        $stmt = $conn->prepare("UPDATE sales SET versement = ?, total_actuel = ?, credit_actuel = ? WHERE id = ?");
                        $stmt->bind_param('dddi', $versement, $total_actuel, $credit_actuel, $sales_id);
                        $stmt->execute();
                        $stmt->close();
                        update_client_financials($conn, (int)$sale['client_id']);

                        $success = 'تم تحديث الدفعة.';
                    } else {
                        $error = 'لا يمكن تعديل الدفعة لهذا البيع.';
                    }
                }
            }
        } elseif ($action === 'mark_prepared') {
            if (!$is_vendeur_user) {
                $error = 'البائع فقط يمكنه وضع الطلب كـ«مُجهّز».';
            } else {
                $sales_id = (int)($_POST['sales_id'] ?? 0);
                if ($sales_id <= 0) {
                    $error = 'معرّف البيع غير صالح.';
                } else {
                    $stmt = $conn->prepare("SELECT status FROM sales WHERE id = ?");
                    $stmt->bind_param('i', $sales_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $sale = $result->fetch_assoc();
                    $stmt->close();

                    if ($sale && $sale['status'] === 'validé') {
                        $stmt = $conn->prepare("UPDATE sales SET status = 'preparé', seller_stuff_id = ? WHERE id = ?");
                        $stmt->bind_param('ii', $user_id, $sales_id);
                        $stmt->execute();
                        $stmt->close();
                        $success = 'تم وضع الطلب كمُجهّز.';
                    } else {
                        $error = 'لا يمكن وضع هذا البيع كمُجهّز.';
                    }
                }
            }
        } elseif ($action === 'mark_ready') {
            if (!$is_vendeur_user) {
                $error = 'البائع فقط يمكنه وضع الطلب كـ«جاهز».';
            } else {
                $sales_id = (int)($_POST['sales_id'] ?? 0);
                if ($sales_id <= 0) {
                    $error = 'معرّف البيع غير صالح.';
                } else {
                    $stmt = $conn->prepare("SELECT status FROM sales WHERE id = ?");
                    $stmt->bind_param('i', $sales_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $sale = $result->fetch_assoc();
                    $stmt->close();

                    if ($sale && $sale['status'] === 'preparé') {
                        $stmt = $conn->prepare("UPDATE sales SET status = 'pret', seller_stuff_id = ? WHERE id = ?");
                        $stmt->bind_param('ii', $user_id, $sales_id);
                        $stmt->execute();
                        $stmt->close();
                        $success = 'تم وضع الطلب كجاهز.';
                    } else {
                        $error = 'لا يمكن وضع هذا البيع كجاهز.';
                    }
                }
            }
        } elseif ($action === 'create_sale') {
            if (!$is_admin_user) {
                $error = 'المسؤولون فقط يمكنهم إنشاء المبيعات.';
            } else {
                $client_id = (int)($_POST['client_id'] ?? 0);
                $product_ids = $_POST['product_id'] ?? [];
                $cartons = $_POST['nombre_de_carton'] ?? [];
                $price_types = $_POST['price_type'] ?? [];
                $unit_prices = $_POST['prix_unite'] ?? [];
                $remise = max(0.0, (float)($_POST['remise'] ?? 0));
                $versement = max(0.0, (float)($_POST['versement'] ?? 0));
                $inserted = 0;
                $total_montant = 0.0;

                if ($client_id <= 0 || !is_array($product_ids) || count($product_ids) === 0) {
                    $error = 'يرجى تعبئة جميع حقول نموذج البيع.';
                } elseif (!validate_sale_cartons_stock($conn, $product_ids, $cartons, $error)) {
                    // $error is already set by the validation helper.
                } else {
                    $stmt = $conn->prepare("SELECT id, type FROM client WHERE id = ? AND approved = 1");
                    $stmt->bind_param('i', $client_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $client = $result->fetch_assoc();
                    $stmt->close();

                    if (!$client) {
                        $error = 'العميل غير موجود أو غير معتمد.';
                    } else {
                        if ($user_type === 'admin') {
                            $query = "INSERT INTO sales (admin_id, client_id, montant, status, source, credit, remise, versement, total_actuel, credit_actuel) VALUES (?, ?, 0.00, 'en_cours', 'magasin', 0.00, 0.00, 0.00, 0.00, 0.00)";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param('ii', $user_id, $client_id);
                        } else {
                            $query = "INSERT INTO sales (admin_id, client_id, montant, status, source, credit, remise, versement, total_actuel, credit_actuel) VALUES (NULL, ?, 0.00, 'en_cours', 'magasin', 0.00, 0.00, 0.00, 0.00, 0.00)";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param('i', $client_id);
                        }
                        if (!$stmt->execute()) {
                            $error = 'خطأ أثناء إنشاء البيع: ' . $stmt->error;
                            $stmt->close();
                        } else {
                            $sale_id = $stmt->insert_id;
                            $stmt->close();

                            if ($sale_id <= 0) {
                                $error = 'خطأ: معرّف البيع غير صالح.';
                            } else {
                                $client_type = $client['type'] ?? 'detail';

                                foreach ($product_ids as $index => $product_id) {
                                    $product_id = (int)$product_id;
                                    $nombre_de_carton = (int)($cartons[$index] ?? 0);
                                    $price_type = $price_types[$index] ?? $client_type;
                                    $custom_price = $unit_prices[$index] ?? null;

                                    if ($product_id <= 0 || $nombre_de_carton <= 0) {
                                        continue;
                                    }

                                    $stmt = $conn->prepare("SELECT reference, marque, supergross_prix, gross_prix, detail_prix, unité_par_carton FROM product WHERE id = ?");
                                    $stmt->bind_param('i', $product_id);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    $prod = $result->fetch_assoc();
                                    $stmt->close();

                                    if (!$prod) {
                                        continue;
                                    }

                                    $prix_unite = get_price_value($prod, $price_type, $custom_price);
                                    $quantite = $nombre_de_carton * $prod['unité_par_carton'];
                                    $montant = $quantite * $prix_unite;

                                    $stmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, product_reference, marque, prix_type, nombre_de_carton, nombre_unite_par_carton, quantité, prix_unite, montant) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $marque = $prod['marque'] ?? null;
                                    $stmt->bind_param('iisssiiidd', $sale_id, $product_id, $prod['reference'], $marque, $price_type, $nombre_de_carton, $prod['unité_par_carton'], $quantite, $prix_unite, $montant);
                                    $stmt->execute();
                                    $stmt->close();

                                    $total_montant += $montant;
                                    $inserted++;
                                }

                                if ($inserted > 0) {
                                    $stmt = $conn->prepare("UPDATE sales SET montant = ?, remise = ?, versement = ? WHERE id = ?");
                                    $stmt->bind_param('dddi', $total_montant, $remise, $versement, $sale_id);
                                    $stmt->execute();
                                    $stmt->close();
                                    recalculate_sale_totals($conn, $sale_id);

                                    $success = 'تم إنشاء البيع بنجاح.';
                                } else {
                                    $stmt = $conn->prepare("DELETE FROM sales WHERE id = ?");
                                    $stmt->bind_param('i', $sale_id);
                                    $stmt->execute();
                                    $stmt->close();

                                    $error = 'لم يُضف أي منتج صالح إلى البيع.';
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'create_return') {
            if (!$is_admin_user) {
                $error = 'إجراء غير مسموح.';
            } else {
                $client_id = (int)($_POST['client_id'] ?? 0);
                $product_ids = $_POST['product_id'] ?? [];
                $cartons = $_POST['nombre_de_carton'] ?? [];
                $unit_prices = $_POST['prix_unite'] ?? [];
                $new_return_id = create_return($conn, $client_id, $product_ids, $cartons, $unit_prices, $user_id, $success, $error);
                if ($success && $new_return_id) {
                    header('Location: sales.php?return_id=' . $new_return_id);
                    exit;
                }
            }
        } elseif ($action === 'cancel_sale') {
            $sales_id = (int)($_POST['sales_id'] ?? 0);
            if ($is_admin_user && $sales_id > 0) {
                $stmt = $conn->prepare("SELECT status FROM sales WHERE id = ?");
                $stmt->bind_param('i', $sales_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $sale = $result->fetch_assoc();
                $stmt->close();

                if ($sale) {
                    if ($sale['status'] === 'validé' || $sale['status'] === 'pret') {
                        restore_sale_stock($conn, $sales_id);
                        restore_sale_stats($conn, $sales_id);
                    }

                    $stmt = $conn->prepare("DELETE FROM sale_items WHERE sale_id = ?");
                    $stmt->bind_param('i', $sales_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM sales WHERE id = ?");
                    $stmt->bind_param('i', $sales_id);
                    $stmt->execute();
                    $stmt->close();

                    $success = 'تم حذف الطلب.';
                } else {
                    $error = 'الطلب غير موجود أو غير مسموح.';
                }
            }
        } elseif ($action === 'add_sale_item') {
            if ($is_admin_user) {
                $sales_id = (int)($_POST['sale_id'] ?? 0);
                $product_ids = $_POST['product_id'] ?? [];
                $cartons = $_POST['nombre_de_carton'] ?? [];
                $price_types = $_POST['price_type'] ?? [];
                $unit_prices = $_POST['prix_unite'] ?? [];
                add_sale_items($conn, $sales_id, $product_ids, $cartons, $price_types, $unit_prices, $is_client, $user_id, $success, $error);
            } else {
                $error = 'إجراء غير مسموح.';
            }
        } elseif ($action === 'update_sale_item') {
            if ($is_admin_user) {
                $sale_item_id = (int)($_POST['sale_item_id'] ?? 0);
                $sale_id = (int)($_POST['sale_id'] ?? 0);
                $product_id = (int)($_POST['product_id'] ?? 0);
                $nombre_de_carton = (int)($_POST['nombre_de_carton'] ?? 0);
                $price_type = $_POST['price_type'] ?? 'detail';
                $custom_price = isset($_POST['prix_unite']) ? $_POST['prix_unite'] : null;
                update_sale_item_record($conn, $sale_item_id, $sale_id, $product_id, $nombre_de_carton, $price_type, $custom_price, $is_client, $user_id, $success, $error);
            } else {
                $error = 'إجراء غير مسموح.';
            }
        } elseif ($action === 'delete_sale_item') {
            if (!$is_admin_user) {
                $error = 'إجراء غير مسموح.';
            } else {
                $sale_item_id = (int)($_POST['sale_item_id'] ?? 0);
                $sale_id = (int)($_POST['sale_id'] ?? 0);
                if ($sale_item_id <= 0 || $sale_id <= 0) {
                    $error = 'بيانات غير صالحة.';
                } else {
                    $stmt = $conn->prepare("SELECT s.status, si.product_id, si.nombre_de_carton, si.quantité, si.montant FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.id = ? AND si.sale_id = ?");
                    $stmt->bind_param('ii', $sale_item_id, $sale_id);
                    $stmt->execute();
                    $item_data = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$item_data) {
                        $error = 'الصنف غير موجود.';
                    } else {
                        $stmt = $conn->prepare("DELETE FROM sale_items WHERE id = ? AND sale_id = ?");
                        $stmt->bind_param('ii', $sale_item_id, $sale_id);
                        $stmt->execute();
                        $deleted = $stmt->affected_rows > 0;
                        $stmt->close();

                        if ($deleted) {
                            $status = (string)($item_data['status'] ?? '');
                            if (in_array($status, ['validé', 'preparé', 'pret'], true)) {
                                $restore_cartons = (int)($item_data['nombre_de_carton'] ?? 0);
                                if ($restore_cartons > 0) {
                                    $restore = $conn->prepare("UPDATE product SET nombre_de_carton = nombre_de_carton + ? WHERE id = ?");
                                    $product_id = (int)$item_data['product_id'];
                                    $restore->bind_param('ii', $restore_cartons, $product_id);
                                    $restore->execute();
                                    $restore->close();
                                }
                                update_product_sale_stats(
                                    $conn,
                                    (int)$item_data['product_id'],
                                    -((int)($item_data['quantité'] ?? 0)),
                                    -((float)($item_data['montant'] ?? 0))
                                );
                            }

                            $new_total = recalculate_sale_total($conn, $sale_id);
                            $stmt = $conn->prepare("UPDATE sales SET montant = ? WHERE id = ?");
                            $stmt->bind_param('di', $new_total, $sale_id);
                            $stmt->execute();
                            $stmt->close();
                            recalculate_sale_totals($conn, $sale_id);
                            if (sale_affects_stock($status)) {
                                $client_stmt = $conn->prepare("SELECT client_id FROM sales WHERE id = ?");
                                $client_stmt->bind_param('i', $sale_id);
                                $client_stmt->execute();
                                $client_row = $client_stmt->get_result()->fetch_assoc();
                                $client_stmt->close();
                                if ($client_row) {
                                    update_client_financials($conn, (int)$client_row['client_id']);
                                }
                            }
                            $success = 'تم حذف الصنف من البيع.';
                        } else {
                            $error = 'الصنف غير موجود.';
                        }
                    }
                }
            }
        }
    }}

$products = [];
$product_result = $conn->query("SELECT id, nom, reference, marque, unité_par_carton, nombre_de_carton, supergross_prix, gross_prix, detail_prix FROM product ORDER BY marque ASC, nom ASC");
while ($row = $product_result->fetch_assoc()) {
    $products[] = $row;
}
if (!$is_client) {
    $clients = [];
    $client_result = $conn->query("SELECT id, nom, type, credit FROM client WHERE approved = 1 ORDER BY nom");
    while ($row = $client_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

if ($is_client) {
    if ($search_query === '') {
        $sales_query = "SELECT s.id, s.time, s.montant, s.status, s.source FROM sales s WHERE s.client_id = ? ORDER BY s.time DESC";
        $stmt = $conn->prepare($sales_query);
        $stmt->bind_param('i', $user_id);
    } elseif (ctype_digit($search_query)) {
        $sales_query = "SELECT s.id, s.time, s.montant, s.status, s.source FROM sales s WHERE s.client_id = ? AND (s.id = ? OR s.time LIKE ?) ORDER BY s.time DESC";
        $stmt = $conn->prepare($sales_query);
        $like = "%{$search_query}%";
        $stmt->bind_param('iis', $user_id, $search_query, $like);
    } else {
        $sales_query = "SELECT s.id, s.time, s.montant, s.status, s.source FROM sales s WHERE s.client_id = ? AND s.time LIKE ? ORDER BY s.time DESC";
        $stmt = $conn->prepare($sales_query);
        $like = "%{$search_query}%";
        $stmt->bind_param('is', $user_id, $like);
    }
    $stmt->execute();
    $sales_result = $stmt->get_result();
    $stmt->close();
} else {
    $status_condition = $is_vendeur_user ? " AND s.status IN ('validé', 'preparé')" : '';
    if ($search_query === '') {
        $sales_query = "SELECT s.id, s.time, s.montant, s.status, s.source, c.nom AS client_nom, s.admin_id, a.nom AS admin_nom, s.seller_stuff_id, v.nom AS seller_nom FROM sales s JOIN client c ON s.client_id = c.id LEFT JOIN admin a ON s.admin_id = a.id LEFT JOIN stuff v ON s.seller_stuff_id = v.id WHERE 1=1" . $status_condition . " ORDER BY s.time DESC";
        $sales_result = $conn->query($sales_query);
    } elseif (ctype_digit($search_query)) {
        $sales_query = "SELECT s.id, s.time, s.montant, s.status, s.source, c.nom AS client_nom, s.admin_id, a.nom AS admin_nom, s.seller_stuff_id, v.nom AS seller_nom FROM sales s JOIN client c ON s.client_id = c.id LEFT JOIN admin a ON s.admin_id = a.id LEFT JOIN stuff v ON s.seller_stuff_id = v.id WHERE (s.id = ? OR s.time LIKE ? OR c.nom LIKE ?)" . $status_condition . " ORDER BY s.time DESC";
        $stmt = $conn->prepare($sales_query);
        $like = "%{$search_query}%";
        $stmt->bind_param('iss', $search_query, $like, $like);
        $stmt->execute();
        $sales_result = $stmt->get_result();
        $stmt->close();
    } else {
        $sales_query = "SELECT s.id, s.time, s.montant, s.status, s.source, c.nom AS client_nom, s.admin_id, a.nom AS admin_nom, s.seller_stuff_id, v.nom AS seller_nom FROM sales s JOIN client c ON s.client_id = c.id LEFT JOIN admin a ON s.admin_id = a.id LEFT JOIN stuff v ON s.seller_stuff_id = v.id WHERE (s.time LIKE ? OR c.nom LIKE ?)" . $status_condition . " ORDER BY s.time DESC";
        $stmt = $conn->prepare($sales_query);
        $like = "%{$search_query}%";
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $sales_result = $stmt->get_result();
        $stmt->close();
    }
}

// Lightweight "today only" list used on the main menu screen, independent of any search filter.
$today_sales = [];
if ($is_client) {
    $stmt = $conn->prepare("SELECT id, time, montant, status, source FROM sales WHERE client_id = ? AND DATE(time) = CURDATE() ORDER BY time DESC");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $today_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $today_status_condition = $is_vendeur_user ? " AND s.status IN ('validé', 'preparé')" : '';
    $today_query = "SELECT s.id, s.time, s.montant, s.status, s.source, c.nom AS client_nom FROM sales s JOIN client c ON s.client_id = c.id WHERE DATE(s.time) = CURDATE()" . $today_status_condition . " ORDER BY s.time DESC";
    $today_result = $conn->query($today_query);
    $today_sales = $today_result ? $today_result->fetch_all(MYSQLI_ASSOC) : [];
}

$selected_product_id = 0;
$selected_product = null;
$client_open_sales = [];
if ($is_client) {
    $selected_product_id = (int)($_GET['product_id'] ?? 0);
    if ($selected_product_id > 0) {
        $stmt = $conn->prepare("SELECT id, reference, nom, quantité, unité_par_carton, detail_prix FROM product WHERE id = ?");
        $stmt->bind_param('i', $selected_product_id);
        $stmt->execute();
        $selected_product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($selected_product) {
            $stmt = $conn->prepare("SELECT id, time, montant FROM sales WHERE client_id = ? AND status = 'en_cours' ORDER BY time DESC");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $client_open_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

$sale_detail = null;
$sale_items = [];
$daily_delivery_counter = 0;
if (isset($_GET['sale_id'])) {
    $sale_id = (int)$_GET['sale_id'];
    if ($sale_id > 0) {
        if ($is_client) {
            $stmt = $conn->prepare("SELECT s.*, c.nom AS client_nom, c.type AS client_type, a.nom AS admin_nom, v.nom AS seller_nom FROM sales s JOIN client c ON s.client_id = c.id LEFT JOIN admin a ON s.admin_id = a.id LEFT JOIN stuff v ON s.seller_stuff_id = v.id WHERE s.id = ? AND s.client_id = ?");
            $stmt->bind_param('ii', $sale_id, $user_id);
        } else if ($is_admin_user) {
            $stmt = $conn->prepare("SELECT s.*, c.nom AS client_nom, c.type AS client_type, a.nom AS admin_nom, v.nom AS seller_nom FROM sales s JOIN client c ON s.client_id = c.id LEFT JOIN admin a ON s.admin_id = a.id LEFT JOIN stuff v ON s.seller_stuff_id = v.id WHERE s.id = ?");
            $stmt->bind_param('i', $sale_id);
        } else {
            $stmt = $conn->prepare("SELECT s.*, c.nom AS client_nom, c.type AS client_type, a.nom AS admin_nom, v.nom AS seller_nom FROM sales s JOIN client c ON s.client_id = c.id LEFT JOIN admin a ON s.admin_id = a.id LEFT JOIN stuff v ON s.seller_stuff_id = v.id WHERE s.id = ? AND s.status IN ('validé', 'preparé')");
            $stmt->bind_param('i', $sale_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $sale_detail = $result->fetch_assoc();
        $stmt->close();

        if ($sale_detail) {
            $stmt = $conn->prepare("SELECT si.*, p.nom AS product_name, p.reference AS product_ref, p.display_mode FROM sale_items si JOIN product p ON si.product_id = p.id WHERE si.sale_id = ? ORDER BY si.id");
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();
            $sale_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Daily delivery counter for PDF:
            // counts sale sequence for the same day without changing DB schema.
            if (!empty($sale_detail['time'])) {
                $sale_time = (string)$sale_detail['time'];
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS daily_counter
                    FROM sales
                    WHERE DATE(time) = DATE(?)
                      AND (time < ? OR (time = ? AND id <= ?))
                ");
                $stmt->bind_param('sssi', $sale_time, $sale_time, $sale_time, $sale_id);
                $stmt->execute();
                $counter_row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $daily_delivery_counter = (int)($counter_row['daily_counter'] ?? 0);
            }
        }
    }
}

// "Add product to this sale" now reuses the creation screen: fetch the target sale's
// client + existing items so they can be prefilled without resubmitting them as new rows.
$add_to_sale_id = 0;
$add_to_sale_items = [];
$add_to_sale_client_id = 0;
$add_to_sale_client_name = '';
$add_to_sale_client_type = 'detail';
if (!$is_client && $is_admin_user && isset($_GET['add_to_sale_id'])) {
    $candidate_id = (int)$_GET['add_to_sale_id'];
    if ($candidate_id > 0) {
        $stmt = $conn->prepare("SELECT s.id, s.client_id, c.nom AS client_nom, c.type AS client_type FROM sales s JOIN client c ON s.client_id = c.id WHERE s.id = ?");
        $stmt->bind_param('i', $candidate_id);
        $stmt->execute();
        $add_to_sale_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($add_to_sale_row) {
            $add_to_sale_id = (int)$add_to_sale_row['id'];
            $add_to_sale_client_id = (int)$add_to_sale_row['client_id'];
            $add_to_sale_client_name = $add_to_sale_row['client_nom'];
            $add_to_sale_client_type = $add_to_sale_row['client_type'] ?? 'detail';

            $stmt = $conn->prepare("SELECT si.product_id, si.product_reference, p.nom AS product_nom, si.nombre_de_carton, si.prix_unite, si.quantité, si.montant FROM sale_items si JOIN product p ON si.product_id = p.id WHERE si.sale_id = ? ORDER BY si.id");
            $stmt->bind_param('i', $add_to_sale_id);
            $stmt->execute();
            $add_to_sale_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
}

// Returns list (shown under the "products return" form) + single return detail view.
$returns_list = [];
$return_detail = null;
$return_items = [];
if (!$is_client && $is_admin_user) {
    $returns_result = $conn->query("SELECT r.*, c.nom AS client_nom, a.nom AS admin_nom FROM `return` r JOIN client c ON r.client_id = c.id LEFT JOIN admin a ON r.admin_id = a.id ORDER BY r.time DESC");
    $returns_list = $returns_result ? $returns_result->fetch_all(MYSQLI_ASSOC) : [];

    if (isset($_GET['return_id'])) {
        $view_return_id = (int)$_GET['return_id'];
        if ($view_return_id > 0) {
            $stmt = $conn->prepare("SELECT r.*, c.nom AS client_nom, a.nom AS admin_nom FROM `return` r JOIN client c ON r.client_id = c.id LEFT JOIN admin a ON r.admin_id = a.id WHERE r.id = ?");
            $stmt->bind_param('i', $view_return_id);
            $stmt->execute();
            $return_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($return_detail) {
                $stmt = $conn->prepare("SELECT ri.*, p.nom AS product_nom FROM return_items ri JOIN product p ON ri.product_id = p.id WHERE ri.return_id = ? ORDER BY ri.id");
                $stmt->bind_param('i', $view_return_id);
                $stmt->execute();
                $return_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
        }
    }
}

// Decide which of the 3 mobile screens (menu / create / list / detail) should be visible on load.
$initial_screen = 'menu';
if ($return_detail) {
    $initial_screen = 'return-detail';
} elseif ($add_to_sale_id > 0) {
    $initial_screen = 'create';
} elseif ($sale_detail) {
    $initial_screen = 'detail';
} elseif ($selected_product) {
    $initial_screen = 'create';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';
    if (in_array($post_action, ['create_sale', 'create_order'], true)) {
        $initial_screen = $success ? 'list' : 'create';
    } elseif ($post_action === 'create_return') {
        $initial_screen = $success ? 'menu' : 'return';
    } elseif (in_array($post_action, ['add_sale_item', 'update_sale_item', 'delete_sale_item', 'cancel_sale', 'update_sale_payment', 'validate_sale', 'mark_prepared', 'mark_ready'], true)) {
        $initial_screen = 'list';
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>المبيعات — NettoClean</title>
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
        const clients = <?= json_encode($clients ?? [], JSON_UNESCAPED_UNICODE) ?>;
        window.__ncInitialScreen = <?= json_encode($initial_screen, JSON_UNESCAPED_UNICODE) ?>;
        window.__addToSaleId = <?= json_encode($add_to_sale_id > 0 ? $add_to_sale_id : null) ?>;
        window.__addToSaleClient = <?= json_encode($add_to_sale_id > 0 ? [
            'id' => $add_to_sale_client_id,
            'nom' => $add_to_sale_client_name,
            'type' => $add_to_sale_client_type,
        ] : null, JSON_UNESCAPED_UNICODE) ?>;
        window.__addToSaleExistingItems = <?= json_encode(array_map(function ($it) {
            return [
                'productId' => (int)$it['product_id'],
                'nom' => $it['product_nom'],
                'reference' => $it['product_reference'],
                'cartons' => (int)$it['nombre_de_carton'],
                'unitPrice' => (float)$it['prix_unite'],
                'quantite' => (float)$it['quantité'],
                'montant' => (float)$it['montant'],
                'existing' => true,
            ];
        }, $add_to_sale_items), JSON_UNESCAPED_UNICODE) ?>;

        function findProduct(value) {
            if (!value) return null;
            const normalized = value.trim().toLowerCase();

            // Allow direct search by product id (e.g. "15" or "id:15")
            const idMatch = normalized.match(/^id\s*[:#]?\s*(\d+)$/i) || normalized.match(/^(\d+)$/);
            if (idMatch) {
                const id = Number(idMatch[1]);
                if (Number.isFinite(id)) {
                    const byId = findProductById(id);
                    if (byId) return byId;
                }
            }

            return products.find(product => {
                const name = String(product.nom || '').toLowerCase();
                const ref = String(product.reference || '').toLowerCase();
                const brand = String(product.marque || '').toLowerCase();

                // Common labels users may see / type (datalist + manual)
                const labelA = `${name} | مرجع: ${ref}`;     // current datalist format
                const labelB = `${ref} - ${name}`;          // alternative "REF - NAME"
                const labelC = `${ref} ${name}`;            // loose typing
                const labelD = `${brand} ${name} ${ref}`;   // include brand for partials

                return name === normalized
                    || ref === normalized
                    || labelA === normalized
                    || labelB === normalized
                    || labelC === normalized
                    || labelA.startsWith(normalized)
                    || labelB.startsWith(normalized)
                    || name.includes(normalized)
                    || ref.includes(normalized)
                    || labelA.includes(normalized)
                    || labelB.includes(normalized)
                    || labelD.includes(normalized);
            }) || null;
        }

        function findProductById(id) {
            const n = Number(id);
            if (!Number.isFinite(n)) return null;
            return products.find((product) => Number(product.id) === n) || null;
        }

        function getPriceByType(product, type) {
            if (!product) return 0;
            switch (type) {
                case 'supergross':
                    return parseFloat(product.supergross_prix || 0);
                case 'gross':
                    return parseFloat(product.gross_prix || 0);
                case 'detail':
                    return parseFloat(product.detail_prix || 0);
                default:
                    return parseFloat(product.detail_prix || 0);
            }
        }

        function syncProductInput(input) {
            const row = input.closest('.product-line');
            const match = findProduct(input.value);
            const hidden = row.querySelector('.product-id');
            const units = row.querySelector('.product-units');
            const marque = row.querySelector('.product-marque');
            const unitPrice = row.querySelector('.unit-price');
            const clientType = input.form.dataset.clientType || 'detail';

            if (match) {
                hidden.value = match.id;
                units.textContent = match.unité_par_carton;
                if (marque) marque.textContent = match.marque || '-';
                unitPrice.value = getPriceByType(match, clientType).toFixed(2);
            } else {
                hidden.value = '';
                units.textContent = '0';
                if (marque) marque.textContent = '-';
                unitPrice.value = '';
            }
            const row3 = row.querySelector('.nc-pos-row3');
            if (row3) row3.classList.toggle('nc-hidden', !match);
            updateQuantity(row);
            updateLineTotals(row);
        }

        function onPriceChange(input) {
            const row = input.closest('.product-line');
            updateLineTotals(row);
        }

        /** Lets the staff type the final line amount directly; the effective unit price
         *  is back-calculated from it (montant ÷ quantité) so the stored data stays consistent. */
        function onLineAmountChange(input) {
            const row = input.closest('.product-line');
            if (!row) return;
            const quantite = parseInt(row.querySelector('.quantity')?.value || '0', 10);
            const lineAmount = parseFloat(input.value || '0');
            const unitPriceInput = row.querySelector('.unit-price');
            if (unitPriceInput && quantite > 0 && Number.isFinite(lineAmount) && lineAmount >= 0) {
                unitPriceInput.value = (lineAmount / quantite).toFixed(2);
            }
        }

        function updateQuantity(row) {
            const cartons = parseInt(row.querySelector('.cartons').value || '0', 10);
            const units = parseInt(row.querySelector('.product-units').textContent || '0', 10);
            const quantite = cartons > 0 && units > 0 ? cartons * units : '';
            const quantityInput = row.querySelector('.quantity');
            if (quantityInput) quantityInput.value = quantite;
            updateLineTotals(row);
        }

        function updateLineTotals(row) {
            const quantite = parseInt(row.querySelector('.quantity')?.value || '0', 10);
            const unitPrice = parseFloat(row.querySelector('.unit-price')?.value || '0');
            const total = quantite > 0 && unitPrice > 0 ? quantite * unitPrice : 0;
            const lineAmount = row.querySelector('.line-amount');
            if (lineAmount) lineAmount.value = total > 0 ? total.toFixed(2) : '';
        }

        /** Pending lines for إنشاء بيع (staff) — submitted via hidden inputs */
        let staffPendingProducts = [];
        let staffEditingIndex = null;

        function staffDraftLineEl() {
            return document.querySelector('.staff-draft-line');
        }

        function escapeHtmlStaff(s) {
            const t = document.createElement('textarea');
            t.textContent = s == null ? '' : String(s);
            return t.innerHTML;
        }

        function staffBindProductCombobox() {
            const line = staffDraftLineEl();
            const dropdown = document.getElementById('staff_product_combobox_list');
            const wrap = line?.querySelector('.product-combobox-wrap');
            const backdrop = document.getElementById('staff_combobox_backdrop');
            if (!line || !dropdown || !wrap) return;
            const input = line.querySelector('.product-search');
            if (!input) return;
            let suppressClickUntil = 0;

            const closeDropdown = () => {
                dropdown.classList.remove('is-open');
                wrap.classList.remove('is-open');
                line.classList.remove('dropdown-open');
                if (backdrop) backdrop.classList.remove('is-open');
            };

            /** While searching, the field pins to the very top of the screen and the list
             *  fills exactly the space between it and the on-screen keyboard — so the field
             *  is never covered by the list, and the list never hides behind the keyboard. */
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
                return products
                    .filter((p) => {
                        const name = String(p.nom || '').toLowerCase();
                        const ref = String(p.reference || '').toLowerCase();
                        return name.includes(n) || ref.includes(n) || `${name} | مرجع: ${ref}`.toLowerCase().includes(n);
                    })
                    .slice(0, 100);
            };

            const render = () => {
                const list = filterList(input.value);
                if (list.length === 0) {
                    dropdown.innerHTML =
                        '<li class="combobox-noresult" style="cursor:default;color:var(--gray);pointer-events:none">لا نتائج</li>';
                } else {
                    dropdown.innerHTML = list
                        .map(
                            (p) =>
                                `<li role="option" tabindex="0" data-pid="${escapeHtmlStaff(String(p.id))}">${escapeHtmlStaff(p.nom)}</li>`
                        )
                        .join('');
                }
                dropdown.classList.add('is-open');
                wrap.classList.add('is-open');
                line.classList.add('dropdown-open');
                if (backdrop) backdrop.classList.add('is-open');
                positionAboveKeyboard();
            };

            const pickById = (id) => {
                const p = findProductById(id);
                if (!p) return;
                input.value = p.nom;
                syncProductInput(input);
                const row3 = line.querySelector('.nc-pos-row3');
                if (row3) row3.classList.add('nc-hidden');
                closeDropdown();
                input.blur();
                staffOpenProductModal(id);
            };

            input.addEventListener('focus', () => render());
            input.addEventListener('input', () => {
                render();
                syncProductInput(input);
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeDropdown();
            });

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

            dropdown.addEventListener(
                'pointerdown',
                (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                },
                true
            );

            dropdown.addEventListener(
                'click',
                (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                },
                true
            );

            document.addEventListener(
                'pointerdown',
                (e) => {
                    if (wrap.contains(e.target)) return;
                    closeDropdown();
                },
                true
            );

            document.addEventListener(
                'click',
                (e) => {
                    if (Date.now() <= suppressClickUntil) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                    }
                },
                true
            );
        }

        function staffClearDraft() {
            const line = staffDraftLineEl();
            if (!line) return;
            line.querySelector('.product-search').value = '';
            line.querySelector('.product-id').value = '';
            line.querySelector('.cartons').value = '1';
            line.querySelector('.unit-price').value = '';
            const q = line.querySelector('.quantity');
            if (q) q.value = '';
            const la = line.querySelector('.line-amount');
            if (la) la.value = '';
            line.querySelector('.product-units').textContent = '0';
            const marque = line.querySelector('.product-marque');
            if (marque) marque.textContent = '-';
            staffEditingIndex = null;
            const dd = document.getElementById('staff_product_combobox_list');
            const wrap = line.querySelector('.product-combobox-wrap');
            if (dd) dd.classList.remove('is-open');
            if (wrap) wrap.classList.remove('is-open');
            const row3 = line.querySelector('.nc-pos-row3');
            if (row3) row3.classList.add('nc-hidden');
        }

        function staffReadDraftRow() {
            const line = staffDraftLineEl();
            if (!line) return null;
            const productId = parseInt(line.querySelector('.product-id')?.value || '0', 10);
            const cartons = parseInt(line.querySelector('.cartons')?.value || '0', 10);
            const unitPrice = parseFloat(line.querySelector('.unit-price')?.value || '0');
            const searchVal = (line.querySelector('.product-search')?.value || '').trim();
            const product = findProductById(productId);
            if (!product || productId <= 0) {
                return { error: 'اختر منتجاً من القائمة أو البحث بالاسم/المرجع.' };
            }
            if (cartons < 1) {
                return { error: 'أدخل عدد كراتين صالحاً (1 على الأقل).' };
            }
            const availableCartons = staffGetAvailableCartons(productId, staffEditingIndex);
            if (cartons > availableCartons) {
                return {
                    error: 'لا يمكن إضافة المنتج «' + product.reference + '»: متوفر فقط ' + availableCartons + ' كرتون(ات) في المخزون.'
                };
            }
            if (!Number.isFinite(unitPrice) || unitPrice < 0) {
                return { error: 'أدخل سعر وحدة صالحاً.' };
            }
            const units = parseInt(line.querySelector('.product-units')?.textContent || '0', 10);
            const manualQuantity = parseInt(line.querySelector('.quantity')?.value || '0', 10);
            const autoQuantity = cartons > 0 && units > 0 ? cartons * units : 0;
            const quantite = manualQuantity > 0 ? manualQuantity : autoQuantity;
            const montant = quantite > 0 && unitPrice > 0 ? quantite * unitPrice : 0;
            if (quantite <= 0) {
                return { error: 'تعذر حساب الكمية: تحقق من المنتج وعدد الكراتين.' };
            }
            return {
                productId,
                label: searchVal || `${product.nom} | مرجع: ${product.reference}`,
                nom: product.nom,
                reference: product.reference,
                marque: product.marque || '',
                cartons,
                unitPrice,
                quantite,
                montant,
                unitsPerCarton: units
            };
        }

        function staffGetPendingCartonsForProduct(productId, excludeIndex = null) {
            let total = 0;
            staffPendingProducts.forEach((row, index) => {
                if (excludeIndex !== null && index === excludeIndex) return;
                if (Number(row.productId) === Number(productId)) {
                    total += parseInt(row.cartons || '0', 10);
                }
            });
            return total;
        }

        function staffGetAvailableCartons(productId, excludeIndex = null) {
            const product = findProductById(productId);
            if (!product) return 0;
            const stock = parseInt(product.nombre_de_carton || '0', 10);
            const reserved = staffGetPendingCartonsForProduct(productId, excludeIndex);
            return Math.max(0, stock - reserved);
        }

        function staffValidatePendingStockForSubmit() {
            const totals = {};
            staffPendingProducts.forEach((row) => {
                const productId = Number(row.productId);
                if (productId <= 0) return;
                totals[productId] = (totals[productId] || 0) + parseInt(row.cartons || '0', 10);
            });

            for (const [productId, requested] of Object.entries(totals)) {
                const product = findProductById(parseInt(productId, 10));
                if (!product) continue;
                const stock = parseInt(product.nombre_de_carton || '0', 10);
                if (requested > stock) {
                    return 'لا يمكن إنشاء البيع: المنتج «' + product.reference + '» متوفر فقط ' + stock + ' كرتون(ات) في المخزون.';
                }
            }
            return null;
        }

        function staffAddPendingLine() {
            const data = staffReadDraftRow();
            if (data && data.error) {
                alert(data.error);
                return;
            }
            if (staffEditingIndex !== null) {
                staffPendingProducts[staffEditingIndex] = data;
                staffEditingIndex = null;
            } else {
                staffPendingProducts.push(data);
            }
            staffRenderPendingTable();
            staffClearDraft();
        }

        function staffDeletePendingRow(index) {
            staffPendingProducts.splice(index, 1);
            if (staffEditingIndex !== null) {
                if (staffEditingIndex === index) staffEditingIndex = null;
                else if (staffEditingIndex > index) staffEditingIndex -= 1;
            }
            staffRenderPendingTable();
        }

        function staffEditPendingRow(index) {
            const row = staffPendingProducts[index];
            if (!row) return;
            const line = staffDraftLineEl();
            if (!line) return;
            staffEditingIndex = index;
            line.querySelector('.product-search').value = row.label;
            line.querySelector('.product-id').value = String(row.productId);
            const p = findProductById(row.productId);
            if (p) {
                line.querySelector('.product-units').textContent = p.unité_par_carton;
                const marque = line.querySelector('.product-marque');
                if (marque) marque.textContent = p.marque || '-';
            }
            line.querySelector('.cartons').value = String(row.cartons);
            line.querySelector('.unit-price').value = Number(row.unitPrice).toFixed(2);
            const row3 = line.querySelector('.nc-pos-row3');
            if (row3) row3.classList.remove('nc-hidden');
            updateQuantity(line);
            updateLineTotals(line);
        }

        function staffRenderPendingTable() {
            const tbody = document.querySelector('#staff_pending_tbody');
            const empty = document.querySelector('#staff_pending_empty');
            staffUpdateLiveTotal();
            if (!tbody) return;
            tbody.innerHTML = '';
            if (staffPendingProducts.length === 0) {
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';
            staffPendingProducts.forEach((r, i) => {
                const tr = document.createElement('tr');
                if (r.existing) {
                    tr.style.opacity = '0.7';
                    tr.innerHTML = `
                        <td>${escapeHtmlStaff(r.nom)}</td>
                        <td>${escapeHtmlStaff(r.reference)}</td>
                        <td>${r.cartons}</td>
                        <td>${r.quantite}</td>
                        <td>${Number(r.unitPrice).toFixed(2)}</td>
                        <td>${Number(r.montant).toFixed(2)}</td>
                        <td class="pending-actions"><span style="font-size:0.7rem;color:var(--gray);">موجود مسبقاً</span></td>`;
                } else {
                    tr.innerHTML = `
                        <td>${escapeHtmlStaff(r.nom)}</td>
                        <td>${escapeHtmlStaff(r.reference)}</td>
                        <td>${r.cartons}</td>
                        <td>${r.quantite}</td>
                        <td>${Number(r.unitPrice).toFixed(2)}</td>
                        <td>${Number(r.montant).toFixed(2)}</td>
                        <td class="pending-actions">
                            <button type="button" class="btn btn-warning" data-idx="${i}">✏️ تعديل</button>
                            <button type="button" class="btn btn-danger" data-del="${i}">🗑️ حذف</button>
                        </td>`;
                }
                tbody.appendChild(tr);
            });
            tbody.querySelectorAll('button[data-idx]').forEach((btn) => {
                btn.addEventListener('click', () => staffEditPendingRow(parseInt(btn.getAttribute('data-idx'), 10)));
            });
            tbody.querySelectorAll('button[data-del]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (confirm('حذف هذا المنتج من القائمة؟')) {
                        staffDeletePendingRow(parseInt(btn.getAttribute('data-del'), 10));
                    }
                });
            });
        }

        function staffFillHiddenInputsForSubmit() {
            const box = document.getElementById('staff-sale-hidden-inputs');
            if (!box) return;
            box.innerHTML = '';
            staffPendingProducts.forEach((r) => {
                if (r.existing) return; // already saved on this sale — never resubmit it
                const pid = document.createElement('input');
                pid.type = 'hidden';
                pid.name = 'product_id[]';
                pid.value = String(r.productId);
                box.appendChild(pid);
                const cart = document.createElement('input');
                cart.type = 'hidden';
                cart.name = 'nombre_de_carton[]';
                cart.value = String(r.cartons);
                box.appendChild(cart);
                const pt = document.createElement('input');
                pt.type = 'hidden';
                pt.name = 'price_type[]';
                pt.value = 'custom';
                box.appendChild(pt);
                const pu = document.createElement('input');
                pu.type = 'hidden';
                pu.name = 'prix_unite[]';
                pu.value = Number(r.unitPrice).toFixed(2);
                box.appendChild(pu);
            });
        }

        function staffInitCreateSaleForm() {
            staffBindProductCombobox();
            const form = document.getElementById('staff-sale-form');
            if (!form) return;
            form.addEventListener('submit', (e) => {
                if (staffPendingProducts.length === 0) {
                    const data = staffReadDraftRow();
                    if (data && data.error) {
                        e.preventDefault();
                        alert(data.error);
                        return;
                    }
                    if (data && !data.error) {
                        staffPendingProducts.push(data);
                        staffRenderPendingTable();
                        staffClearDraft();
                    }
                }
                if (staffPendingProducts.length === 0) {
                    e.preventDefault();
                    alert('أضف منتجاً واحداً على الأقل باستخدام «إضافة منتج» (أو املأ السطر ثم أرسل).');
                    return;
                }
                const stockError = staffValidatePendingStockForSubmit();
                if (stockError) {
                    e.preventDefault();
                    alert(stockError);
                    return;
                }
                const cid = document.getElementById('client_id');
                if (!cid || !cid.value) {
                    e.preventDefault();
                    alert('اختر عميلاً صالحاً من القائمة.');
                    return;
                }
                staffFillHiddenInputsForSubmit();
            });
            staffClearDraft();
            staffRenderPendingTable();
        }

        function createProductLine(isStaff = false) {
            const line = document.createElement('div');
            line.className = 'product-line';
            if (isStaff) {
                line.innerHTML = `
                    <div class="form-group">
                        <label>منتج</label>
                        <input type="text" name="product_search[]" list="productOptions" class="product-search" placeholder="بحث بالاسم أو المرجع" autocomplete="off" required oninput="syncProductInput(this)">
                        <input type="hidden" name="product_id[]" class="product-id">
                        <input type="hidden" name="price_type[]" value="custom">
                        <div style="margin-top:6px; font-size:0.85rem; color:var(--gray);">وحدة/كرتون: <span class="product-units">0</span> | علامة: <span class="product-marque">-</span></div>
                    </div>
                    <div class="form-group">
                        <label>سعر الوحدة (قابل للتعديل)</label>
                        <input type="number" step="0.01" name="prix_unite[]" class="unit-price" required oninput="onPriceChange(this)">
                    </div>
                    <div class="form-group">
                        <label>عدد الكراتين</label>
                        <input type="number" name="nombre_de_carton[]" class="cartons" min="1" value="1" required oninput="updateQuantity(this.closest('.product-line'))">
                    </div>
                    <div class="form-group">
                        <label>الكمية الإجمالية (وحدات)</label>
                        <input type="number" class="quantity" readonly>
                    </div>
                    <div class="form-group">
                        <label>مبلغ السطر</label>
                        <input type="number" step="0.01" class="line-amount" readonly>
                    </div>
                    <button type="button" class="btn btn-danger remove-product-line" onclick="removeProductLine(this)">🗑️ حذف</button>
                `;
            } else {
                line.innerHTML = `
                    <div class="form-group">
                        <label>منتج</label>
                        <input type="text" name="product_search[]" list="productOptions" class="product-search" placeholder="بحث بالاسم أو المرجع" autocomplete="off" required oninput="syncProductInput(this)">
                        <input type="hidden" name="product_id[]" class="product-id">
                        <input type="hidden" name="price_type[]" value="detail">
                        <div style="margin-top:6px; font-size:0.85rem; color:var(--gray);">وحدة/كرتون: <span class="product-units">0</span> | علامة: <span class="product-marque">-</span></div>
                    </div>
                    <div class="form-group">
                        <label>سعر الوحدة</label>
                        <input type="number" step="0.01" name="prix_unite[]" class="unit-price" readonly required>
                    </div>
                    <div class="form-group">
                        <label>عدد الكراتين</label>
                        <input type="number" name="nombre_de_carton[]" class="cartons" min="1" value="1" required oninput="updateQuantity(this.closest('.product-line'))">
                    </div>
                    <div class="form-group">
                        <label>الكمية الإجمالية (وحدات)</label>
                        <input type="number" class="quantity" readonly>
                    </div>
                    <div class="form-group">
                        <label>مبلغ السطر</label>
                        <input type="number" step="0.01" class="line-amount" readonly>
                    </div>
                    <button type="button" class="btn btn-danger remove-product-line" onclick="removeProductLine(this)">🗑️ حذف</button>
                `;
            }
            return line;
        }

        function addProductLine(isStaff = false, containerSelector = null) {
            const container = containerSelector ? document.querySelector(containerSelector) : (isStaff ? document.querySelector('#staff-product-lines') : document.querySelector('#client-product-lines'));
            if (!container) return;
            const line = createProductLine(isStaff);
            container.appendChild(line);
            updateQuantity(line);
        }

        function removeProductLine(button) {
            const container = button.closest('.product-line').parentNode;
            if (container.querySelectorAll('.product-line').length === 1) {
                const line = button.closest('.product-line');
                line.querySelector('.product-search').value = '';
                line.querySelector('.product-id').value = '';
                line.querySelector('.cartons').value = '1';
                line.querySelector('.product-units').textContent = '0';
                const marque = line.querySelector('.product-marque');
                if (marque) marque.textContent = '-';
                if (line.querySelector('.quantity')) {
                    line.querySelector('.quantity').value = '';
                }
                return;
            }
            button.closest('.product-line').remove();
        }

        function openEditItemForm(button) {
            const card = document.querySelector('#edit-sale-item-card');
            if (!card) return;
            card.style.display = 'block';

            const productId = button.dataset.productId;
            const product = findProductById(productId);
            const productSearch = card.querySelector('#edit_product_search');
            const productIdInput = card.querySelector('#edit_product_id');
            const unitPriceInput = card.querySelector('#edit_prix_unite');
            const cartonsInput = card.querySelector('#edit_nombre_de_carton');
            const quantityInput = card.querySelector('#edit_quantity');
            const itemIdInput = card.querySelector('#edit_sale_item_id');
            const unitsLabel = card.querySelector('.product-units');
            const marqueLabel = card.querySelector('.product-marque');

            productIdInput.value = productId;
            if (product) {
                productSearch.value = `${product.nom} | مرجع: ${product.reference}`;
                unitsLabel.textContent = product.unité_par_carton;
                if (marqueLabel) marqueLabel.textContent = product.marque || '-';
            }
            unitPriceInput.value = button.dataset.unitPrice || '';
            cartonsInput.value = button.dataset.cartons || '1';
            quantityInput.value = button.dataset.quantity || '';
            itemIdInput.value = button.dataset.itemId || '';
        }

        function syncClientInput(input) {
            const value = input.value.trim();
            const hidden = document.querySelector('#client_id');
            const form = document.querySelector('#staff-sale-form');
            if (!hidden) return;
            if (!value) {
                hidden.value = '';
                if (form) form.dataset.clientType = 'detail';
                return;
            }

            const normalized = value.toLowerCase();
            const parsedId =
                (normalized.match(/^id\s*[:#]?\s*(\d+)$/i)?.[1]) ||
                (normalized.match(/\|\s*id\s*[:#]?\s*(\d+)\s*$/i)?.[1]) ||
                (normalized.match(/^(\d+)$/)?.[1]) ||
                null;

            const match = clients.find(client => {
                const name = String(client.nom || '').toLowerCase();
                const id = String(client.id ?? '').toLowerCase();
                const labelA = `${name} | id: ${id}`; // current datalist format (" | ID: 123")
                const labelB = `${name} | id:${id}`;
                const labelC = `id:${id}`;
                const labelD = `id: ${id}`;

                return (parsedId !== null && String(client.id) === String(parsedId))
                    || name === normalized
                    || labelA === normalized
                    || labelB === normalized
                    || labelC === normalized
                    || labelD === normalized
                    || name.includes(normalized);
            });

            if (match) {
                hidden.value = match.id;
                if (form) form.dataset.clientType = match.type || 'detail';
            } else {
                hidden.value = '';
                if (form) form.dataset.clientType = 'detail';
            }
        }

        /* ===================== NC MOBILE SCREENS ===================== */
        function showScreen(name) {
            document.querySelectorAll('.nc-screen').forEach((el) => el.classList.remove('active'));
            const el = document.getElementById('screen-' + name);
            if (el) el.classList.add('active');
            try { localStorage.setItem('nc_sales_screen', name); } catch (e) { /* ignore */ }
            window.scrollTo(0, 0);
        }

        function initNcScreens() {
            let target = window.__ncInitialScreen || 'menu';
            if (target === 'menu') {
                try {
                    const saved = localStorage.getItem('nc_sales_screen');
                    if (saved && document.getElementById('screen-' + saved)) target = saved;
                } catch (e) { /* ignore */ }
            }
            if (!document.getElementById('screen-' + target)) target = 'menu';
            showScreen(target);
        }

        /* ---- Live running total + product count on the create-sale header ---- */
        function staffUpdateLiveTotal() {
            const el = document.getElementById('staff_live_total');
            const countEl = document.getElementById('staff_live_count');
            const total = staffPendingProducts.reduce((sum, row) => sum + (Number(row.montant) || 0), 0);
            if (el) el.textContent = total.toFixed(2);
            if (countEl) countEl.textContent = String(staffPendingProducts.length);
        }

        /* ---- Product entry modal: shown right after picking a product, captures price + cartons ---- */
        let staffModalProduct = null;

        function staffOpenProductModal(productId) {
            const p = findProductById(productId);
            if (!p) return;
            staffModalProduct = p;
            const available = staffGetAvailableCartons(p.id, staffEditingIndex);
            document.getElementById('pm_title').textContent = p.nom;
            document.getElementById('pm_stock_info').textContent =
                `المتوفر: ${available} كرتون × ${p.unité_par_carton || 0} وحدة/كرتون`;

            const form = document.getElementById('staff-sale-form');
            const clientTier = (form && form.dataset.clientType) || 'detail';
            document.getElementById('pm_price').value = getPriceByType(p, clientTier).toFixed(2);
            document.getElementById('pm_cartons').value = '';
            document.getElementById('staff-product-modal').style.display = 'flex';
        }

        function staffCloseProductModal() {
            const modal = document.getElementById('staff-product-modal');
            if (modal) modal.style.display = 'none';
            staffModalProduct = null;
            // The pick was cancelled: clear whatever product was selected in the draft line.
            const line = staffDraftLineEl();
            if (line) {
                const search = line.querySelector('.product-search');
                const hidden = line.querySelector('.product-id');
                if (search) search.value = '';
                if (hidden) hidden.value = '';
            }
        }

        function staffSaveProductModal() {
            if (!staffModalProduct) return;
            const p = staffModalProduct;
            const cartonsRaw = document.getElementById('pm_cartons').value.trim();
            const cartons = cartonsRaw === '' ? 1 : parseInt(cartonsRaw, 10);
            const unitPrice = parseFloat(document.getElementById('pm_price').value || '0');

            if (!Number.isFinite(cartons) || cartons <= 0) {
                alert('أدخل عدد كراتين صحيح.');
                return;
            }
            if (!Number.isFinite(unitPrice) || unitPrice < 0) {
                alert('أدخل سعراً صحيحاً.');
                return;
            }
            const available = staffGetAvailableCartons(p.id, staffEditingIndex);
            if (cartons > available) {
                alert(`الكمية المتوفرة من "${p.nom}" هي ${available} كرتون فقط.`);
                return;
            }

            const quantite = cartons * Number(p.unité_par_carton || 0);
            const row = {
                productId: p.id,
                nom: p.nom,
                reference: p.reference,
                cartons,
                unitPrice,
                quantite,
                montant: quantite * unitPrice
            };

            if (staffEditingIndex !== null && staffEditingIndex >= 0 && staffEditingIndex < staffPendingProducts.length) {
                staffPendingProducts[staffEditingIndex] = row;
            } else {
                staffPendingProducts.push(row);
            }
            staffEditingIndex = null;

            staffRenderPendingTable();
            staffClearDraft();
            const modal = document.getElementById('staff-product-modal');
            if (modal) modal.style.display = 'none';
            staffModalProduct = null;
        }

        /* ---- Confirm modal: remise / versement / totals preview before saving ---- */
        function staffOpenConfirmModal() {
            if (staffPendingProducts.length === 0) {
                const data = staffReadDraftRow();
                if (data && data.error) {
                    alert(data.error);
                    return;
                }
                if (data && !data.error) {
                    staffPendingProducts.push(data);
                    staffRenderPendingTable();
                    staffClearDraft();
                }
            }
            if (staffPendingProducts.length === 0) {
                alert('أضف منتجاً واحداً على الأقل قبل التأكيد.');
                return;
            }
            const stockError = staffValidatePendingStockForSubmit();
            if (stockError) {
                alert(stockError);
                return;
            }
            const cid = document.getElementById('client_id');
            const cSearch = document.getElementById('client_search');
            if (!cid || !cid.value) {
                alert('اختر عميلاً صالحاً من القائمة.');
                return;
            }
            const client = clients.find((c) => Number(c.id) === Number(cid.value));
            document.getElementById('cm_client_name').textContent = client ? client.nom : (cSearch ? cSearch.value : '-');
            document.getElementById('cm_credit_ancien').dataset.value = client ? Number(client.credit || 0) : 0;
            document.getElementById('cm_remise').value = '0';
            document.getElementById('cm_versement').value = '0';
            staffRecalcConfirmModal();
            document.getElementById('staff-confirm-modal').style.display = 'flex';
        }

        function staffCloseConfirmModal() {
            const modal = document.getElementById('staff-confirm-modal');
            if (modal) modal.style.display = 'none';
        }

        /* ---- Cancel the sale currently being built (clears everything, back to menu) ---- */
        function staffCancelCurrentSale() {
            const hasItems = staffPendingProducts.some((r) => !r.existing);
            const clientSearch = document.getElementById('client_search');
            const hasClient = !window.__addToSaleId && clientSearch && clientSearch.value.trim() !== '';
            if ((hasItems || hasClient) && !confirm('هل تريد إلغاء؟ سيتم حذف كل المنتجات المضافة.')) {
                return;
            }
            if (window.__addToSaleId) {
                location.href = 'sales.php?sale_id=' + window.__addToSaleId;
                return;
            }
            staffPendingProducts = [];
            staffEditingIndex = null;
            staffClearDraft();
            staffRenderPendingTable();
            if (clientSearch) clientSearch.value = '';
            const clientId = document.getElementById('client_id');
            if (clientId) clientId.value = '';
            const remiseInput = document.getElementById('staff_remise_input');
            const versementInput = document.getElementById('staff_versement_input');
            if (remiseInput) remiseInput.value = '0';
            if (versementInput) versementInput.value = '0';
            staffCloseConfirmModal();
            showScreen('menu');
        }

        /* ---- Add-to-existing-sale mode: skip remise/versement (already set on the sale) and submit directly ---- */
        function staffSubmitAddToSale() {
            if (staffPendingProducts.length === 0) {
                const data = staffReadDraftRow();
                if (data && data.error) {
                    alert(data.error);
                    return;
                }
                if (data && !data.error) {
                    staffPendingProducts.push(data);
                    staffRenderPendingTable();
                    staffClearDraft();
                }
            }
            const newRows = staffPendingProducts.filter((r) => !r.existing);
            if (newRows.length === 0) {
                alert('أضف منتجاً واحداً على الأقل قبل الحفظ.');
                return;
            }
            const stockError = staffValidatePendingStockForSubmit();
            if (stockError) {
                alert(stockError);
                return;
            }
            staffFillHiddenInputsForSubmit();
            const form = document.getElementById('staff-sale-form');
            if (form) form.requestSubmit();
        }

        function staffRecalcConfirmModal() {
            const montant = staffPendingProducts.reduce((sum, row) => sum + (Number(row.montant) || 0), 0);
            const creditAncienEl = document.getElementById('cm_credit_ancien');
            const creditAncien = Number(creditAncienEl.dataset.value || 0);
            const remise = Math.max(0, parseFloat(document.getElementById('cm_remise').value || '0'));
            const versement = Math.max(0, parseFloat(document.getElementById('cm_versement').value || '0'));
            const totalActuel = montant + creditAncien - remise;
            const creditActuel = totalActuel - versement;
            document.getElementById('cm_montant').textContent = montant.toFixed(2) + ' دج';
            creditAncienEl.textContent = creditAncien.toFixed(2) + ' دج';
            document.getElementById('cm_total_actuel').textContent = totalActuel.toFixed(2) + ' دج';
            document.getElementById('cm_credit_actuel').textContent = creditActuel.toFixed(2) + ' دج';
        }

        function staffSubmitConfirmedSale() {
            const remise = Math.max(0, parseFloat(document.getElementById('cm_remise').value || '0'));
            const versement = Math.max(0, parseFloat(document.getElementById('cm_versement').value || '0'));
            document.getElementById('staff_remise_input').value = remise.toFixed(2);
            document.getElementById('staff_versement_input').value = versement.toFixed(2);
            staffCloseConfirmModal();
            const form = document.getElementById('staff-sale-form');
            if (form) form.requestSubmit();
        }

        /* ===================== PRODUCTS RETURN (إرجاع المنتجات) =====================
           Mirrors the create-sale POS flow above, but with its own state/ids so the two
           screens never interfere with each other, and no remise/versement/confirm modal
           (a return has no negotiation step — it's recorded directly). */
        let returnPendingProducts = [];
        let returnEditingIndex = null;
        let returnModalProduct = null;

        function returnDraftLineEl() {
            return document.querySelector('.return-draft-line');
        }

        function returnBindProductCombobox() {
            const line = returnDraftLineEl();
            const dropdown = document.getElementById('return_product_combobox_list');
            const wrap = line?.querySelector('.product-combobox-wrap');
            const backdrop = document.getElementById('return_combobox_backdrop');
            if (!line || !dropdown || !wrap) return;
            const input = line.querySelector('.product-search');
            if (!input) return;
            let suppressClickUntil = 0;

            const closeDropdown = () => {
                dropdown.classList.remove('is-open');
                wrap.classList.remove('is-open');
                line.classList.remove('dropdown-open');
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
                return products
                    .filter((p) => {
                        const name = String(p.nom || '').toLowerCase();
                        const ref = String(p.reference || '').toLowerCase();
                        return name.includes(n) || ref.includes(n) || `${name} | مرجع: ${ref}`.toLowerCase().includes(n);
                    })
                    .slice(0, 100);
            };

            const render = () => {
                const list = filterList(input.value);
                if (list.length === 0) {
                    dropdown.innerHTML =
                        '<li class="combobox-noresult" style="cursor:default;color:var(--gray);pointer-events:none">لا نتائج</li>';
                } else {
                    dropdown.innerHTML = list
                        .map(
                            (p) =>
                                `<li role="option" tabindex="0" data-pid="${escapeHtmlStaff(String(p.id))}">${escapeHtmlStaff(p.nom)}</li>`
                        )
                        .join('');
                }
                dropdown.classList.add('is-open');
                wrap.classList.add('is-open');
                line.classList.add('dropdown-open');
                if (backdrop) backdrop.classList.add('is-open');
                positionAboveKeyboard();
            };

            const pickById = (id) => {
                const p = findProductById(id);
                if (!p) return;
                input.value = p.nom;
                syncProductInput(input);
                const row3 = line.querySelector('.nc-pos-row3');
                if (row3) row3.classList.add('nc-hidden');
                closeDropdown();
                input.blur();
                returnOpenProductModal(id);
            };

            input.addEventListener('focus', () => render());
            input.addEventListener('input', () => {
                render();
                syncProductInput(input);
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeDropdown();
            });

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

            dropdown.addEventListener(
                'pointerdown',
                (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                },
                true
            );

            dropdown.addEventListener(
                'click',
                (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                },
                true
            );

            document.addEventListener(
                'pointerdown',
                (e) => {
                    if (wrap.contains(e.target)) return;
                    closeDropdown();
                },
                true
            );

            document.addEventListener(
                'click',
                (e) => {
                    if (Date.now() <= suppressClickUntil) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
                    }
                },
                true
            );
        }

        function returnClearDraft() {
            const line = returnDraftLineEl();
            if (!line) return;
            line.querySelector('.product-search').value = '';
            line.querySelector('.product-id').value = '';
            line.querySelector('.cartons').value = '1';
            line.querySelector('.unit-price').value = '';
            const q = line.querySelector('.quantity');
            if (q) q.value = '';
            const la = line.querySelector('.line-amount');
            if (la) la.value = '';
            line.querySelector('.product-units').textContent = '0';
            const marque = line.querySelector('.product-marque');
            if (marque) marque.textContent = '-';
            returnEditingIndex = null;
            const dd = document.getElementById('return_product_combobox_list');
            const wrap = line.querySelector('.product-combobox-wrap');
            if (dd) dd.classList.remove('is-open');
            if (wrap) wrap.classList.remove('is-open');
            const row3 = line.querySelector('.nc-pos-row3');
            if (row3) row3.classList.add('nc-hidden');
        }

        function returnGetPendingCartonsForProduct(productId, excludeIndex = null) {
            let total = 0;
            returnPendingProducts.forEach((row, index) => {
                if (excludeIndex !== null && index === excludeIndex) return;
                if (Number(row.productId) === Number(productId)) {
                    total += parseInt(row.cartons || '0', 10);
                }
            });
            return total;
        }

        // Returns don't have a stock ceiling (products are coming back INTO the warehouse),
        // so "available" here is only used for informational display, not a hard limit.
        function returnGetReservedCartons(productId, excludeIndex = null) {
            return returnGetPendingCartonsForProduct(productId, excludeIndex);
        }

        function returnAddPendingLine() {
            const line = returnDraftLineEl();
            if (!line) return;
            const productId = parseInt(line.querySelector('.product-id')?.value || '0', 10);
            const cartons = parseInt(line.querySelector('.cartons')?.value || '0', 10);
            const unitPrice = parseFloat(line.querySelector('.unit-price')?.value || '0');
            const product = findProductById(productId);
            if (!product || productId <= 0) {
                alert('اختر منتجاً من القائمة أو البحث بالاسم/المرجع.');
                return;
            }
            if (cartons < 1) {
                alert('أدخل عدد كراتين صالحاً (1 على الأقل).');
                return;
            }
            if (!Number.isFinite(unitPrice) || unitPrice < 0) {
                alert('أدخل سعر وحدة صالحاً.');
                return;
            }
            const units = parseInt(line.querySelector('.product-units')?.textContent || '0', 10);
            const quantite = cartons * units;
            const row = {
                productId,
                nom: product.nom,
                reference: product.reference,
                cartons,
                unitPrice,
                quantite,
                montant: quantite * unitPrice
            };
            if (returnEditingIndex !== null) {
                returnPendingProducts[returnEditingIndex] = row;
                returnEditingIndex = null;
            } else {
                returnPendingProducts.push(row);
            }
            returnRenderPendingTable();
            returnClearDraft();
        }

        function returnDeletePendingRow(index) {
            returnPendingProducts.splice(index, 1);
            if (returnEditingIndex !== null) {
                if (returnEditingIndex === index) returnEditingIndex = null;
                else if (returnEditingIndex > index) returnEditingIndex -= 1;
            }
            returnRenderPendingTable();
        }

        function returnEditPendingRow(index) {
            const row = returnPendingProducts[index];
            if (!row) return;
            const line = returnDraftLineEl();
            if (!line) return;
            returnEditingIndex = index;
            line.querySelector('.product-search').value = row.nom;
            line.querySelector('.product-id').value = String(row.productId);
            const p = findProductById(row.productId);
            if (p) {
                line.querySelector('.product-units').textContent = p.unité_par_carton;
                const marque = line.querySelector('.product-marque');
                if (marque) marque.textContent = p.marque || '-';
            }
            line.querySelector('.cartons').value = String(row.cartons);
            line.querySelector('.unit-price').value = Number(row.unitPrice).toFixed(2);
            const row3 = line.querySelector('.nc-pos-row3');
            if (row3) row3.classList.remove('nc-hidden');
            updateQuantity(line);
            updateLineTotals(line);
        }

        function returnRenderPendingTable() {
            const tbody = document.querySelector('#return_pending_tbody');
            const empty = document.querySelector('#return_pending_empty');
            returnUpdateLiveTotal();
            if (!tbody) return;
            tbody.innerHTML = '';
            if (returnPendingProducts.length === 0) {
                if (empty) empty.style.display = 'block';
                return;
            }
            if (empty) empty.style.display = 'none';
            returnPendingProducts.forEach((r, i) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${escapeHtmlStaff(r.nom)}</td>
                    <td>${escapeHtmlStaff(r.reference)}</td>
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
                btn.addEventListener('click', () => returnEditPendingRow(parseInt(btn.getAttribute('data-idx'), 10)));
            });
            tbody.querySelectorAll('button[data-del]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (confirm('حذف هذا المنتج من القائمة؟')) {
                        returnDeletePendingRow(parseInt(btn.getAttribute('data-del'), 10));
                    }
                });
            });
        }

        function returnFillHiddenInputsForSubmit() {
            const box = document.getElementById('return-hidden-inputs');
            if (!box) return;
            box.innerHTML = '';
            returnPendingProducts.forEach((r) => {
                const pid = document.createElement('input');
                pid.type = 'hidden';
                pid.name = 'product_id[]';
                pid.value = String(r.productId);
                box.appendChild(pid);
                const cart = document.createElement('input');
                cart.type = 'hidden';
                cart.name = 'nombre_de_carton[]';
                cart.value = String(r.cartons);
                box.appendChild(cart);
                const pu = document.createElement('input');
                pu.type = 'hidden';
                pu.name = 'prix_unite[]';
                pu.value = Number(r.unitPrice).toFixed(2);
                box.appendChild(pu);
            });
        }

        function returnUpdateLiveTotal() {
            const el = document.getElementById('return_live_total');
            const countEl = document.getElementById('return_live_count');
            const total = returnPendingProducts.reduce((sum, row) => sum + (Number(row.montant) || 0), 0);
            if (el) el.textContent = total.toFixed(2);
            if (countEl) countEl.textContent = String(returnPendingProducts.length);
        }

        function returnOpenProductModal(productId) {
            const p = findProductById(productId);
            if (!p) return;
            returnModalProduct = p;
            const reserved = returnGetReservedCartons(p.id, returnEditingIndex);
            document.getElementById('rpm_title').textContent = p.nom;
            document.getElementById('rpm_stock_info').textContent =
                `المخزون الحالي: ${p.nombre_de_carton || 0} كرتون × ${p.unité_par_carton || 0} وحدة/كرتون` +
                (reserved > 0 ? ` (تمت إضافة ${reserved} في هذه العملية)` : '');
            const returnClientId = document.getElementById('return_client_id')?.value;
            const returnClient = returnClientId ? clients.find((c) => Number(c.id) === Number(returnClientId)) : null;
            const returnClientTier = returnClient ? (returnClient.type || 'detail') : 'detail';
            document.getElementById('rpm_price').value = getPriceByType(p, returnClientTier).toFixed(2);
            document.getElementById('rpm_cartons').value = '';
            document.getElementById('return-product-modal').style.display = 'flex';
        }

        function returnCloseProductModal() {
            const modal = document.getElementById('return-product-modal');
            if (modal) modal.style.display = 'none';
            returnModalProduct = null;
            const line = returnDraftLineEl();
            if (line) {
                const search = line.querySelector('.product-search');
                const hidden = line.querySelector('.product-id');
                if (search) search.value = '';
                if (hidden) hidden.value = '';
            }
        }

        function returnSaveProductModal() {
            if (!returnModalProduct) return;
            const p = returnModalProduct;
            const cartonsRaw = document.getElementById('rpm_cartons').value.trim();
            const cartons = cartonsRaw === '' ? 1 : parseInt(cartonsRaw, 10);
            const unitPrice = parseFloat(document.getElementById('rpm_price').value || '0');

            if (!Number.isFinite(cartons) || cartons <= 0) {
                alert('أدخل عدد كراتين صحيح.');
                return;
            }
            if (!Number.isFinite(unitPrice) || unitPrice < 0) {
                alert('أدخل سعراً صحيحاً.');
                return;
            }

            const quantite = cartons * Number(p.unité_par_carton || 0);
            const row = {
                productId: p.id,
                nom: p.nom,
                reference: p.reference,
                cartons,
                unitPrice,
                quantite,
                montant: quantite * unitPrice
            };

            if (returnEditingIndex !== null && returnEditingIndex >= 0 && returnEditingIndex < returnPendingProducts.length) {
                returnPendingProducts[returnEditingIndex] = row;
            } else {
                returnPendingProducts.push(row);
            }
            returnEditingIndex = null;

            returnRenderPendingTable();
            returnClearDraft();
            const modal = document.getElementById('return-product-modal');
            if (modal) modal.style.display = 'none';
            returnModalProduct = null;
        }

        function syncReturnClientInput(input) {
            const value = input.value.trim();
            const hidden = document.getElementById('return_client_id');
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
            const match = clients.find((client) => {
                const name = String(client.nom || '').toLowerCase();
                const id = String(client.id ?? '').toLowerCase();
                const labelA = `${name} | id: ${id}`;
                return (
                    (parsedId !== null && String(client.id) === String(parsedId)) ||
                    name === normalized ||
                    labelA === normalized ||
                    name.includes(normalized)
                );
            });
            hidden.value = match ? match.id : '';
        }

        function returnInitForm() {
            returnBindProductCombobox();
            returnClearDraft();
            returnRenderPendingTable();
        }

        function returnCancelCurrent() {
            const hasItems = returnPendingProducts.length > 0;
            const clientSearch = document.getElementById('return_client_search');
            const hasClient = clientSearch && clientSearch.value.trim() !== '';
            if ((hasItems || hasClient) && !confirm('هل تريد إلغاء؟ سيتم حذف كل المنتجات المضافة.')) {
                return;
            }
            returnPendingProducts = [];
            returnEditingIndex = null;
            returnClearDraft();
            returnRenderPendingTable();
            if (clientSearch) clientSearch.value = '';
            const clientId = document.getElementById('return_client_id');
            if (clientId) clientId.value = '';
            showScreen('menu');
        }

        function returnSubmitOperation() {
            if (returnPendingProducts.length === 0) {
                alert('أضف منتجاً واحداً على الأقل قبل الحفظ.');
                return;
            }
            const cid = document.getElementById('return_client_id');
            if (!cid || !cid.value) {
                alert('اختر عميلاً صالحاً من القائمة.');
                return;
            }
            returnFillHiddenInputsForSubmit();
            const form = document.getElementById('staff-return-form');
            if (form) form.requestSubmit();
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (document.querySelector('#client-product-lines')) {
                addProductLine(false);
            }
            if (document.getElementById('staff-sale-form')) {
                staffInitCreateSaleForm();
                if (window.__addToSaleId && window.__addToSaleClient) {
                    const clientSearch = document.getElementById('client_search');
                    const clientId = document.getElementById('client_id');
                    const form = document.getElementById('staff-sale-form');
                    if (clientSearch) clientSearch.value = window.__addToSaleClient.nom;
                    if (clientId) clientId.value = window.__addToSaleClient.id;
                    if (form) form.dataset.clientType = window.__addToSaleClient.type || 'detail';
                    if (Array.isArray(window.__addToSaleExistingItems) && window.__addToSaleExistingItems.length) {
                        staffPendingProducts = window.__addToSaleExistingItems.slice();
                        staffRenderPendingTable();
                    }
                }
            }
            if (document.getElementById('staff-return-form')) {
                returnInitForm();
            }
            initNcScreens();
        });
    </script>
</head>
<body class="nc-app-body">

    <header>
        <?= $is_client ? '📦 طلباتي' : '📋 المبيعات' ?>
    </header>

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
                <?php if ($is_client || $is_admin_user): ?>
                <button type="button" class="nc-menu-btn nc-menu-btn--create" onclick="showScreen('create')">
                    <span class="nc-menu-btn-icon"><?= $is_client ? '🛒' : '➕' ?></span>
                    <span class="nc-menu-btn-label"><?= $is_client ? 'طلب جديد' : 'إنشاء بيع' ?></span>
                </button>
                <?php endif; ?>
                <button type="button" class="nc-menu-btn nc-menu-btn--list" onclick="showScreen('list')">
                    <span class="nc-menu-btn-icon">📋</span>
                    <span class="nc-menu-btn-label"><?= $is_client ? 'طلباتي' : 'المبيعات' ?></span>
                </button>
                <?php if (!$is_client && $is_admin_user): ?>
                <button type="button" class="nc-menu-btn nc-menu-btn--return" onclick="showScreen('return')">
                    <span class="nc-menu-btn-icon">↩️</span>
                    <span class="nc-menu-btn-label">إرجاع المنتجات</span>
                </button>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>🗓️ مبيعات اليوم</h2>
                <?php if (count($today_sales) === 0): ?>
                    <p>لا توجد مبيعات اليوم بعد.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>الوقت</th>
                                    <?php if (!$is_client): ?><th>العميل</th><?php endif; ?>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_sales as $sale): ?>
                                    <tr>
                                        <td>#<?= $sale['id'] ?></td>
                                        <td><?= $sale['time'] ?></td>
                                        <?php if (!$is_client): ?><td><?= htmlspecialchars($sale['client_nom'] ?? '-') ?></td><?php endif; ?>
                                        <td><?= number_format($sale['montant'], 2) ?> دج</td>
                                        <td><span class="status <?= $sale['status'] ?>"><?= ucfirst($sale['status']) ?></span></td>
                                        <td class="actions">
                                            <a class="btn btn-primary" href="sales.php?sale_id=<?= $sale['id'] ?>" style="padding:0.375rem 0.75rem; font-size:0.75rem;">📋 تفاصيل</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($sale_detail): ?>
        <div id="screen-detail" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('list')">→ رجوع إلى القائمة</button>
            </div>
            <div class="card">
                <h2>📄 تفاصيل البيع #<?= $sale_detail['id'] ?></h2>
                <p><strong>العميل:</strong> <?= htmlspecialchars($sale_detail['client_nom']) ?></p>
                <p><strong>التاريخ:</strong> <?= $sale_detail['time'] ?></p>
                <p><strong>الإجمالي:</strong> <?= number_format($sale_detail['montant'], 2) ?> دج</p>
                <p><strong>دين العميل:</strong> <?= number_format($sale_detail['credit'] ?? 0, 2) ?> دج</p>
                <p><strong>الخصم:</strong> <?= number_format($sale_detail['remise'] ?? 0, 2) ?> دج</p>
                <p><strong>الدفعة:</strong> <?= number_format($sale_detail['versement'] ?? 0, 2) ?> دج</p>
                <p><strong>الإجمالي الحالي:</strong> <?= number_format($sale_detail['total_actuel'] ?? 0, 2) ?> دج</p>
                <p><strong>الدين الحالي:</strong> <?= number_format($sale_detail['credit_actuel'] ?? 0, 2) ?> دج</p>
                <p><strong>الحالة:</strong> <span class="status <?= $sale_detail['status'] ?>"><?= ucfirst($sale_detail['status']) ?></span></p>
                <p><strong>المصدر:</strong> <?= htmlspecialchars($sale_detail['source']) ?></p>
                <p><strong>المسؤول:</strong> <?= htmlspecialchars($sale_detail['admin_nom'] ?? '-') ?></p>
                <p><strong>البائع (التجهيز):</strong> <?= htmlspecialchars($sale_detail['seller_nom'] ?? '-') ?></p>
                <?php if (!$is_client && $is_admin_user && $sale_detail['status'] === 'en_cours'): ?>
                    <div class="flex-buttons">
                        <form method="POST" style="display:inline; margin:0; width:100%; max-width:420px;">
                            <input type="hidden" name="action" value="validate_sale">
                            <input type="hidden" name="sales_id" value="<?= $sale_detail['id'] ?>">
                            <div class="form-group">
                                <label>الخصم (دج)</label>
                                <input type="number" step="0.01" name="remise" value="<?= number_format((float)($sale_detail['remise'] ?? 0), 2, '.', '') ?>" min="0">
                            </div>
                            <div class="form-group">
                                <label>الدفعة (دج)</label>
                                <input type="number" step="0.01" name="versement" value="<?= number_format((float)($sale_detail['versement'] ?? 0), 2, '.', '') ?>" min="0">
                            </div>
                            <button type="submit" class="btn btn-success">✅ تأكيد الطلب</button>
                        </form>
                        <button type="button" class="btn btn-secondary" onclick="location.href='sales.php?add_to_sale_id=<?= $sale_detail['id'] ?>'">➕ إضافة منتج لهذا البيع</button>
                    </div>
                <?php elseif (!$is_client && $is_admin_user && in_array($sale_detail['status'], ['validé', 'preparé', 'pret'], true)): ?>
                    <div class="flex-buttons">
                        <form method="POST" style="display:inline; margin:0; width:100%; max-width:420px;">
                            <input type="hidden" name="action" value="update_sale_payment">
                            <input type="hidden" name="sales_id" value="<?= $sale_detail['id'] ?>">
                            <div class="form-group">
                                <label>الدفعة (دج)</label>
                                <input type="number" step="0.01" name="versement" value="<?= number_format((float)($sale_detail['versement'] ?? 0), 2, '.', '') ?>" min="0">
                            </div>
                            <button type="submit" class="btn btn-primary">💾 تحديث الدفعة</button>
                        </form>
                        <button type="button" class="btn btn-secondary" onclick="location.href='sales.php?add_to_sale_id=<?= $sale_detail['id'] ?>'">➕ إضافة منتج لهذا البيع</button>
                    </div>
                <?php elseif (!$is_client && $is_vendeur_user && $sale_detail['status'] === 'validé'): ?>
                    <div class="flex-buttons">
                        <form method="POST" style="display:inline; margin:0;">
                            <input type="hidden" name="action" value="mark_prepared">
                            <input type="hidden" name="sales_id" value="<?= $sale_detail['id'] ?>">
                            <button type="submit" class="btn btn-primary">📦 وضع كمُجهّز</button>
                        </form>
                    </div>
                <?php elseif (!$is_client && $is_vendeur_user && $sale_detail['status'] === 'preparé'): ?>
                    <div class="flex-buttons">
                        <form method="POST" style="display:inline; margin:0;">
                            <input type="hidden" name="action" value="mark_ready">
                            <input type="hidden" name="sales_id" value="<?= $sale_detail['id'] ?>">
                            <button type="submit" class="btn btn-success">✅ وضع كجاهز</button>
                        </form>
                    </div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>مرجع</th>
                                <th>علامة</th>
                                <th>منتج</th>
                                <th>كراتين</th>
                                <th>وحدة/كرتون</th>
                                <th>كمية</th>
                                <th>سعر وحدة</th>
                                <th>مبلغ</th>
                                <?php if (!$is_client && $is_admin_user): ?><th>إجراءات</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sale_items) === 0): ?>
                                <tr><td colspan="<?= (!$is_client && $is_admin_user) ? '10' : '9' ?>">لا توجد أصناف لهذا البيع.</td></tr>
                            <?php else: $i=0;?>
                                <?php foreach ($sale_items as $item): ?>
                                    <tr>
                                        <td><?= ++$i ?></td>
                                        <td><?= htmlspecialchars($item['product_reference'] ?? $item['product_ref']) ?></td>
                                        <td><?= htmlspecialchars($item['marque'] ?? '-') ?></td>
                                        <td class="product-name-<?= htmlspecialchars(product_display_css_class($item['display_mode'] ?? 'normal')) ?>"><?= htmlspecialchars($item['product_name'] ?? $item['product_reference']) ?></td>
                                        <td><?= $item['nombre_de_carton'] ?></td>
                                        <td><?= $item['nombre_unite_par_carton'] ?></td>
                                        <td><?= $item['quantité'] ?></td>
                                        <td><?= number_format($item['prix_unite'], 2) ?> دج</td>
                                        <td><?= number_format($item['montant'], 2) ?> دج</td>
                                        <?php if (!$is_client && $is_admin_user): ?>
                                        <td>
                                            <button type="button" class="btn btn-warning" style="padding:0.375rem 0.75rem; font-size:0.75rem;" onclick="openEditItemForm(this)"
                                                data-item-id="<?= $item['id'] ?>"
                                                data-product-id="<?= $item['product_id'] ?>"
                                                data-price-type="<?= htmlspecialchars($item['prix_type']) ?>"
                                                data-unit-price="<?= number_format($item['prix_unite'], 2, '.', '') ?>"
                                                data-cartons="<?= $item['nombre_de_carton'] ?>"
                                                data-quantity="<?= $item['quantité'] ?>"
                                            >✏️ تعديل</button>
                                            <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('حذف هذا الصنف من هذا البيع؟');">
                                                <input type="hidden" name="action" value="delete_sale_item">
                                                <input type="hidden" name="sale_id" value="<?= $sale_detail['id'] ?>">
                                                <input type="hidden" name="sale_item_id" value="<?= $item['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="padding:0.375rem 0.75rem; font-size:0.75rem;">🗑️ حذف</button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <!-- Ajoutez ce bouton à côté des autres boutons -->
<div class="flex-buttons" style="margin-bottom: 1rem;">
    <button type="button" class="btn btn-primary" onclick="imprimerCarte()">
        🖨️ طباعة
    </button>
</div>
                </div>
                
                <div id="edit-sale-item-card" class="card" style="display:none; margin-top:1rem;">
                    <h3>✏️ تعديل بند</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_sale_item">
                        <input type="hidden" name="sale_id" value="<?= $sale_detail['id'] ?>">
                        <input type="hidden" name="sale_item_id" id="edit_sale_item_id">
                        <input type="hidden" name="price_type" value="custom">
                        <div class="product-line">
                            <div class="form-group">
                                <label>منتج</label>
                                <input type="text" id="edit_product_search" name="product_search" list="productOptions" class="product-search" placeholder="بحث بالاسم أو المرجع" autocomplete="off" required oninput="syncProductInput(this)">
                                <input type="hidden" id="edit_product_id" name="product_id">
                                <div style="margin-top:6px; font-size:0.85rem; color:var(--gray);">وحدة/كرتون: <span class="product-units">0</span> | علامة: <span class="product-marque">-</span></div>
                            </div>
                            <div class="form-group">
                                <label>سعر الوحدة (قابل للتعديل)</label>
                                <input type="number" step="0.01" id="edit_prix_unite" name="prix_unite" class="unit-price" required oninput="onPriceChange(this)">
                            </div>
                            <div class="form-group">
                                <label>عدد الكراتين</label>
                                <input type="number" id="edit_nombre_de_carton" name="nombre_de_carton" class="cartons" min="1" value="1" required oninput="updateQuantity(this.closest('.product-line'))">
                            </div>
                            <div class="form-group">
                                <label>الكمية الإجمالية (وحدات)</label>
                                <input type="number" id="edit_quantity" class="quantity" readonly>
                            </div>
                        </div>
                        <div class="flex-buttons" style="margin-top:1rem;">
                            <button type="submit" class="btn btn-success">💾 حفظ</button>
                            <button type="button" class="btn btn-danger" onclick="document.querySelector('#edit-sale-item-card').style.display='none'">❌ إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_client): ?>
        <div id="screen-create" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('menu')">→ رجوع للقائمة</button>
            </div>

            <?php if ($selected_product): ?>
                <div class="card">
                    <h2>🛒 طلب المنتج: <?= htmlspecialchars($selected_product['nom']) ?></h2>
                    <p><strong>مرجع:</strong> <?= htmlspecialchars($selected_product['reference']) ?></p>
                    <p><strong>سعر التجزئة:</strong> <?= number_format($selected_product['detail_prix'], 2) ?> دج</p>
                    <p><strong>مخزون الكراتين:</strong> <?= $selected_product['quantité'] ?></p>
                    <p><strong>وحدات لكل كرتون:</strong> <?= $selected_product['unité_par_carton'] ?></p>
                    <div class="flex-buttons">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('addToExistingSale').style.display='block'; document.getElementById('createNewSaleFromProduct').style.display='none';">📦 إضافة لطلب قائم</button>
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('createNewSaleFromProduct').style.display='block'; document.getElementById('addToExistingSale').style.display='none';">🆕 طلب جديد</button>
                    </div>
                    <div id="addToExistingSale" style="display:none; margin-top:1rem;">
                        <?php if (count($client_open_sales) > 0): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_sale_item">
                                <input type="hidden" name="product_id[]" value="<?= $selected_product['id'] ?>">
                                <input type="hidden" name="price_type[]" value="detail">
                                <div class="form-group">
                                    <label>اختر طلباً قيد التنفيذ</label>
                                    <select name="sale_id" required>
                                        <option value="">— اختر طلباً —</option>
                                        <?php foreach ($client_open_sales as $openSale): ?>
                                            <option value="<?= $openSale['id'] ?>">#<?= $openSale['id'] ?> - <?= $openSale['time'] ?> - <?= number_format($openSale['montant'], 2) ?> دج</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>عدد الكراتين</label>
                                    <input type="number" name="nombre_de_carton[]" min="1" value="1" required>
                                </div>
                                <button type="submit" class="btn btn-success">➕ إضافة للسلة</button>
                            </form>
                        <?php else: ?>
                            <p>لا يوجد طلب قيد التنفيذ. أنشئ طلباً جديداً أدناه.</p>
                        <?php endif; ?>
                    </div>
                    <div id="createNewSaleFromProduct" style="display:none; margin-top:1rem;">
                        <form method="POST">
                            <input type="hidden" name="action" value="create_order">
                            <input type="hidden" name="product_id[]" value="<?= $selected_product['id'] ?>">
                            <input type="hidden" name="price_type[]" value="detail">
                            <input type="hidden" name="prix_unite[]" value="">
                            <div class="form-group">
                                <label>عدد الكراتين</label>
                                <input type="number" name="nombre_de_carton[]" min="1" value="1" required>
                            </div>
                            <button type="submit" class="btn btn-success">🆕 إنشاء طلب جديد</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h2>🛒 طلب جديد</h2>
                <form method="POST" id="client-order-form" data-client-type="<?= htmlspecialchars($logged_in_client_type) ?>">
                    <input type="hidden" name="action" value="create_order">
                    <div id="client-product-lines"></div>
                    <div class="flex-buttons" style="margin-top:1rem;">
                        <button type="button" class="btn btn-secondary" onclick="addProductLine(false)">➕ إضافة منتج</button>
                        <button type="submit" class="btn btn-success">📦 إرسال الطلب</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="screen-list" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('menu')">→ رجوع للقائمة</button>
            </div>
            <div class="card">
                <h2>📄 طلباتي</h2>
                <form method="GET" class="search-form">
                    <input type="text" name="q" placeholder="🔍 بحث بالمعرّف أو التاريخ" value="<?= htmlspecialchars($search_query) ?>">
                    <button type="submit" class="btn btn-primary">بحث</button>
                    <?php if (!empty($search_query)): ?>
                        <a href="sales.php" class="btn btn-secondary">إعادة ضبط</a>
                    <?php endif; ?>
                </form>
                <?php if ($sales_result->num_rows === 0): ?>
                    <p>لا توجد طلبات. قدّم طلباً ليظهر هنا.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>التاريخ</th>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>المصدر</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($sale = $sales_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?= $sale['id'] ?></td>
                                        <td><?= $sale['time'] ?></td>
                                        <td><?= number_format($sale['montant'], 2) ?> دج</td>
                                        <td><span class="status <?= $sale['status'] ?>"><?= ucfirst($sale['status']) ?></span></td>
                                        <td><?= htmlspecialchars($sale['source']) ?></td>
                                        <td class="actions">
                                            <a class="btn btn-primary" href="sales.php?sale_id=<?= $sale['id'] ?>" style="padding:0.375rem 0.75rem; font-size:0.75rem;">📋 تفاصيل</a>
                                            <form method="POST" style="display:inline; margin:0;">
                                                <input type="hidden" name="action" value="cancel_sale">
                                                <input type="hidden" name="sales_id" value="<?= $sale['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="padding:0.375rem 0.75rem; font-size:0.75rem;">🗑️ حذف</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
            <?php if ($is_admin_user): ?>
            <div id="screen-create" class="nc-screen">
            <div class="nc-screen-nav">
                <?php if ($add_to_sale_id > 0): ?>
                    <button type="button" class="btn btn-secondary nc-back-btn" onclick="location.href='sales.php?sale_id=<?= $add_to_sale_id ?>'">→ رجوع لتفاصيل البيع #<?= $add_to_sale_id ?></button>
                <?php else: ?>
                    <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('menu')">→ رجوع للقائمة</button>
                <?php endif; ?>
            </div>
            <div class="card card--sale-create nc-pos-card">
                <?php if ($add_to_sale_id > 0): ?>
                    <div class="alert alert-success" style="margin-bottom:0.75rem;">
                        ➕ إضافة منتجات إلى البيع #<?= $add_to_sale_id ?> — الزبون: <?= htmlspecialchars($add_to_sale_client_name) ?>
                    </div>
                <?php endif; ?>
                <div class="nc-pos-header">
                    <div class="nc-pos-total-box">
                        <span class="nc-pos-total-label">المبلغ الإجمالي</span>
                        <span class="nc-pos-total-value" id="staff_live_total">0.00</span>
                    </div>
                    <div class="nc-pos-count-box">
                        <span class="nc-pos-count-label">عدد المنتجات</span>
                        <span class="nc-pos-count-value" id="staff_live_count">0</span>
                    </div>
                </div>

                <form method="POST" id="staff-sale-form" class="staff-sale-form-inner" data-client-type="detail" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $add_to_sale_id > 0 ? 'add_sale_item' : 'create_sale' ?>">
                    <?php if ($add_to_sale_id > 0): ?>
                        <input type="hidden" name="sale_id" value="<?= $add_to_sale_id ?>">
                    <?php endif; ?>
                    <input type="hidden" name="remise" id="staff_remise_input" value="0">
                    <input type="hidden" name="versement" id="staff_versement_input" value="0">
                    <div id="staff-sale-hidden-inputs"></div>

                    <div class="product-line staff-draft-line nc-pos-draft-line">
                        <div class="form-group nc-pos-product-group">
                            <label>منتج — ابحث بالاسم أو المرجع</label>
                            <div class="product-combobox-wrap">
                                <input type="text" class="product-search" placeholder="🔍 اكتب اسم المنتج أو المرجع…" autocomplete="off" oninput="syncProductInput(this)" aria-label="المنتج" autocapitalize="off" spellcheck="false">
                                <div id="staff_combobox_backdrop" class="staff-combobox-backdrop"></div>
                                <ul id="staff_product_combobox_list" class="product-combobox-list" role="listbox"></ul>
                            </div>
                            <input type="hidden" class="product-id" value="">
                            <div class="staff-meta-hint">وحدة/كرتون: <span class="product-units">0</span> | علامة: <span class="product-marque">-</span></div>
                        </div>
                        <div class="nc-pos-row3 nc-hidden">
                            <div class="form-group">
                                <label>سعر الوحدة</label>
                                <input type="number" step="0.01" class="unit-price" oninput="onPriceChange(this)" placeholder="0.00" aria-label="سعر الوحدة قابل للتعديل" inputmode="decimal">
                            </div>
                            <div class="form-group">
                                <label>عدد الكراتين</label>
                                <input type="number" class="cartons" min="1" value="1" oninput="updateQuantity(this.closest('.product-line'))" placeholder="1" aria-label="عدد الكراتين" inputmode="numeric">
                            </div>
                            <div class="form-group">
                                <label>مبلغ السطر ✏️</label>
                                <input type="number" step="0.01" class="line-amount" oninput="onLineAmountChange(this)" placeholder="0.00" aria-label="مبلغ السطر (قابل للتعديل)" inputmode="decimal">
                            </div>
                            <div class="form-group nc-pos-qty-group">
                                <label>الكمية</label>
                                <input type="number" class="quantity" oninput="updateLineTotals(this.closest('.product-line'))" placeholder="0" aria-label="الكمية الإجمالية بالوحدات" inputmode="numeric">
                            </div>
                        </div>
                        <div class="staff-draft-footer" dir="rtl">
                            <button type="button" class="btn btn-danger" onclick="staffClearDraft()">🗑️ حذف</button>
                            <button type="button" class="btn btn-secondary" onclick="staffAddPendingLine()">➕ إضافة منتج</button>
                        </div>
                    </div>

                    <div class="staff-pending-wrap">
                        <h3>📋 منتجات هذا البيع</h3>
                        <p id="staff_pending_empty" class="staff-pending-empty">لم تُضف منتجات بعد. املأ السطر ثم اضغط «إضافة منتج».</p>
                        <div class="table-responsive">
                            <table class="staff-pending-table">
                                <thead>
                                    <tr>
                                        <th>منتج</th>
                                        <th>مرجع</th>
                                        <th>كراتين</th>
                                        <th>كمية</th>
                                        <th>سعر الوحدة</th>
                                        <th>المبلغ</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="staff_pending_tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="nc-pos-bottom-bar">
                        <div class="form-group nc-pos-client-group">
                            <label>اسم الزبون</label>
                            <input type="text" name="client_search" id="client_search" list="clientOptions" placeholder="🔍 اسم الزبون أو المعرّف" autocomplete="off" required oninput="syncClientInput(this)" aria-label="العميل" <?= $add_to_sale_id > 0 ? 'readonly' : '' ?>>
                            <input type="hidden" name="client_id" id="client_id">
                        </div>
                        <button type="button" class="btn btn-danger nc-pos-cancel-btn" onclick="staffCancelCurrentSale()">✖️ إلغاء</button>
                        <button type="button" class="btn btn-success nc-pos-confirm-btn" onclick="<?= $add_to_sale_id > 0 ? 'staffSubmitAddToSale()' : 'staffOpenConfirmModal()' ?>">✅ <?= $add_to_sale_id > 0 ? 'حفظ المنتجات' : 'تأكيد' ?></button>
                    </div>
                </form>
                <datalist id="productOptions">
                    <?php foreach ($products as $p): ?>
                        <option value="<?= htmlspecialchars($p['nom'].' | مرجع: '.$p['reference']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="clientOptions">
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= htmlspecialchars($c['nom'].' | ID: '.$c['id']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div id="staff-product-modal" class="nc-modal-overlay" style="display:none;">
                <div class="nc-modal">
                    <h3 id="pm_title">-</h3>
                    <p id="pm_stock_info" class="nc-pm-stock-info"></p>
                    <div class="form-group">
                        <label>سعر المنتج</label>
                        <input type="number" step="0.01" id="pm_price" class="nc-pm-big-input" inputmode="decimal">
                    </div>
                    <div class="form-group">
                        <label>الكمية (عدد الكراتين)</label>
                        <input type="number" id="pm_cartons" class="nc-pm-big-input" min="1" placeholder="1" inputmode="numeric">
                    </div>
                    <div class="flex-buttons" style="margin-top:1rem;">
                        <button type="button" class="btn btn-success" onclick="staffSaveProductModal()">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="staffCloseProductModal()">↩️ الرجوع للخلف</button>
                    </div>
                </div>
            </div>

            <div id="staff-confirm-modal" class="nc-modal-overlay" style="display:none;">
                <div class="nc-modal">
                    <h3>✅ تأكيد البيع</h3>
                    <div class="nc-modal-row"><span>الزبون</span><strong id="cm_client_name">—</strong></div>
                    <div class="nc-modal-row"><span>المبلغ (هذا البيع)</span><strong id="cm_montant">0.00 دج</strong></div>
                    <div class="nc-modal-row"><span>الدين السابق</span><strong id="cm_credit_ancien">0.00 دج</strong></div>
                    <div class="form-group">
                        <label>الخصم (remise)</label>
                        <input type="number" step="0.01" min="0" id="cm_remise" value="0" inputmode="decimal" oninput="staffRecalcConfirmModal()">
                    </div>
                    <div class="form-group">
                        <label>الدفعة / الوريمنت (versement)</label>
                        <input type="number" step="0.01" min="0" id="cm_versement" value="0" inputmode="decimal" oninput="staffRecalcConfirmModal()">
                    </div>
                    <div class="nc-modal-row nc-modal-row--strong"><span>الإجمالي الحالي</span><strong id="cm_total_actuel">0.00 دج</strong></div>
                    <div class="nc-modal-row nc-modal-row--strong"><span>الدين الحالي</span><strong id="cm_credit_actuel">0.00 دج</strong></div>
                    <div class="flex-buttons" style="margin-top:1rem;">
                        <button type="button" class="btn btn-success" onclick="staffSubmitConfirmedSale()">💾 حفظ البيع</button>
                        <button type="button" class="btn btn-danger" onclick="staffCloseConfirmModal()">❌ إلغاء</button>
                    </div>
                </div>
            </div>
            </div>
            <?php endif; ?>

        <?php if ($is_admin_user): ?>
        <div id="screen-return" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('menu')">→ رجوع للقائمة</button>
            </div>
            <div class="card card--sale-create nc-pos-card">
                <div class="alert alert-success" style="margin-bottom:0.75rem; background:#fff7ed; border-color:#f59e0b; color:#92400e;">
                    ↩️ إرجاع منتجات إلى المخزون — سيُضاف كل منتج إلى المخزون تلقائياً، ويُخصم من دين الزبون.
                </div>
                <div class="nc-pos-header">
                    <div class="nc-pos-total-box">
                        <span class="nc-pos-total-label">قيمة المرتجع</span>
                        <span class="nc-pos-total-value" id="return_live_total">0.00</span>
                    </div>
                    <div class="nc-pos-count-box">
                        <span class="nc-pos-count-label">عدد المنتجات</span>
                        <span class="nc-pos-count-value" id="return_live_count">0</span>
                    </div>
                </div>

                <form method="POST" id="staff-return-form" class="staff-sale-form-inner" autocomplete="off">
                    <input type="hidden" name="action" value="create_return">
                    <div id="return-hidden-inputs"></div>

                    <div class="product-line return-draft-line nc-pos-draft-line">
                        <div class="form-group nc-pos-product-group">
                            <label>منتج — ابحث بالاسم أو المرجع</label>
                            <div class="product-combobox-wrap">
                                <input type="text" class="product-search" placeholder="🔍 اكتب اسم المنتج أو المرجع…" autocomplete="off" oninput="syncProductInput(this)" aria-label="المنتج" autocapitalize="off" spellcheck="false">
                                <div id="return_combobox_backdrop" class="staff-combobox-backdrop"></div>
                                <ul id="return_product_combobox_list" class="product-combobox-list" role="listbox"></ul>
                            </div>
                            <input type="hidden" class="product-id" value="">
                            <div class="staff-meta-hint">وحدة/كرتون: <span class="product-units">0</span> | علامة: <span class="product-marque">-</span></div>
                        </div>
                        <div class="nc-pos-row3 nc-hidden">
                            <div class="form-group">
                                <label>سعر الوحدة</label>
                                <input type="number" step="0.01" class="unit-price" oninput="onPriceChange(this)" placeholder="0.00" aria-label="سعر الوحدة قابل للتعديل" inputmode="decimal">
                            </div>
                            <div class="form-group">
                                <label>عدد الكراتين</label>
                                <input type="number" class="cartons" min="1" value="1" oninput="updateQuantity(this.closest('.product-line'))" placeholder="1" aria-label="عدد الكراتين" inputmode="numeric">
                            </div>
                            <div class="form-group">
                                <label>مبلغ السطر ✏️</label>
                                <input type="number" step="0.01" class="line-amount" oninput="onLineAmountChange(this)" placeholder="0.00" aria-label="مبلغ السطر (قابل للتعديل)" inputmode="decimal">
                            </div>
                            <div class="form-group nc-pos-qty-group">
                                <label>الكمية</label>
                                <input type="number" class="quantity" oninput="updateLineTotals(this.closest('.product-line'))" placeholder="0" aria-label="الكمية الإجمالية بالوحدات" inputmode="numeric">
                            </div>
                        </div>
                        <div class="staff-draft-footer" dir="rtl">
                            <button type="button" class="btn btn-danger" onclick="returnClearDraft()">🗑️ حذف</button>
                            <button type="button" class="btn btn-secondary" onclick="returnAddPendingLine()">➕ إضافة منتج</button>
                        </div>
                    </div>

                    <div class="staff-pending-wrap">
                        <h3>📋 منتجات هذا المرتجع</h3>
                        <p id="return_pending_empty" class="staff-pending-empty">لم تُضف منتجات بعد. املأ السطر ثم اضغط «إضافة منتج».</p>
                        <div class="table-responsive">
                            <table class="staff-pending-table">
                                <thead>
                                    <tr>
                                        <th>منتج</th>
                                        <th>مرجع</th>
                                        <th>كراتين</th>
                                        <th>كمية</th>
                                        <th>سعر الوحدة</th>
                                        <th>المبلغ</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="return_pending_tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="nc-pos-bottom-bar">
                        <div class="form-group nc-pos-client-group">
                            <label>اسم الزبون</label>
                            <input type="text" name="client_search" id="return_client_search" list="clientOptions" placeholder="🔍 اسم الزبون أو المعرّف" autocomplete="off" required oninput="syncReturnClientInput(this)" aria-label="العميل">
                            <input type="hidden" name="client_id" id="return_client_id">
                        </div>
                        <button type="button" class="btn btn-danger nc-pos-cancel-btn" onclick="returnCancelCurrent()">✖️ إلغاء</button>
                        <button type="button" class="btn btn-success nc-pos-confirm-btn" onclick="returnSubmitOperation()">✅ حفظ الإرجاع</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2>📄 سجل عمليات الإرجاع</h2>
                <?php if (count($returns_list) === 0): ?>
                    <p>لا توجد عمليات إرجاع مسجّلة بعد.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>الوقت</th>
                                    <th>الزبون</th>
                                    <th>الموظف</th>
                                    <th>قيمة المرتجع</th>
                                    <th>الدين قبل</th>
                                    <th>المسترجع للزبون</th>
                                    <th>الدين الحالي</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returns_list as $ret): ?>
                                    <tr>
                                        <td>#<?= (int)$ret['id'] ?></td>
                                        <td><?= htmlspecialchars($ret['time']) ?></td>
                                        <td><?= htmlspecialchars($ret['client_nom']) ?></td>
                                        <td><?= htmlspecialchars($ret['admin_nom'] ?? '-') ?></td>
                                        <td><?= number_format((float)$ret['montant'], 2) ?> دج</td>
                                        <td><?= number_format((float)$ret['credit'], 2) ?> دج</td>
                                        <td><?= number_format((float)$ret['total_actuel'], 2) ?> دج</td>
                                        <td><?= number_format((float)$ret['credit_actuel'], 2) ?> دج</td>
                                        <td class="actions">
                                            <a class="btn btn-primary" href="sales.php?return_id=<?= (int)$ret['id'] ?>" style="padding:0.375rem 0.75rem; font-size:0.75rem;">📋 تفاصيل</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div id="return-product-modal" class="nc-modal-overlay" style="display:none;">
                <div class="nc-modal">
                    <h3 id="rpm_title">-</h3>
                    <p id="rpm_stock_info" class="nc-pm-stock-info"></p>
                    <div class="form-group">
                        <label>سعر الوحدة</label>
                        <input type="number" step="0.01" id="rpm_price" class="nc-pm-big-input" inputmode="decimal">
                    </div>
                    <div class="form-group">
                        <label>الكمية (عدد الكراتين)</label>
                        <input type="number" id="rpm_cartons" class="nc-pm-big-input" min="1" placeholder="1" inputmode="numeric">
                    </div>
                    <div class="flex-buttons" style="margin-top:1rem;">
                        <button type="button" class="btn btn-success" onclick="returnSaveProductModal()">💾 حفظ</button>
                        <button type="button" class="btn btn-secondary" onclick="returnCloseProductModal()">↩️ الرجوع للخلف</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($return_detail): ?>
        <div id="screen-return-detail" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('return')">→ رجوع لسجل الإرجاع</button>
            </div>
            <div class="card">
                <h2>↩️ تفاصيل الإرجاع #<?= (int)$return_detail['id'] ?></h2>
                <p><strong>العميل:</strong> <?= htmlspecialchars($return_detail['client_nom']) ?></p>
                <p><strong>التاريخ:</strong> <?= htmlspecialchars($return_detail['time']) ?></p>
                <p><strong>الموظف:</strong> <?= htmlspecialchars($return_detail['admin_nom'] ?? '-') ?></p>
                <p><strong>المصدر:</strong> <?= htmlspecialchars($return_detail['source']) ?></p>
                <p><strong>قيمة المرتجع:</strong> <?= number_format((float)$return_detail['montant'], 2) ?> دج</p>
                <p><strong>الدين قبل العملية:</strong> <?= number_format((float)$return_detail['credit'], 2) ?> دج</p>
                <p><strong>المسترجع للزبون:</strong> <?= number_format((float)$return_detail['total_actuel'], 2) ?> دج</p>
                <p><strong>الدين الحالي:</strong> <?= number_format((float)$return_detail['credit_actuel'], 2) ?> دج</p>

                <div class="table-responsive" style="margin-top:1rem;">
                    <table>
                        <thead>
                            <tr>
                                <th>منتج</th>
                                <th>مرجع</th>
                                <th>كراتين</th>
                                <th>وحدة/كرتون</th>
                                <th>كمية</th>
                                <th>سعر الوحدة</th>
                                <th>مبلغ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($return_items as $ri): ?>
                                <tr>
                                    <td><?= htmlspecialchars($ri['product_nom']) ?></td>
                                    <td><?= htmlspecialchars($ri['product_reference']) ?></td>
                                    <td><?= (int)$ri['nombre_de_carton'] ?></td>
                                    <td><?= (int)$ri['nombre_unite_par_carton'] ?></td>
                                    <td><?= (int)$ri['quantité'] ?></td>
                                    <td><?= number_format((float)$ri['prix_unite'], 2) ?> دج</td>
                                    <td><?= number_format((float)$ri['montant'], 2) ?> دج</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex-buttons" style="margin-top:1rem;">
                    <button type="button" class="btn btn-primary" onclick="imprimerReturnCarte()">
                        🖨️ طباعة
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="screen-list" class="nc-screen">
            <div class="nc-screen-nav">
                <button type="button" class="btn btn-secondary nc-back-btn" onclick="showScreen('menu')">→ رجوع للقائمة</button>
            </div>
            <div class="card">
                <h2>📄 طلبات العملاء</h2>
                <form method="GET" class="search-form">
                    <input type="text" name="q" placeholder="🔍 بحث بالمعرّف أو التاريخ أو العميل" value="<?= htmlspecialchars($search_query) ?>">
                    <button type="submit" class="btn btn-primary">بحث</button>
                    <?php if (!empty($search_query)): ?>
                        <a href="sales.php" class="btn btn-secondary">إعادة ضبط</a>
                    <?php endif; ?>
                </form>
                <?php if ($sales_result->num_rows === 0): ?>
                    <p>لا توجد طلبات عملاء.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>التاريخ</th>
                                    <th>العميل</th>
                                    <th>المبلغ</th>
                                    <th>مسؤول</th>
                                    <th>بائع</th>
                                    <th>الحالة</th>
                                    <th>المصدر</th>
                                    <th>إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($sale = $sales_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?= $sale['id'] ?></td>
                                        <td><?= $sale['time'] ?></td>
                                        <td><?= htmlspecialchars($sale['client_nom']) ?></td>
                                        <td><?= number_format($sale['montant'], 2) ?> دج</td>
                                        <td><?= htmlspecialchars($sale['admin_nom'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($sale['seller_nom'] ?? '-') ?></td>
                                        <td><span class="status <?= $sale['status'] ?>"><?= ucfirst($sale['status']) ?></span></td>
                                        <td><?= htmlspecialchars($sale['source']) ?></td>
                                        <td class="actions">
                                            <a class="btn btn-primary" href="sales.php?sale_id=<?= $sale['id'] ?>" style="padding:0.375rem 0.75rem; font-size:0.75rem;">📋 تفاصيل</a>
                                            <?php if ($is_vendeur_user && $sale['status'] === 'validé'): ?>
                                            <form method="POST" style="display:inline; margin:0;">
                                                <input type="hidden" name="action" value="mark_prepared">
                                                <input type="hidden" name="sales_id" value="<?= $sale['id'] ?>">
                                                <button type="submit" class="btn btn-secondary" style="padding:0.375rem 0.75rem; font-size:0.75rem;">📦 مُجهّز</button>
                                            </form>
                                            <?php elseif ($is_vendeur_user && $sale['status'] === 'preparé'): ?>
                                            <form method="POST" style="display:inline; margin:0;">
                                                <input type="hidden" name="action" value="mark_ready">
                                                <input type="hidden" name="sales_id" value="<?= $sale['id'] ?>">
                                                <button type="submit" class="btn btn-success" style="padding:0.375rem 0.75rem; font-size:0.75rem;">✅ جاهز</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($is_admin_user): ?>
                                            <form method="POST" style="display:inline; margin:0;">
                                                <input type="hidden" name="action" value="cancel_sale">
                                                <input type="hidden" name="sales_id" value="<?= $sale['id'] ?>">
                                                <button type="submit" class="btn btn-danger" style="padding:0.375rem 0.75rem; font-size:0.75rem;">🗑️ حذف</button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
            </div>
        </div>
        <?php endif; ?>

    </div>

</body>
</html>
<style>
    /* ===================== STYLES D'IMPRESSION ===================== */
@media print {
    /* Cache les éléments non nécessaires à l'impression */
    header, 
    .back-link, 
    .btn, 
    button:not(.no-print),
    .search-form,
    .flex-buttons,
    .actions,
    .remove-product-line,
    #edit-sale-item-card,
    form:not(.print-form) {
        display: none !important;
    }
    
    /* Style pour l'impression */
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
    
    .card {
        box-shadow: none !important;
        border: 1px solid #ccc !important;
        margin: 0 !important;
        padding: 15px !important;
        page-break-inside: avoid;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th, td {
        border: 1px solid #ddd;
        padding: 8px;
    }
    
    th {
        background: #f0f0f0 !important;
    }
    
    /* Évite les coupures de page à l'intérieur des blocs */
    .card, .product-line {
        page-break-inside: avoid;
    }
}
</style>
<script>
/* ===================== IMPRESSION THERMIQUE (RawBT / Bluetooth) — MODE IMAGE ===================== */
/*
 * L'imprimante XP-P323B ne sait pas afficher l'arabe en texte brut (police interne
 * limitée à l'ASCII). Solution : on DESSINE le ticket sur un <canvas> (le navigateur,
 * lui, sait très bien rendre l'arabe), puis on convertit ce dessin en image bitmap
 * envoyée à l'imprimante via les commandes ESC/POS (GS v 0). L'imprimante reçoit alors
 * des pixels, pas des caractères : n'importe quelle langue/police s'imprime fidèlement.
 */

/**
 * Détecte si on est sur Android (où l'on doit utiliser RawBT au lieu de window.print()).
 */
function isAndroidDevice() {
    return /android/i.test(navigator.userAgent || '');
}

/**
 * Largeur du papier en PIXELS (pas en caractères, on est en mode image).
 * 58mm = 384px ; 80mm = 576px. Un seul chiffre à changer si besoin.
 */
const PAPER_WIDTH_PX = 576; // 80mm @ 203dpi (confirmé par mesure réelle du papier)

/**
 * Construit le ticket complet en image (canvas), en arabe RTL natif,
 * en utilisant TOUTE la largeur du papier.
 * Retourne le canvas prêt à être converti en commandes ESC/POS.
 */
function dessinerTicketCanvas(data) {
    const W = PAPER_WIDTH_PX;
    const PAD = 16; // marge gauche/droite en pixels (papier 80mm)
    const contentW = W - PAD * 2;

    // --- Canvas de mesure (pour calculer la hauteur totale avant de dessiner pour de vrai) ---
    const measureCanvas = document.createElement('canvas');
    const mctx = measureCanvas.getContext('2d');

    const FONT_NORMAL = '26px Arial, sans-serif';
    const FONT_BOLD = 'bold 28px Arial, sans-serif';
    const FONT_SMALL = '22px Arial, sans-serif';
    const FONT_TITLE = 'bold 30px Arial, sans-serif';
    const LINE_H = 36;
    const LINE_H_SMALL = 28;

    // Découpe un texte arabe en plusieurs lignes selon la largeur disponible
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

    // Tronque un texte avec "…" s'il dépasse maxWidth (pour les cellules de tableau, une seule ligne)
    function truncateText(ctx, text, maxWidth) {
        let str = String(text ?? '');
        if (ctx.measureText(str).width <= maxWidth) return str;
        while (str.length > 1 && ctx.measureText(str + '…').width > maxWidth) {
            str = str.slice(0, -1);
        }
        return str + '…';
    }

    // --- Construit la liste des "opérations" de dessin avec leur hauteur, pour calculer le total ---
    const ops = [];
    function addLine(text, font, height, align = 'right') {
        ops.push({ type: 'text', text, font, height, align });
    }
    function addSeparator(height = 14, thick = false) {
        ops.push({ type: 'sep', height, thick });
    }
    function addSpace(height) {
        ops.push({ type: 'space', height });
    }
    function addRow(leftLabel, rightValue, font, height) {
        ops.push({ type: 'row', leftLabel, rightValue, font, height });
    }

    mctx.font = FONT_TITLE;
    wrapText(mctx, data.titre1, contentW).forEach((l) => addLine(l, FONT_TITLE, LINE_H + 4, 'center'));
    mctx.font = FONT_SMALL;
    wrapText(mctx, data.titre2, contentW).forEach((l) => addLine(l, FONT_SMALL, LINE_H_SMALL, 'center'));
    wrapText(mctx, data.titre3, contentW).forEach((l) => addLine(l, FONT_SMALL, LINE_H_SMALL, 'center'));
    addSeparator(16, true);

    // --- En-tête compact : ID vente, date, client (sans labels) ---
    addLine(`#${data.saleId || '-'}`, FONT_BOLD, LINE_H + 2, 'center');
    addLine(`${data.time || '-'}`, FONT_NORMAL, LINE_H, 'center');
    mctx.font = FONT_BOLD;
    wrapText(mctx, `${data.clientName || '-'}`, contentW).forEach((l) =>
        addLine(l, FONT_BOLD, LINE_H, 'center')
    );
    addSeparator(16, true);

    // --- Tableau articles : colonnes alignées, une ligne par produit ---
    // Colonnes (de droite à gauche en RTL) : Produit | Cartons | U/Carton | Px unit. | Total
    const FONT_TABLE_HEAD = 'bold 20px Arial, sans-serif';
    const FONT_TABLE_ROW = '20px Arial, sans-serif';
    const LINE_H_TABLE = 30;

    // Largeurs de colonnes en pixels (somme = contentW). Produit prend le plus de place.
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
        ops.push({
            type: 'table-row',
            cols: tableCols,
            values: item,
            font: FONT_TABLE_ROW,
            height: LINE_H_TABLE
        });
    });
    addSeparator(16, true);

    // --- Résumé financier ---
    addRow('عدد المنتجات', data.productCount, FONT_NORMAL, LINE_H);
    if (data.isReturn) {
        addRow('قيمة المرتجع', data.total, FONT_BOLD, LINE_H + 2);
        addRow('الدين قبل العملية', data.creditAncien, FONT_NORMAL, LINE_H);
        addRow('المسترجع للزبون', data.totalActuel, FONT_NORMAL, LINE_H);
        addSeparator(16, true);
        addRow('الدين الحالي', data.creditActuel, FONT_BOLD, LINE_H + 4);
    } else {
        addRow('المجموع', data.total, FONT_BOLD, LINE_H + 2);
        addRow('القرض السابق', data.creditAncien, FONT_NORMAL, LINE_H);
        addRow('الخصم', data.remise, FONT_NORMAL, LINE_H);
        addRow('المبلغ المستحق', data.totalActuel, FONT_NORMAL, LINE_H);
        addRow('الدفع', data.versement, FONT_NORMAL, LINE_H);
        addSeparator(16, true);
        addRow('القرض الحالي', data.creditActuel, FONT_BOLD, LINE_H + 4);
    }
    addSeparator(16, true);
    addSpace(40); // marge basse pour la coupe papier

    // --- Calcule la hauteur totale ---
    let totalHeight = 20; // marge haute
    ops.forEach((op) => { totalHeight += op.height; });

    // --- Dessine pour de vrai sur un canvas à la bonne taille ---
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
            // On place les colonnes de DROITE à GAUCHE dans l'ordre du tableau (RTL)
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
        // 'space' ne dessine rien
        y += op.height;
    });

    return canvas;
}

/**
 * Convertit un canvas en commandes ESC/POS bitmap (GS v 0) encodées en Base64,
 * format attendu par RawBT pour l'impression d'images sur imprimante thermique.
 */
function canvasToEscPosBase64(canvas) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    const imgData = ctx.getImageData(0, 0, w, h).data;

    // Seuillage noir/blanc (dithering simple par luminosité)
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

    // En-tête ESC/POS pour image bitmap : GS v 0 m xL xH yL yH [data]
    const xL = widthBytes & 0xFF;
    const xH = (widthBytes >> 8) & 0xFF;
    const yL = h & 0xFF;
    const yH = (h >> 8) & 0xFF;

    const header = new Uint8Array([0x1D, 0x76, 0x30, 0x00, xL, xH, yL, yH]);
    const full = new Uint8Array(header.length + bitmap.length);
    full.set(header, 0);
    full.set(bitmap, header.length);

    // Conversion en Base64
    let binary = '';
    for (let i = 0; i < full.length; i++) {
        binary += String.fromCharCode(full[i]);
    }
    return btoa(binary);
}

/**
 * Envoie une image (déjà en commandes ESC/POS Base64) à RawBT via l'intent Android officiel.
 * Doc RawBT : https://rawbt.ru/start.html
 */
function sendEscPosToRawBT(base64Data) {
    const intentUrl = 'intent:base64,' + base64Data +
        '#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
    window.location.href = intentUrl;
}

function imprimerCarte() {
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

    const saleTitle = detailCard.querySelector('h2')?.textContent || 'بيع';
    const idMatch = saleTitle.match(/#(\d+)/);
    const saleId = idMatch ? Number(idMatch[1]) : 0;
    const deliveryCounter = <?= (int)$daily_delivery_counter ?>;
    const clientId = <?= (int)($sale_detail['client_id'] ?? 0) ?>;
    const clientName = getValueByLabel('العميل');
    const time = getValueByLabel('التاريخ');
    const admin = getValueByLabel('المسؤول');
    const source = getValueByLabel('المصدر');
    const total = getValueByLabel('الإجمالي');
    const creditAncien = getValueByLabel('دين العميل');
    const remise = getValueByLabel('الخصم');
    const totalActuel = getValueByLabel('الإجمالي الحالي');
    const versement = getValueByLabel('الدفعة');
    const creditActuel = getValueByLabel('الدين الحالي');

    const table = detailCard.querySelector('table');
    if (!table) return;
    const originalHeaders = Array.from(table.querySelectorAll('thead th')).map((th) => (th.textContent || '').trim());
    const headerMap = {
        'ID': 'ID',
        'منتج': 'المنتج',
        'كراتين': 'كراتين',
        'وحدة/كرتون': 'وحدة/كرتون',
        'كمية': 'كمية',
        'سعر وحدة': 'سعر الوحدة',
        'مبلغ': 'المبلغ'
    };
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
    const printTableHtml = `
        <thead><tr>${finalHeaders.map((h) => `<th>${h}</th>`).join('')}</tr></thead>
        <tbody>${bodyRows}</tbody>
    `;
    const productCount = Array.from(table.querySelectorAll('tbody tr')).filter((tr) => tr.querySelectorAll('td').length > 0).length;

    /* ============ BRANCHE ANDROID : impression via RawBT (image bitmap, arabe natif) ============ */
    if (isAndroidDevice()) {
        // Récupère les lignes du tableau en TEXTE BRUT pour construire les données du ticket
        const dataRows = Array.from(table.querySelectorAll('tbody tr')).map((tr) => {
            const cells = Array.from(tr.querySelectorAll('td'));
            if (!cells.length) return null;
            const row = {};
            keepIndexes.forEach((idx, i) => {
                row[finalHeaders[i]] = (cells[idx]?.textContent || '').trim();
            });
            return row;
        }).filter(Boolean);

        // Nettoie un prix : enlève les virgules (séparateur de milliers) et le suffixe "دج"
        function cleanPrice(val) {
            return String(val || '0')
                .replace(/دج/g, '')
                .replace(/,/g, '')
                .trim();
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
            titre2: 'لمواد التغليف، للعقاقير و مواد النظافة و الصيانة',
            titre3: 'المنزلية و المهنية و غيرها من المواد المماثلة',
            deliveryCounter,
            saleId,
            time,
            admin,
            source,
            clientName,
            clientId,
            items,
            productCount,
            total: cleanPrice(total) || '0',
            creditAncien: cleanPrice(creditAncien) || '0',
            remise: cleanPrice(remise) || '0',
            totalActuel: cleanPrice(totalActuel) || '0',
            versement: cleanPrice(versement) || '0',
            creditActuel: cleanPrice(creditActuel) || '0'
        };

        const canvas = dessinerTicketCanvas(ticketData);
        const base64Data = canvasToEscPosBase64(canvas);
        sendEscPosToRawBT(base64Data);
        return; // on s'arrête ici sur Android, pas besoin d'ouvrir window.print()
    }

    /* ============ BRANCHE PC / IMPRIMANTE A4 CLASSIQUE : ancien comportement ============ */
const w = window.open('', '_blank');
w.document.write(`
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>وصل التسليم رقم #${deliveryCounter || 0}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 10px; color: #111; direction: rtl; }
        .top-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .top-table td { border: 1px solid #333; vertical-align: top; padding: 4px 8px; font-size: 11px; line-height: 1.4; width: 33.33%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .items th, .items td { border: 1px solid #bbb; padding: 3px 6px; font-size: 10px; text-align: right; line-height: 1.2; }
        .items th { background: #f1f5f9; height: 20px; }
        .items tr { height: 18px; }
        .footer-container {
            display: flex;
            justify-content: space-between;
            align-items: stretch;
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
            background-color: #fcfdfe;
        }
        .summary-left { width:30%; display: flex; flex-direction: column; justify-content: center; border: 2px solid #007bff; padding: 10px; border-radius: 4px; transform: rotate(-4deg); }
        .summary-left strong { color: blue; font-size: 13px; margin-bottom: 4px; }
        .summary { width: 50%; }
        .summary-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 2px 0; font-size: 11px; }
        .summary-row:last-child { border-bottom: none; }
        .summary-row strong { white-space: nowrap; margin-left: 8px; }
        @media print {
            body { margin: 0; }
            .footer-container { -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <table class="top-table">
        <tr>
            <td>
                رقم الرخصة : <br>  
                وصل التسليم رقم: #${deliveryCounter || '-'}<br>
                الرقم التسلسلي: #${saleId || '-'}<br>
            </td>
            <td>
                الوقت: ${escapeHtml(String(time || '-'))}<br>
                المسؤول: ${escapeHtml(String(admin || '-'))}<br>
                المصدر: ${escapeHtml(String(source || '-'))}
            </td>
            <td>
                العميل: ${escapeHtml(String(clientName || '-'))}<br>
                رقم العميل: ${clientId || '-'}
            </td>
        </tr>
    </table>
    <table class="items">${printTableHtml}</table>
    <div class="footer-container">
        <div class="summary-left">
            <strong>تجارة بالجملة لمواد التجميل و التنظيف</strong>
            <strong>لمواد التغليف، للعقاقير و مواد النظافة و الصيانة</strong>
            <strong>المنزلية و المهنية و غيرها من المواد المماثلة</strong>
        </div>
        <div class="summary">
            <div class="summary-row"><strong>عدد المنتجات</strong><span>${productCount}</span></div>
            <div class="summary-row"><strong>المجموع</strong><span>${escapeHtml(String(total || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>القرض السابق</strong><span>${escapeHtml(String(creditAncien || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الخصم</strong><span>${escapeHtml(String(remise || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>المبلغ المستحق</strong><span>${escapeHtml(String(totalActuel || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الدفع</strong><span>${escapeHtml(String(versement || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>القرض الحالي</strong><span>${escapeHtml(String(creditActuel || '0.00 دج'))}</span></div>
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

function imprimerReturnCarte() {
    function escapeHtml(s) {
        const t = document.createElement('textarea');
        t.textContent = s;
        return t.innerHTML;
    }

    const detailCard = document.querySelector('#screen-return-detail .card');
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

    const returnTitle = detailCard.querySelector('h2')?.textContent || 'إرجاع';
    const idMatch = returnTitle.match(/#(\d+)/);
    const returnId = idMatch ? Number(idMatch[1]) : 0;
    const clientName = getValueByLabel('العميل');
    const time = getValueByLabel('التاريخ');
    const admin = getValueByLabel('الموظف');
    const source = getValueByLabel('المصدر');
    const total = getValueByLabel('قيمة المرتجع');
    const creditAncien = getValueByLabel('الدين قبل العملية');
    const totalActuel = getValueByLabel('المسترجع للزبون');
    const creditActuel = getValueByLabel('الدين الحالي');

    const table = detailCard.querySelector('table');
    if (!table) return;
    const originalHeaders = Array.from(table.querySelectorAll('thead th')).map((th) => (th.textContent || '').trim());
    const headerMap = {
        'منتج': 'المنتج',
        'مرجع': 'مرجع',
        'كراتين': 'كراتين',
        'وحدة/كرتون': 'وحدة/كرتون',
        'كمية': 'كمية',
        'سعر الوحدة': 'سعر الوحدة',
        'مبلغ': 'المبلغ'
    };
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
    const printTableHtml = `
        <thead><tr>${finalHeaders.map((h) => `<th>${h}</th>`).join('')}</tr></thead>
        <tbody>${bodyRows}</tbody>
    `;
    const productCount = Array.from(table.querySelectorAll('tbody tr')).filter((tr) => tr.querySelectorAll('td').length > 0).length;

    /* ============ BRANCHE ANDROID : impression via RawBT ============ */
    if (isAndroidDevice()) {
        const dataRows = Array.from(table.querySelectorAll('tbody tr')).map((tr) => {
            const cells = Array.from(tr.querySelectorAll('td'));
            if (!cells.length) return null;
            const row = {};
            keepIndexes.forEach((idx, i) => {
                row[finalHeaders[i]] = (cells[idx]?.textContent || '').trim();
            });
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
            titre2: 'وصل إرجاع منتجات إلى المخزون',
            titre3: '',
            deliveryCounter: returnId,
            saleId: returnId,
            time,
            admin,
            source,
            clientName,
            clientId: <?= (int)($return_detail['client_id'] ?? 0) ?>,
            items,
            productCount,
            isReturn: true,
            total: cleanPrice(total) || '0',
            creditAncien: cleanPrice(creditAncien) || '0',
            totalActuel: cleanPrice(totalActuel) || '0',
            creditActuel: cleanPrice(creditActuel) || '0'
        };

        const canvas = dessinerTicketCanvas(ticketData);
        const base64Data = canvasToEscPosBase64(canvas);
        sendEscPosToRawBT(base64Data);
        return;
    }

    /* ============ BRANCHE PC / IMPRIMANTE A4 ============ */
    const w = window.open('', '_blank');
    w.document.write(`
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>وصل إرجاع منتجات #${returnId || 0}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 10px; color: #111; direction: rtl; }
        .top-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .top-table td { border: 1px solid #333; vertical-align: top; padding: 4px 8px; font-size: 11px; line-height: 1.4; width: 33.33%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .items th, .items td { border: 1px solid #bbb; padding: 3px 6px; font-size: 10px; text-align: right; line-height: 1.2; }
        .items th { background: #f1f5f9; height: 20px; }
        .items tr { height: 18px; }
        .footer-container {
            display: flex;
            justify-content: space-between;
            align-items: stretch;
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
            background-color: #fcfdfe;
        }
        .summary-left { width:30%; display: flex; flex-direction: column; justify-content: center; border: 2px solid #f59e0b; padding: 10px; border-radius: 4px; transform: rotate(-4deg); }
        .summary-left strong { color: #b45309; font-size: 13px; margin-bottom: 4px; }
        .summary { width: 50%; }
        .summary-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 2px 0; font-size: 11px; }
        .summary-row:last-child { border-bottom: none; }
        .summary-row strong { white-space: nowrap; margin-left: 8px; }
        @media print {
            body { margin: 0; }
            .footer-container { -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <table class="top-table">
        <tr>
            <td>
                وصل إرجاع منتجات<br>
                الرقم التسلسلي: #${returnId || '-'}<br>
            </td>
            <td>
                الوقت: ${escapeHtml(String(time || '-'))}<br>
                الموظف: ${escapeHtml(String(admin || '-'))}<br>
                المصدر: ${escapeHtml(String(source || '-'))}
            </td>
            <td>
                العميل: ${escapeHtml(String(clientName || '-'))}
            </td>
        </tr>
    </table>
    <table class="items">${printTableHtml}</table>
    <div class="footer-container">
        <div class="summary-left">
            <strong>تجارة بالجملة لمواد التجميل و التنظيف</strong>
            <strong>وصل إرجاع منتجات إلى المخزون</strong>
        </div>
        <div class="summary">
            <div class="summary-row"><strong>عدد المنتجات</strong><span>${productCount}</span></div>
            <div class="summary-row"><strong>قيمة المرتجع</strong><span>${escapeHtml(String(total || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>الدين قبل العملية</strong><span>${escapeHtml(String(creditAncien || '0.00 دج'))}</span></div>
            <div class="summary-row"><strong>المسترجع للزبون</strong><span>${escapeHtml(String(totalActuel || '0.00 دج'))}</span></div>
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
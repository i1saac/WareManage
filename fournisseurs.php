<?php
header('Content-Type: text/html; charset=utf-8');
// fournisseurs.php - Manage Suppliers (Only for Admin)
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'];

// Only Admin can access this page
if ($is_client || $user_type !== 'admin') {
    die("<div class='error-container'><h2>⛔ الوصول مرفوض</h2><p>يمكن للمسؤول فقط إدارة المورّدين.</p><a href='dashboard.php' class='btn-primary'>العودة إلى لوحة التحكم</a></div>");
}

// Handle Add New Supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $nom = trim($_POST['nom']);
    $telephone = trim($_POST['telephone']);

    if (empty($nom)) {
        $error = "اسم المورّد مطلوب";
    } else {
        $stmt = $conn->prepare("INSERT INTO fournisseur (nom, telephone) VALUES (?, ?)");
        $stmt->bind_param("ss", $nom, $telephone);
        
        if ($stmt->execute()) {
            $success = "تمت إضافة المورّد بنجاح!";
        } else {
            $error = "خطأ أثناء الإضافة.";
        }
    }
}

// Handle Delete Supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)$_POST['id'];
    if ($conn->query("DELETE FROM fournisseur WHERE id = $id")) {
        $success = "تم حذف المورّد بنجاح!";
    } else {
        $error = "خطأ أثناء الحذف.";
    }
}

// Handle Edit Supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = (int)$_POST['id'];
    $nom = trim($_POST['nom']);
    $telephone = trim($_POST['telephone'] ?? '');
    $mon_credit = (float)($_POST['mon_credit'] ?? 0);
    $mon_versement_total = (float)($_POST['mon_versement_total'] ?? 0);

    if (empty($nom)) {
        $error = "اسم المورّد مطلوب";
    } else {
        $stmt = $conn->prepare("UPDATE fournisseur SET nom = ?, telephone = ?, mon_credit = ?, mon_versement_total = ? WHERE id = ?");
        $stmt->bind_param('ssddi', $nom, $telephone, $mon_credit, $mon_versement_total, $id);

        if ($stmt->execute()) {
            $success = "تم تعديل المورّد بنجاح!";
        } else {
            $error = "خطأ أثناء التعديل.";
        }
        $stmt->close();
    }
}

// Nouveau versement fournisseur : mon_versement_total += montant, mon_credit -= montant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_versement') {
    $id = (int)($_POST['id'] ?? 0);
    $nouveau = max(0.0, (float)($_POST['nouveau_versement'] ?? 0));
    if ($id <= 0 || $nouveau <= 0) {
        $error = "مبلغ الدفع غير صالح.";
    } else {
        $stmt = $conn->prepare("UPDATE fournisseur SET mon_versement_total = mon_versement_total + ?, mon_credit = mon_credit - ? WHERE id = ?");
        $stmt->bind_param('ddi', $nouveau, $nouveau, $id);
        if ($stmt->execute()) {
            $success = "تم تسجيل الدفع: زيادة إجمالي الدفعات ونقص الدين.";
        } else {
            $error = "خطأ أثناء تسجيل الدفع.";
        }
        $stmt->close();
    }
}

// Get search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get all suppliers or search results
if (!empty($search)) {
    $search_like = "%$search%";
    $stmt = $conn->prepare("SELECT id, nom, telephone, mon_credit, mon_versement_total FROM fournisseur WHERE nom LIKE ? ORDER BY nom ASC");
    $stmt->bind_param("s", $search_like);
    $stmt->execute();
    $fournisseurs = $stmt->get_result();
} else {
    $fournisseurs = $conn->query("SELECT id, nom, telephone, mon_credit, mon_versement_total FROM fournisseur ORDER BY nom ASC");
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>المورّدون — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1e3a5f">

    <style>
        /* ===================== VARIABLES CSS - Bleu Nuit & Bleu Ciel ===================== */
        :root {
            --primary: #1e3a5f;      /* Bleu nuit profond */
            --primary-dark: #0f2a45;  /* Bleu nuit plus foncé */
            --primary-light: #3b82f6; /* Bleu ciel */
            --secondary: #64748b;
            --success: #10b981;
            --success-dark: #059669;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f0f9ff;        /* Bleu très clair / blanc cassé */
            --border: #b8d4f0;       /* Bleu ciel pâle */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
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

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #0f2a45 0%, #1e3a5f 50%, #3b82f6 100%);
            min-height: 100vh;
            padding: 2rem 1rem;
        }

        /* ===================== CONTAINER ===================== */
        .container {
            max-width: 1280px;
            margin: 0 auto;
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

        /* ===================== HEADER ===================== */
        .header {
            margin-bottom: 2rem;
            text-align: center;
        }

        .header h1 {
            font-size: 2rem;
            color: white;
            margin-bottom: 0.5rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .header p {
            color: rgba(255,255,255,0.9);
            font-size: 0.95rem;
        }

        /* ===================== CARDS ===================== */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h2::before {
            content: '';
            width: 4px;
            height: 20px;
            background: #3b82f6;
            border-radius: 2px;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* ===================== FORM ===================== */
        .form-group {
            margin-bottom: 1.25rem;
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

        /* ===================== BUTTONS ===================== */
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

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, var(--success-dark) 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, var(--warning-dark) 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            color: white;
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--dark);
        }

        .btn-outline:hover {
            background: var(--light);
        }

        .btn-block {
            width: 100%;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        /* ===================== SEARCH BAR ===================== */
        .search-container {
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 0.625rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-results-info {
            margin-bottom: 1rem;
            padding: 0.5rem 0;
            color: var(--gray);
            font-size: 0.875rem;
            border-bottom: 1px solid var(--border);
        }

        /* ===================== TABLE ===================== */
        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
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

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: var(--light);
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        /* ===================== ALERTS ===================== */
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

        .alert-danger {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }

        .error-container {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: var(--radius-lg);
            max-width: 500px;
            margin: 3rem auto;
        }

        .error-container h2 {
            color: var(--danger);
            margin-bottom: 1rem;
        }

        /* ===================== EMPTY STATE ===================== */
        .empty-state {
            text-align: center;
            padding: 3rem;
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 1rem;
            opacity: 0.5;
            stroke: var(--primary);
        }

        .empty-state p {
            color: var(--gray);
            margin-bottom: 1rem;
        }

        /* ===================== MODAL ===================== */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 0;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }

        .close-btn {
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: opacity 0.2s;
            line-height: 1;
        }

        .close-btn:hover {
            opacity: 0.8;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* ===================== RESPONSIVE ===================== */
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .card-body {
                padding: 1rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .actions {
                flex-direction: column;
            }

            .btn-sm {
                width: 100%;
                text-align: center;
            }

            thead th, tbody td {
                padding: 0.75rem 0.5rem;
            }

            .search-container {
                flex-direction: column;
            }

            .modal-content {
                margin: 20% auto;
                width: 95%;
            }
        }

        @media (max-width: 480px) {
            .card-header {
                padding: 1rem;
            }

            .card-header h2 {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🏬 إدارة المورّدين</h1>
        <p>إدارة المورّدين وبيانات الاتصال</p>
    </div>

    <!-- Messages d'alerte -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <span>✓</span>
            <span><?= htmlspecialchars($success) ?></span>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <span>⚠</span>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Formulaire Ajout -->
    <div class="card">
        <div class="card-header">
            <h2>➕ إضافة مورّد</h2>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label for="nom" class="required">اسم المورّد</label>
                    <input type="text" id="nom" name="nom" placeholder="مثال: شركة..." required>
                </div>

                <div class="form-group">
                    <label for="telephone">الهاتف</label>
                    <input type="tel" id="telephone" name="telephone" placeholder="مثال: 0555...">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">➕ إضافة المورّد</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des fournisseurs -->
    <div class="card">
        <div class="card-header">
            <h2>📋 قائمة المورّدين</h2>
        </div>
        <div class="card-body">
            <!-- Barre de recherche -->
            <div class="search-container">
                <form method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; width: 100%;">
                    <input type="text" name="search" class="search-input" 
                           placeholder="🔍 بحث بالاسم..." 
                           value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary">بحث</button>
                    <?php if (!empty($search)): ?>
                        <a href="fournisseurs.php" class="btn btn-secondary">إعادة ضبط</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!empty($search)): ?>
                <div class="search-results-info">
                    📌 نتائج «<strong><?= htmlspecialchars($search) ?></strong>» (<?= $fournisseurs->num_rows ?> نتيجة)
                </div>
            <?php endif; ?>

            <?php if ($fournisseurs->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>اسم المورّد</th>
                                <th>الهاتف</th>
                                <th>الدين الحالي</th>
                                <th>إجمالي الدفعات</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($f = $fournisseurs->fetch_assoc()): ?>
                            <tr>
                                <td>#<?= $f['id'] ?></td>
                                <td><strong><?= htmlspecialchars($f['nom']) ?></strong></td>
                                <td><?= htmlspecialchars($f['telephone'] ?? 'غير محدد') ?></td>
                                <td><?= number_format((float)($f['mon_credit'] ?? 0), 2, ',', ' ') ?> دج</td>
                                <td><?= number_format((float)($f['mon_versement_total'] ?? 0), 2, ',', ' ') ?> دج</td>
                                <td class="actions">
                                    <button type="button" class="btn btn-success btn-sm" 
                                            onclick="openVersementModal(<?= (int)$f['id'] ?>, '<?= htmlspecialchars($f['nom'], ENT_QUOTES) ?>')">
                                        💰 دفعة
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm" 
                                            onclick="openEditModal(<?= (int)$f['id'] ?>, '<?= htmlspecialchars($f['nom'], ENT_QUOTES) ?>', '<?= htmlspecialchars($f['telephone'] ?? '', ENT_QUOTES) ?>', <?= json_encode((float)($f['mon_credit'] ?? 0)) ?>, <?= json_encode((float)($f['mon_versement_total'] ?? 0)) ?>)">
                                        ✏️ تعديل
                                    </button>
                                    
                                    <form method="POST" style="display:inline;" 
                                          onsubmit="return confirm('⚠️ حذف هذا المورّد؟ لا يمكن التراجع.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ حذف</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" stroke="currentColor" stroke-linecap="round"/>
                        <path d="M17 21v-4H7v4M9 9h6" stroke="currentColor" stroke-linecap="round"/>
                    </svg>
                    <p>لا يوجد مورّدون</p>
                    <p>استخدم النموذج أعلاه لإضافة أول مورّد.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lien retour -->
    <div style="text-align: center; margin-top: 1rem;">
        <a href="dashboard.php" class="btn btn-secondary">← العودة إلى لوحة التحكم</a>
    </div>
</div>

<!-- Modal pour modifier un fournisseur -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>✏️ تعديل المورّد</h3>
            <span class="close-btn" onclick="closeEditModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="edit_nom" class="required">اسم المورّد</label>
                    <input type="text" id="edit_nom" name="nom" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_telephone">الهاتف</label>
                    <input type="text" id="edit_telephone" name="telephone">
                </div>

                <div class="form-group">
                    <label for="edit_mon_credit">ديني (دج)</label>
                    <input type="number" step="0.01" id="edit_mon_credit" name="mon_credit" value="0">
                </div>

                <div class="form-group">
                    <label for="edit_mon_versement_total">إجمالي دفعاتي (دج)</label>
                    <input type="number" step="0.01" id="edit_mon_versement_total" name="mon_versement_total" value="0">
                </div>
                <p style="font-size:0.8rem;color:var(--gray);margin-top:-0.5rem;">تعديل مباشر. لدفعة تزيد الإجمالي وتنقص الدين استخدم «دفعة».</p>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeEditModal()">إلغاء</button>
                <button type="submit" class="btn btn-success">💾 حفظ</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal nouveau versement fournisseur -->
<div id="versementModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>💰 دفعة جديدة</h3>
            <span class="close-btn" onclick="closeVersementModal()">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_versement">
            <input type="hidden" name="id" id="versement_fournisseur_id">
            <div class="modal-body">
                <p style="margin-bottom:1rem;">المورّد: <strong id="versement_fournisseur_name"></strong></p>
                <p style="font-size:0.85rem;color:var(--gray);margin-bottom:1rem;">إجمالي دفعاتي += المبلغ · ديني -= المبلغ</p>
                <div class="form-group">
                    <label for="nouveau_versement_f" class="required">المبلغ (دج)</label>
                    <input type="number" step="0.01" name="nouveau_versement" id="nouveau_versement_f" min="0.01" required placeholder="0.00">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeVersementModal()">إلغاء</button>
                <button type="submit" class="btn btn-success">✅ تسجيل الدفعة</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(id, nom, telephone, monCredit, monVersementTotal) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_nom').value = nom;
        document.getElementById('edit_telephone').value = telephone;
        document.getElementById('edit_mon_credit').value = monCredit != null ? Number(monCredit) : 0;
        document.getElementById('edit_mon_versement_total').value = monVersementTotal != null ? Number(monVersementTotal) : 0;
        document.getElementById('editModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    function openVersementModal(id, nom) {
        document.getElementById('versement_fournisseur_id').value = id;
        document.getElementById('versement_fournisseur_name').textContent = nom;
        document.getElementById('nouveau_versement_f').value = '';
        document.getElementById('versementModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeVersementModal() {
        document.getElementById('versementModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    // Fermer le modal en cliquant à l'extérieur
    window.onclick = function(event) {
        var modal = document.getElementById('editModal');
        var vModal = document.getElementById('versementModal');
        if (event.target == modal) {
            closeEditModal();
        }
        if (event.target == vModal) {
            closeVersementModal();
        }
    }

    // Fermer avec la touche Echap
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeEditModal();
            closeVersementModal();
        }
    });
</script>

</body>
</html>
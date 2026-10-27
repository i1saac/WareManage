<?php
header('Content-Type: text/html; charset=utf-8');
// clients.php - Manage Clients (Mainly for Admin)
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
    echo "<div class='error-container'><h2>⛔ الوصول مرفوض</h2><p>يمكن للمسؤول فقط إدارة العملاء.</p><a href='dashboard.php' class='btn-primary'>العودة إلى لوحة التحكم</a></div>";
    exit();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $type = $_POST['type'] ?? 'detail';
        $stmt = $conn->prepare("UPDATE client SET approved = 1, type = ? WHERE id = ?");
        $stmt->bind_param("si", $type, $client_id);
        $stmt->execute();
        echo "<script>alert('تم اعتماد العميل بنجاح!');</script>";
    }

    if ($action === 'delete') {
        $conn->query("DELETE FROM client WHERE id = $client_id");
        echo "<script>alert('تم حذف العميل!');</script>";
    }

    if ($action === 'edit') {
        $nom = trim($_POST['nom'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $type = $_POST['type'] ?? 'detail';
        $credit = (float)($_POST['credit'] ?? 0);
        $versement_total = (float)($_POST['versement_total'] ?? 0);
        if ($nom === '' || $client_id <= 0) {
            echo "<script>alert('بيانات غير صالحة.');</script>";
        } else {
            $stmt = $conn->prepare("UPDATE client SET nom = ?, telephone = ?, type = ?, credit = ?, versement_total = ? WHERE id = ?");
            $stmt->bind_param('sssddi', $nom, $telephone, $type, $credit, $versement_total, $client_id);
            $stmt->execute();
            $stmt->close();
            echo "<script>alert('تم تحديث بيانات العميل!');</script>";
        }
    }

    if ($action === 'add_versement') {
        $nouveau = max(0.0, (float)($_POST['nouveau_versement'] ?? 0));
        if ($client_id <= 0 || $nouveau <= 0) {
            echo "<script>alert('مبلغ الدفع غير صالح.');</script>";
        } else {
            $stmt = $conn->prepare("UPDATE client SET versement_total = versement_total + ?, credit = credit - ? WHERE id = ?");
            $stmt->bind_param('ddi', $nouveau, $nouveau, $client_id);
            if ($stmt->execute()) {
                echo "<script>alert('تم تسجيل الدفع: زيادة إجمالي الدفعات ونقص الدين.');</script>";
            }
            $stmt->close();
        }
    }

    if ($action === 'create') {
        $nom = $_POST['nom'];
        $telephone = $_POST['telephone'];
        $type = $_POST['type'] ?? 'detail';
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // Check if name exists
        $check = $conn->query("SELECT id FROM client WHERE nom = '$nom'");
        if ($check->num_rows > 0) {
            echo "<script>alert('هذا الاسم مستخدم مسبقاً!');</script>";
        } else {
            $stmt = $conn->prepare("INSERT INTO client (nom, telephone, password, approved, type) VALUES (?, ?, ?, 1, ?)");
            $stmt->bind_param("ssss", $nom, $telephone, $password, $type);
            if ($stmt->execute()) {
                echo "<script>alert('تم إنشاء العميل بنجاح!');</script>";
            }
        }
    }
}

// Get search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get all clients or search results
if (!empty($search)) {
    $search_like = "%$search%";
    $stmt = $conn->prepare("SELECT id, nom, telephone, credit, versement_total, approved, type, created_at 
                            FROM client 
                            WHERE nom LIKE ? 
                            ORDER BY created_at DESC");
    $stmt->bind_param("s", $search_like);
    $stmt->execute();
    $clients = $stmt->get_result();
} else {
    $clients = $conn->query("SELECT id, nom, telephone, credit, versement_total, approved, type, created_at 
                            FROM client 
                            ORDER BY created_at DESC");
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>العملاء — NettoClean</title>
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
            color: var(--dark);
        }

        /* ===================== HEADER ===================== */
        header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: white;
            padding: 1rem 2rem;
            text-align: center;
            font-size: 1.5rem;
            font-weight: bold;
            box-shadow: var(--shadow-lg);
        }

        /* ===================== CONTAINER ===================== */
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

        /* ===================== CARDS ===================== */
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

        /* ===================== FORMULAIRES ===================== */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: flex-end;
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

        /* ===================== BOUTONS ===================== */
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

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
        }

        /* ===================== RECHERCHE ===================== */
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

        /* ===================== TABLEAU ===================== */
        .table-responsive {
            overflow-x: auto;
            margin-top: 1rem;
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

        /* ===================== STATUT ===================== */
        .status {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-approved {
            background: #d1fae5;
            color: var(--success-dark);
        }

        .status-pending {
            background: #fef3c7;
            color: var(--warning-dark);
        }

        /* ===================== ACTIONS ===================== */
        .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            overflow-y: auto;
            padding: 2rem 0;
            box-sizing: border-box;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: white;
            margin: 0 auto;
            padding: 0;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            max-height: calc(100vh - 4rem);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: slideUp 0.3s ease-out;
        }

        .modal-content > form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
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
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            flex-shrink: 0;
        }

        .modal-header h2, .modal-header h3 {
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
            overflow-y: auto;
            flex: 1 1 auto;
        }

        .modal-footer {
            padding: 1rem 1.5rem 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-shrink: 0;
            border-top: 1px solid var(--border, #e5e7eb);
            background: white;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
        }

        /* ===================== MESSAGES D'ERREUR ===================== */
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

        /* ===================== LIEN RETOUR ===================== */
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

        /* ===================== RESPONSIVE ===================== */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .card {
                padding: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
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
                width: 95%;
                max-height: calc(100vh - 2rem);
            }

            .modal {
                padding: 1rem 0;
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
    </style>
</head>
<body class="nc-app-body">

    <header>
        👥 إدارة العملاء
    </header>

    <div class="container">

        <!-- Section: Créer un Client Manuellement -->
        <div class="card">
            <h2>➕ إنشاء عميل يدوياً</h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="create_nom" class="required">اسم العميل</label>
                        <input type="text" id="create_nom" name="nom" placeholder="مثال: أحمد" required>
                    </div>
                    <div class="form-group">
                        <label for="create_telephone">الهاتف</label>
                        <input type="text" id="create_telephone" name="telephone" placeholder="مثال: +213...">
                    </div>
                    <div class="form-group">
                        <label for="create_type" class="required">نوع العميل</label>
                        <select id="create_type" name="type" required>
                            <option value="detail">تجزئة</option>
                            <option value="gross">جملة</option>
                            <option value="supergross">سوبر جملة</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="create_password" class="required">كلمة المرور</label>
                        <input type="password" id="create_password" name="password" placeholder="كلمة المرور" required>
                    </div>
                    <div class="form-group">
                        <input type="hidden" name="action" value="create">
                        <button type="submit" class="btn btn-success" style="margin-top: 1.5rem;">➕ إنشاء عميل</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Liste des clients -->
        <div class="card">
            <h2>📋 قائمة العملاء</h2>
            
            <!-- Barre de recherche -->
            <div class="search-container">
                <form method="GET" style="display: flex; gap: 0.75rem; flex-wrap: wrap; width: 100%;">
                    <input type="text" name="search" class="search-input" 
                           placeholder="🔍 بحث بالاسم..." 
                           value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary">بحث</button>
                    <?php if (!empty($search)): ?>
                        <a href="clients.php" class="btn btn-secondary">إعادة ضبط</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!empty($search)): ?>
                <div class="search-results-info">
                    📌 نتائج البحث عن «<strong><?= htmlspecialchars($search) ?></strong>»
                    (<?= $clients->num_rows ?> نتيجة)
                </div>
            <?php endif; ?>
            
            <?php if ($clients->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>الاسم</th>
                                <th>الهاتف</th>
                                <th>النوع</th>
                                <th>الدين</th>
                                <th>إجمالي الدفعات</th>
                                <th>الحالة</th>
                                <th>تاريخ الإنشاء</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($client = $clients->fetch_assoc()): ?>
                            <tr>
                                <td>#<?= $client['id'] ?></td>
                                <td><strong><?= htmlspecialchars($client['nom']) ?></strong></td>
                                <td><?= htmlspecialchars($client['telephone'] ?? 'غير محدد') ?></td>
                                <td>
                                    <?php
                                        $type = $client['type'] ?? 'detail';
                                        $type_labels = [
                                            'supergross' => '🏢 سوبر جملة',
                                            'gross' => '📦 جملة',
                                            'detail' => '🛍️ تجزئة'
                                        ];
                                        echo $type_labels[$type] ?? $type;
                                    ?>
                                </td>
                                <td><?= number_format($client['credit'] ?? 0, 2) ?> DA</td>
                                <td><?= number_format($client['versement_total'] ?? 0, 2) ?> DA</td>
                                <td>
                                    <?php if ($client['approved'] == 1): ?>
                                        <span class="status status-approved">✅ معتمد</span>
                                    <?php else: ?>
                                        <span class="status status-pending">⏳ قيد الانتظار</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($client['created_at'])) ?></td>
                                <td class="actions">
                                    <button type="button" class="btn btn-warning btn-sm" 
                                            onclick="openCommandesModal(<?= $client['id'] ?>, '<?= htmlspecialchars($client['nom']) ?>')">
                                        📋 الطلبات
                                    </button>

                                    <button type="button" class="btn btn-success btn-sm" 
                                            onclick="openVersementModal(<?= (int)$client['id'] ?>, '<?= htmlspecialchars($client['nom'], ENT_QUOTES) ?>')">
                                        💰 دفعة
                                    </button>

                                    <button type="button" class="btn btn-warning btn-sm" 
                                            onclick="openEditModal(<?= (int)$client['id'] ?>, '<?= htmlspecialchars($client['nom'], ENT_QUOTES) ?>', '<?= htmlspecialchars($client['telephone'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($client['type'] ?? 'detail', ENT_QUOTES) ?>', <?= json_encode((float)($client['credit'] ?? 0)) ?>, <?= json_encode((float)($client['versement_total'] ?? 0)) ?>)">
                                        ✏️ تعديل
                                    </button>

                                    <?php if ($client['approved'] == 0): ?>
                                        <button type="button" class="btn btn-success btn-sm" 
                                                onclick="openApproveModal(<?= $client['id'] ?>, '<?= htmlspecialchars($client['nom']) ?>')">
                                            ✅ اعتماد
                                        </button>
                                    <?php endif; ?>

                                    <form method="POST" style="display:inline;" 
                                          onsubmit="return confirm('⚠️ حذف هذا العميل نهائياً؟ لا يمكن التراجع.');">
                                        <input type="hidden" name="client_id" value="<?= $client['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
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
                        <path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <p>لا يوجد عملاء مسجّلون حالياً.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="back-link"   >
            <a href="dashboard.php"   style="position: fixed;top:50px;left:30px; background-color:#0f2a45">← العودة إلى لوحة التحكم</a>
        </div>

    </div>

    <!-- Modal pour modifier un client -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ تعديل العميل</h2>
                <span class="close-btn" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="client_id" id="edit_client_id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_nom" class="required">اسم العميل</label>
                        <input type="text" id="edit_nom" name="nom" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_telephone">الهاتف</label>
                        <input type="text" id="edit_telephone" name="telephone">
                    </div>

                    <div class="form-group">
                        <label for="edit_type" class="required">نوع العميل</label>
                        <select id="edit_type" name="type" required>
                            <option value="detail">تجزئة</option>
                            <option value="gross">جملة</option>
                            <option value="supergross">سوبر جملة</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit_credit">الدين (دج)</label>
                        <input type="number" step="0.01" id="edit_credit" name="credit" value="0">
                    </div>

                    <div class="form-group">
                        <label for="edit_versement_total">إجمالي الدفعات (دج)</label>
                        <input type="number" step="0.01" id="edit_versement_total" name="versement_total" value="0">
                    </div>
                    <p style="font-size:0.8rem;color:var(--gray);margin-top:-0.5rem;">تعديل مباشر للأرصدة. لدفعة جديدة دون استبدال المبالغ استخدم «دفعة».</p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">إلغاء</button>
                    <button type="submit" class="btn btn-success">💾 حفظ</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal nouveau versement client -->
    <div id="versementModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>💰 دفعة جديدة</h2>
                <span class="close-btn" onclick="closeVersementModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_versement">
                <input type="hidden" name="client_id" id="versement_client_id">
                <div class="modal-body">
                    <p style="margin-bottom:1rem;">العميل: <strong id="versement_client_name"></strong></p>
                    <p style="font-size:0.85rem;color:var(--gray);margin-bottom:1rem;">إجمالي الدفعات += المبلغ · الدين -= المبلغ</p>
                    <div class="form-group">
                        <label for="nouveau_versement" class="required">المبلغ (دج)</label>
                        <input type="number" step="0.01" name="nouveau_versement" id="nouveau_versement" min="0.01" required placeholder="0.00">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeVersementModal()">إلغاء</button>
                    <button type="submit" class="btn btn-success">✅ تسجيل الدفعة</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal pour approuver un client -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✅ اعتماد العميل</h2>
                <span class="close-btn" onclick="closeApproveModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="client_id" id="approve_client_id">
                
                <div class="modal-body">
                    <p style="margin-bottom: 1rem;">اختر نوع العميل لـ <strong><span id="approve_client_name"></span></strong>:</p>
                    
                    <div class="form-group">
                        <label for="approve_type" class="required">نوع العميل</label>
                        <select id="approve_type" name="type" required>
                            <option value="detail">🛍️ تجزئة</option>
                            <option value="gross">📦 جملة</option>
                            <option value="supergross">🏢 سوبر جملة</option>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">إلغاء</button>
                    <button type="submit" class="btn btn-success">✅ اعتماد وتأكيد</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal pour voir les commandes du client -->
    <div id="commandesModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>طلبات <span id="clientNameCommandes"></span></h3>
                <span class="close-btn" onclick="closeCommandesModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="commandesLoading" style="text-align: center; padding: 20px;">
                    <p style="color: var(--gray);">جاري تحميل الطلبات...</p>
                </div>
                <div id="commandesContent" style="display: none;"></div>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(clientId, nom, telephone, type, credit, versementTotal) {
            document.getElementById('edit_client_id').value = clientId;
            document.getElementById('edit_nom').value = nom;
            document.getElementById('edit_telephone').value = telephone;
            document.getElementById('edit_type').value = type || 'detail';
            document.getElementById('edit_credit').value = credit != null ? Number(credit) : 0;
            document.getElementById('edit_versement_total').value = versementTotal != null ? Number(versementTotal) : 0;
            document.getElementById('editModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openVersementModal(clientId, nom) {
            document.getElementById('versement_client_id').value = clientId;
            document.getElementById('versement_client_name').textContent = nom;
            document.getElementById('nouveau_versement').value = '';
            document.getElementById('versementModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeVersementModal() {
            document.getElementById('versementModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openCommandesModal(clientId, clientName) {
            document.getElementById('clientNameCommandes').textContent = clientName;
            document.getElementById('commandesModal').style.display = 'block';
            document.getElementById('commandesLoading').style.display = 'block';
            document.getElementById('commandesContent').style.display = 'none';
            document.body.style.overflow = 'hidden';

            // Charger les commandes via AJAX
            fetch('get_commandes.php?client_id=' + clientId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('commandesLoading').style.display = 'none';
                    document.getElementById('commandesContent').innerHTML = data;
                    document.getElementById('commandesContent').style.display = 'block';
                })
                .catch(error => {
                    document.getElementById('commandesLoading').innerHTML = '<p style="color: var(--danger);">تعذّر تحميل الطلبات.</p>';
                });
        }

        function closeCommandesModal() {
            document.getElementById('commandesModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openApproveModal(clientId, clientName) {
            document.getElementById('approve_client_id').value = clientId;
            document.getElementById('approve_client_name').textContent = clientName;
            document.getElementById('approve_type').value = 'detail';
            document.getElementById('approveModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        window.onclick = function(event) {
            var editModal = document.getElementById('editModal');
            var commandesModal = document.getElementById('commandesModal');
            var approveModal = document.getElementById('approveModal');
            var versementModal = document.getElementById('versementModal');
            if (event.target == editModal) {
                closeEditModal();
            }
            if (event.target == commandesModal) {
                closeCommandesModal();
            }
            if (event.target == approveModal) {
                closeApproveModal();
            }
            if (event.target == versementModal) {
                closeVersementModal();
            }
        }

        // Fermer avec la touche Echap
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeEditModal();
                closeCommandesModal();
                closeApproveModal();
                closeVersementModal();
            }
        });
    </script>

</body>
</html>
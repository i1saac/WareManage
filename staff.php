<a href="./dashboard.php" class="btn-home">العودة للرئيسية</a>

<style>
    .btn-home {
        display: inline-block;
        padding: 12px 28px;
        font-size: 16px;
        font-weight: 600;
        color: white;
        text-decoration: none;
        border-radius: 30px;
        background: linear-gradient(135deg, #1e3a5f, #3b82f6);
        box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
        position: fixed;
        overflow: hidden;
    }

    .btn-home:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 12px 25px rgba(0,0,0,0.3);
    }

    .btn-home:active {
        transform: scale(0.95);
    }

    .btn-home::before {
        content: "";
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(120deg, transparent, rgba(255,255,255,0.6), transparent);
        transition: 0.5s;
    }

    .btn-home:hover::before {
        left: 100%;
    }
</style>

<?php
session_start();
require_once 'config.php';

// Vérification connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'];

// Vérifier admin
if ($is_client || $user_type !== 'admin') {
    die("<div class='error-container'><h2>الوصول مرفوض</h2><p>ليست لديك الصلاحيات.</p><a href='dashboard.php' class='btn-primary'>العودة إلى لوحة التحكم</a></div>");
}

// =====================
// ➤ AJOUT
// =====================
if (isset($_POST['add'])) {
    $nom = trim($_POST['nom']);
    $password = trim($_POST['password']);
    $post = strtolower(trim($_POST['post'] ?? 'vendeur'));
    if (!in_array($post, ['vendeur', 'admin'], true)) {
        $post = 'vendeur';
    }
    $credit = max(0, floatval($_POST['credit'] ?? 0));
    $salaire_par_jour = max(0, floatval($_POST['salaire_par_jour'] ?? 0));
    $nombre_d_absence = max(0, intval($_POST['nombre_d_absence'] ?? 0));
    $salaire_par_mois = max(0, floatval($_POST['salaire_par_mois'] ?? 0));

    if ($salaire_par_jour <= 0 && $salaire_par_mois > 0) {
        $salaire_par_jour = $salaire_par_mois / 30;
    }

    if ($salaire_par_mois > 0) {
        $salaire_par_mois = max(0, $salaire_par_mois - $salaire_par_jour * $nombre_d_absence);
    } else {
        $salaire_par_mois = $salaire_par_jour * max(0, 30 - $nombre_d_absence);
    }

    if (!empty($nom) && !empty($password)) {

        $check = $conn->prepare("SELECT id FROM stuff WHERE nom=? ");
        $check->bind_param("s", $nom);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $error = "الاسم مستخدم مسبقاً";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO stuff(nom,password,post,credit,salaire_par_jour,nombre_d_absence,salaire_par_mois) VALUES(?,?,?,?,?,?,?)");
            $stmt->bind_param("sssddid", $nom, $hashed, $post, $credit, $salaire_par_jour, $nombre_d_absence, $salaire_par_mois);
            
            if ($stmt->execute()) {
                $success = "تمت إضافة الموظف بنجاح";
            } else {
                $error = "خطأ أثناء الإضافة";
            }
        }
    } else {
        $error = "يرجى تعبئة جميع الحقول";
    }
}

// =====================
// ➤ SUPPRIMER
// =====================
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $stmt = $conn->prepare("DELETE FROM stuff WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: staff.php");
    exit();
}

// =====================
// ➤ VERSEMENT CRÉDIT / SALAIRE (vendeur)
// =====================
if (isset($_POST['apply_versement_credit'])) {
    $id = intval($_POST['vendeur_id'] ?? 0);
    $amount = max(0, floatval($_POST['versement_credit'] ?? 0));
    if ($id > 1 && $amount > 0) {
        $exists = $conn->prepare("SELECT id FROM stuff WHERE id = ? AND post = 'vendeur'");
        $exists->bind_param("i", $id);
        $exists->execute();
        if ($exists->get_result()->num_rows === 0) {
            $error = "البائع غير موجود";
        } else {
            $stmt = $conn->prepare("UPDATE stuff SET credit = GREATEST(0, credit - ?) WHERE id = ? AND post = 'vendeur'");
            $stmt->bind_param("di", $amount, $id);
            if ($stmt->execute()) {
                $success = "تم تسجيل دفعة الدين (تم تحديث الدين)";
            } else {
                $error = "خطأ أثناء الحفظ";
            }
        }
    } else {
        $error = $amount <= 0 ? "يجب أن يكون المبلغ أكبر من 0" : "إجراء غير مسموح لهذا الحساب";
    }
}

if (isset($_POST['apply_versement_salaire'])) {
    $id = intval($_POST['vendeur_id'] ?? 0);
    $amount = max(0, floatval($_POST['versement_salaire'] ?? 0));
    if ($id > 1 && $amount > 0) {
        $exists = $conn->prepare("SELECT id FROM stuff WHERE id = ? AND post = 'vendeur'");
        $exists->bind_param("i", $id);
        $exists->execute();
        if ($exists->get_result()->num_rows === 0) {
            $error = "البائع غير موجود";
        } else {
            $stmt = $conn->prepare("UPDATE stuff SET salaire_par_mois = GREATEST(0, salaire_par_mois - ?) WHERE id = ? AND post = 'vendeur'");
            $stmt->bind_param("di", $amount, $id);
            if ($stmt->execute()) {
                $success = "تم تسجيل دفعة الراتب (تم تحديث راتب الشهر)";
            } else {
                $error = "خطأ أثناء الحفظ";
            }
        }
    } else {
        $error = $amount <= 0 ? "يجب أن يكون المبلغ أكبر من 0" : "إجراء غير مسموح لهذا الحساب";
    }
}

// =====================
// ➤ MODIFIER
// =====================
if (isset($_POST['update'])) {
    $id = intval($_POST['id']);
    $nom = trim($_POST['nom']);
    $post = strtolower(trim($_POST['post'] ?? 'vendeur'));
    if (!in_array($post, ['vendeur', 'admin'], true)) {
        $post = 'vendeur';
    }
    $credit = max(0, floatval($_POST['credit'] ?? 0));
    $salaire_par_jour = max(0, floatval($_POST['salaire_par_jour'] ?? 0));
    $nombre_d_absence = max(0, intval($_POST['nombre_d_absence'] ?? 0));
    $salaire_par_mois = max(0, floatval($_POST['salaire_par_mois'] ?? 0));

    if ($salaire_par_jour <= 0 && $salaire_par_mois > 0) {
        $salaire_par_jour = $salaire_par_mois / 30;
    }

    if ($salaire_par_mois > 0) {
        $salaire_par_mois = max(0, $salaire_par_mois - $salaire_par_jour * $nombre_d_absence);
    } else {
        $salaire_par_mois = $salaire_par_jour * max(0, 30 - $nombre_d_absence);
    }

    if (!empty($nom)) {
        $stmt = $conn->prepare("UPDATE stuff SET nom=?, post=?, credit=?, salaire_par_jour=?, nombre_d_absence=?, salaire_par_mois=? WHERE id=?");
        $stmt->bind_param("ssddidi", $nom, $post, $credit, $salaire_par_jour, $nombre_d_absence, $salaire_par_mois, $id);
        
        if ($stmt->execute()) {
            $success = "تم التعديل بنجاح";
        } else {
            $error = "خطأ أثناء التعديل";
        }
    }
}

// =====================
// ➤ Récupération
// =====================
$result = $conn->query("SELECT * FROM stuff WHERE post IN ('vendeur','admin') ORDER BY id DESC");

// Pour édition
$edit_data = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $res = $conn->query("SELECT * FROM stuff WHERE id=$id AND post IN ('vendeur','admin')");
    $edit_data = $res->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>إدارة الموظفين — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
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
            --light: #f0f9ff;        /* Bleu très clair / blanc cassé */
            --border: #b8d4f0;       /* Bleu ciel pâle */
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
            --radius-lg: 0.75rem;
        }

        /* ===================== RESET & BASE ===================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body.nc-app-body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
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

        .btn-warning:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: var(--secondary);
            color: white;
        }

        .btn-block {
            width: 100%;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        /* ===================== TABLE ===================== */
        .table-responsive {
            overflow-x: auto;
            margin: -1.5rem;
            padding: 0 1.5rem 1.5rem 1.5rem;
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

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-admin {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .badge-vendeur {
            background: linear-gradient(135deg, #3b82f6 0%, #1e3a5f 100%);
            color: white;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .versement-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.35rem;
        }

        .versement-form input[type="number"] {
            width: 6.5rem;
            min-width: 0;
            padding: 0.35rem 0.5rem;
            font-size: 0.75rem;
        }

        .versement-cell {
            min-width: 140px;
            vertical-align: top;
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

            table {
                font-size: 0.75rem;
            }

            thead th, tbody td {
                padding: 0.75rem 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .card-header {
                padding: 1rem;
            }

            .card-header h2 {
                font-size: 1rem;
            }

            .badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.5rem;
            }
        }

        /* ===================== PRINT STYLES ===================== */
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .btn, .actions, .form-actions {
                display: none;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid var(--border);
            }
        }
    </style>
</head>
<body class="nc-app-body">

<div class="container">
    <div class="header">
        <h1>👥 إدارة الموظفين</h1>
        <p>إدارة الحسابات والصلاحيات</p>
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

    <!-- Formulaire Ajout / Modification -->
    <div class="card">
        <div class="card-header">
            <h2><?= $edit_data ? "✏️ تعديل موظف" : "➕ إضافة موظف" ?></h2>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="id" value="<?= $edit_data['id'] ?? '' ?>">

                <div class="form-group">
                    <label for="nom" class="required">الاسم الكامل</label>
                    <input type="text" id="nom" name="nom" placeholder="مثال: أحمد"
                           value="<?= htmlspecialchars($edit_data['nom'] ?? '') ?>" required>
                </div>

                <?php if (!$edit_data): ?>
                    <div class="form-group">
                        <label for="password" class="required">كلمة المرور</label>
                        <input type="password" id="password" name="password" 
                               placeholder="6 أحرف على الأقل" required>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="post" class="required">المنصب</label>
                    <select id="post" name="post" required>
                        <option value="vendeur" <?= (($edit_data['post'] ?? 'vendeur') === 'vendeur') ? 'selected' : '' ?>>🛒 بائع</option>
                        <option value="admin" <?= (($edit_data['post'] ?? '') === 'admin') ? 'selected' : '' ?>>🛡️ Admin</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="credit">الدين</label>
                    <input type="number" step="0.01" id="credit" name="credit" placeholder="0.00"
                           value="<?= htmlspecialchars($edit_data['credit'] ?? '0.00') ?>">
                </div>

                <div class="form-group">
                    <label for="salaire_par_jour">أجر اليوم</label>
                    <input type="number" step="0.01" id="salaire_par_jour" name="salaire_par_jour" placeholder="0.00"
                           value="<?= htmlspecialchars($edit_data['salaire_par_jour'] ?? '0.00') ?>">
                </div>

                <div class="form-group">
                    <label for="nombre_d_absence">عدد أيام الغياب</label>
                    <input type="number" id="nombre_d_absence" name="nombre_d_absence" min="0" placeholder="0"
                           value="<?= htmlspecialchars($edit_data['nombre_d_absence'] ?? '0') ?>">
                </div>

                <div class="form-group">
                    <label for="salaire_par_mois">راتب الشهر</label>
                    <input type="number" step="0.01" id="salaire_par_mois" name="salaire_par_mois" placeholder="0.00"
                           value="<?= htmlspecialchars($edit_data['salaire_par_mois'] ?? '0.00') ?>">
                </div>

                <div class="form-actions">
                    <?php if ($edit_data): ?>
                        <button type="submit" name="update" class="btn btn-success">✅ تحديث</button>
                        <a href="staff.php" class="btn btn-secondary">❌ إلغاء</a>
                    <?php else: ?>
                        <button type="submit" name="add" class="btn btn-primary">➕ إضافة</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste du personnel -->
    <div class="card">
        <div class="card-header">
            <h2>📋 قائمة الموظفين</h2>
        </div>
        <div class="card-body">
            <?php if ($result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>الاسم</th>
                                <th>الدور</th>
                                <th>الدين</th>
                                <th>أجر/يوم</th>
                                <th>غياب</th>
                                <th>راتب/شهر</th>
                                <th>دفعة دين</th>
                                <th>دفعة راتب</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>#<?= $row['id'] ?></td>
                                <td><strong><?= htmlspecialchars($row['nom']) ?></strong></td>
                                <td>
                                    <?php if (($row['post'] ?? 'vendeur') === 'admin'): ?>
                                        <span class="badge badge-admin">🛡️ Admin</span>
                                    <?php else: ?>
                                        <span class="badge badge-vendeur">🛒 بائع</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format((float)($row['credit'] ?? 0), 2, ',', ' ') ?> دج</td>
                                <td><?= number_format((float)($row['salaire_par_jour'] ?? 0), 2, ',', ' ') ?> دج</td>
                                <td><?= intval($row['nombre_d_absence'] ?? 0) ?></td>
                                <td><?= number_format((float)($row['salaire_par_mois'] ?? 0), 2, ',', ' ') ?> دج</td>
                                <td class="versement-cell">
                                    <?php if ((int)$row['id'] !== 1 && ($row['post'] ?? 'vendeur') === 'vendeur'): ?>
                                    <form method="POST" class="versement-form" onsubmit="return confirm('تسجيل هذه الدفعة على دين البائع؟');">
                                        <input type="hidden" name="vendeur_id" value="<?= (int)$row['id'] ?>">
                                        <input type="number" step="0.01" min="0.01" name="versement_credit" placeholder="دج" required title="المبلغ المخصوم من الدين">
                                        <button type="submit" name="apply_versement_credit" class="btn btn-primary btn-sm">OK</button>
                                    </form>
                                    <?php else: ?>
                                    <span class="badge" style="background:#94a3b8;color:#fff;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="versement-cell">
                                    <?php if ((int)$row['id'] !== 1 && ($row['post'] ?? 'vendeur') === 'vendeur'): ?>
                                    <form method="POST" class="versement-form" onsubmit="return confirm('تسجيل هذه الدفعة على راتب الشهر؟');">
                                        <input type="hidden" name="vendeur_id" value="<?= (int)$row['id'] ?>">
                                        <input type="number" step="0.01" min="0.01" name="versement_salaire" placeholder="دج" required title="المبلغ المخصوم من راتب الشهر">
                                        <button type="submit" name="apply_versement_salaire" class="btn btn-success btn-sm">OK</button>
                                    </form>
                                    <?php else: ?>
                                    <span class="badge" style="background:#94a3b8;color:#fff;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                   <?php 
                                   if($row['id']!=1){?>
                                     <a href="staff.php?edit=<?= $row['id'] ?>" class="btn btn-warning btn-sm">
                                        ✏️ تعديل
                                    </a>
                                    <a href="staff.php?delete=<?= $row['id'] ?>"
                                       onclick="return confirm('⚠️ حذف هذا الموظف نهائياً؟ لا يمكن التراجع.')"
                                       class="btn btn-danger btn-sm">
                                        🗑️ حذف
                                    </a>
                                    <?php
                                   }
                                   ?>
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
                    <p>لا يوجد موظفون مسجّلون</p>
                    <p>استخدم النموذج أعلاه لإضافة أول موظف.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
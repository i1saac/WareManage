<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['is_client'] ?? true) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';
$admin_id = $_SESSION['user_id'];

$view = $_GET['view'] ?? 'main';
if (!in_array($view, ['main', 'create_admin', 'add_expense'], true)) {
    $view = 'main';
}

/** Creates the recurring-expenses table on first use if it doesn't exist yet. */
function ensure_recurring_expenses_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `admin_recurring_expenses` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `admin_id` int(11) NOT NULL,
        `name` varchar(150) NOT NULL,
        `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `period` enum('daily','monthly') NOT NULL DEFAULT 'monthly',
        `is_active` tinyint(1) NOT NULL DEFAULT 1,
        `next_due_at` datetime NOT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `admin_id` (`admin_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
ensure_recurring_expenses_table($conn);

/** Advances a datetime by one period ('daily' -> +1 day, 'monthly' -> +1 month). */
function advance_due_date(DateTime $dt, string $period): DateTime {
    $next = clone $dt;
    if ($period === 'daily') {
        $next->modify('+1 day');
    } else {
        $next->modify('+1 month');
    }
    return $next;
}

/** Lazily applies any recurring expenses whose due date has passed (no server cron needed).
 *  Runs on every page load; catches up on any periods missed while nobody visited the page. */
function apply_due_recurring_expenses($conn) {
    $now = new DateTime();
    $result = $conn->query("SELECT * FROM admin_recurring_expenses WHERE is_active = 1 AND next_due_at <= NOW()");
    if (!$result) {
        return;
    }
    while ($rec = $result->fetch_assoc()) {
        $due = new DateTime($rec['next_due_at']);
        $amount = (float)$rec['amount'];
        $iterations = 0;
        while ($due <= $now && $iterations < 500) {
            $stmt = $conn->prepare("INSERT INTO admin_expenses (admin_id, amount, cause) VALUES (?, ?, ?)");
            $cause = 'مصروف تلقائي: ' . $rec['name'];
            $stmt->bind_param('ids', $rec['admin_id'], $amount, $cause);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE admin SET expenses = expenses + ? WHERE id = ?");
            $stmt->bind_param('di', $amount, $rec['admin_id']);
            $stmt->execute();
            $stmt->close();

            $due = advance_due_date($due, $rec['period']);
            $iterations++;
        }
        $stmt = $conn->prepare("UPDATE admin_recurring_expenses SET next_due_at = ? WHERE id = ?");
        $dueStr = $due->format('Y-m-d H:i:s');
        $stmt->bind_param('si', $dueStr, $rec['id']);
        $stmt->execute();
        $stmt->close();
    }
}
apply_due_recurring_expenses($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_admin'])) {
        $nom = trim($_POST['nom']);
        $password = trim($_POST['password']);

        if (empty($nom) || empty($password)) {
            $error = 'يرجى إدخال الاسم وكلمة المرور للمسؤول.';
        } elseif (strlen($password) < 6) {
            $error = 'يجب أن تكون كلمة المرور 6 أحرف على الأقل.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM admin WHERE LOWER(nom) = LOWER(?)");
            $stmt->bind_param('s', $nom);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error = 'اسم المسؤول هذا مستخدم مسبقاً.';
            } else {
                $stmt = $conn->prepare("SELECT id FROM stuff WHERE LOWER(nom) = LOWER(?)");
                $stmt->bind_param('s', $nom);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error = 'اسم المسؤول هذا مستخدم مسبقاً.';
                } else {
                    $stmt = $conn->prepare("SELECT id FROM client WHERE LOWER(nom) = LOWER(?)");
                    $stmt->bind_param('s', $nom);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        $error = 'اسم المسؤول هذا مستخدم مسبقاً.';
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("INSERT INTO admin (nom, password, expenses) VALUES (?, ?, 0.00)");
                        $stmt->bind_param('ss', $nom, $hashed_password);
                        if ($stmt->execute()) {
                            $success = 'تم إنشاء المسؤول بنجاح.';
                        } else {
                            $error = 'خطأ أثناء إنشاء المسؤول.';
                        }
                    }
                }
            }
        }
    }

    if (isset($_POST['add_expense'])) {
        $amount = max(0, floatval($_POST['amount'] ?? 0));
        $cause = trim($_POST['cause'] ?? '');

        if ($amount <= 0 || $cause === '') {
            $error = 'يرجى إدخال المبلغ والسبب للمصروف.';
        } else {
            $stmt = $conn->prepare("INSERT INTO admin_expenses (admin_id, amount, cause) VALUES (?, ?, ?)");
            $stmt->bind_param('ids', $admin_id, $amount, $cause);
            if ($stmt->execute()) {
                $stmt = $conn->prepare("UPDATE admin SET expenses = expenses + ? WHERE id = ?");
                $stmt->bind_param('di', $amount, $admin_id);
                $stmt->execute();
                $success = 'تمت إضافة المصروف بنجاح.';
            } else {
                $error = 'خطأ أثناء إضافة المصروف.';
            }
        }
    }

    if (isset($_POST['add_recurring_expense'])) {
        $name = trim($_POST['recurring_name'] ?? '');
        $amount = max(0, floatval($_POST['recurring_amount'] ?? 0));
        $period = ($_POST['recurring_period'] ?? 'monthly') === 'daily' ? 'daily' : 'monthly';

        if ($name === '' || $amount <= 0) {
            $error = 'يرجى إدخال اسم المصروف والقيمة.';
        } else {
            $nextDue = new DateTime();
            $nextDue = advance_due_date($nextDue, $period);
            $nextDueStr = $nextDue->format('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO admin_recurring_expenses (admin_id, name, amount, period, is_active, next_due_at) VALUES (?, ?, ?, ?, 1, ?)");
            $stmt->bind_param('isdss', $admin_id, $name, $amount, $period, $nextDueStr);
            if ($stmt->execute()) {
                $success = 'تم إنشاء المصروف التلقائي بنجاح. سيُطبَّق ابتداءً من ' . $nextDue->format('d/m/Y') . '.';
            } else {
                $error = 'خطأ أثناء إنشاء المصروف التلقائي.';
            }
        }
    }

    if (isset($_POST['update_recurring_expense'])) {
        $rec_id = (int)($_POST['recurring_id'] ?? 0);
        $name = trim($_POST['recurring_name'] ?? '');
        $amount = max(0, floatval($_POST['recurring_amount'] ?? 0));
        $period = ($_POST['recurring_period'] ?? 'monthly') === 'daily' ? 'daily' : 'monthly';

        if ($rec_id <= 0 || $name === '' || $amount <= 0) {
            $error = 'يرجى إدخال بيانات صحيحة للمصروف التلقائي.';
        } else {
            $stmt = $conn->prepare("UPDATE admin_recurring_expenses SET name = ?, amount = ?, period = ? WHERE id = ?");
            $stmt->bind_param('sdsi', $name, $amount, $period, $rec_id);
            if ($stmt->execute()) {
                $success = 'تم تحديث المصروف التلقائي بنجاح.';
            } else {
                $error = 'خطأ أثناء تحديث المصروف التلقائي.';
            }
        }
    }

    if (isset($_POST['toggle_recurring_expense'])) {
        $rec_id = (int)($_POST['recurring_id'] ?? 0);
        if ($rec_id > 0) {
            $stmt = $conn->prepare("UPDATE admin_recurring_expenses SET is_active = 1 - is_active WHERE id = ?");
            $stmt->bind_param('i', $rec_id);
            $stmt->execute();
            $success = 'تم تحديث حالة المصروف التلقائي.';
        }
    }
}

$admins = [];
$admin_result = $conn->query("SELECT id, nom, expenses, created_at FROM admin ORDER BY id DESC");
if ($admin_result) {
    while ($row = $admin_result->fetch_assoc()) {
        $admins[] = $row;
    }
}

$current_admin = null;
$stmt = $conn->prepare("SELECT id, nom, expenses FROM admin WHERE id = ?");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$current_admin = $result->fetch_assoc();
$stmt->close();

// All expenses across all admins (shown on the main page), newest first.
$all_expenses = [];
$result = $conn->query(
    "SELECT ae.id, ae.amount, ae.cause, ae.created_at, a.nom AS admin_nom
     FROM admin_expenses ae
     LEFT JOIN admin a ON a.id = ae.admin_id
     ORDER BY ae.created_at DESC"
);
if ($result) {
    $all_expenses = $result->fetch_all(MYSQLI_ASSOC);
}

$total_all_expenses = 0.0;
foreach ($admins as $a) {
    $total_all_expenses += (float)$a['expenses'];
}

// Recurring expenses (shown on the "add expense" page), current admin's own.
$recurring_expenses = [];
$stmt = $conn->prepare("SELECT * FROM admin_recurring_expenses WHERE admin_id = ? ORDER BY id DESC");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$recurring_expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>المسؤولون والمصاريف — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <style>
        :root {
            --primary: #1e3a5f;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --success-dark: #059669;
            --danger: #ef4444;
            --danger-dark: #dc2626;
            --warning: #f59e0b;
            --warning-dark: #d97706;
            --border: #b8d4f0;
            --radius: 0.75rem;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }
        * { box-sizing: border-box; }
        body.nc-app-body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            color: #0f172a;
        }
        .container {
            max-width: 1140px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            gap: 1rem;
            color: #fff;
        }
        header h1 {
            margin: 0;
            font-size: 1.75rem;
            color: #fff;
        }
        header p { margin: 0.25rem 0 0; opacity: 0.95; color: rgba(255,255,255,0.95); }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            border-radius: 999px;
            border: none;
            background: var(--primary);
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .btn-success { background: var(--success); }
        .btn-danger { background: var(--danger); }
        .btn-outline { background: transparent; border: 1px solid var(--border); color: #0f172a; }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; border-radius: var(--radius); }
        .card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            padding: 1rem 1.5rem;
        }
        .card-body {
            padding: 1.5rem;
        }
        .grid {
            display: grid;
            gap: 1rem;
        }
        .grid-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: white;
            font-family: inherit;
        }
        textarea { resize: vertical; min-height: 120px; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }
        th { background: #f1f5f9; }
        .alert { padding: 0.75rem 1rem; border-radius: var(--radius); margin-bottom: 1rem; }
        .alert-success { background: #ecfdf5; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .badge { display: inline-block; padding: 0.3rem 0.75rem; border-radius: 999px; background: #e2e8f0; }
        .badge-active { background: #d1fae5; color: #166534; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }

        /* ===================== 3-BUTTON NAV ===================== */
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
            border-radius: var(--radius);
            font-size: 1.02rem;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            text-decoration: none;
            transition: transform 0.12s ease, filter 0.12s ease;
        }
        .main-action-btn:active { transform: scale(0.98); filter: brightness(0.95); }
        .main-action-btn .icon { font-size: 1.5rem; line-height: 1; }
        .main-action-btn--dashboard { background: linear-gradient(135deg, var(--secondary), #334155); }
        .main-action-btn--admin { background: linear-gradient(135deg, var(--primary), var(--primary-light)); }
        .main-action-btn--expense { background: linear-gradient(135deg, var(--warning), var(--warning-dark)); }
        .back-link-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 1.25rem;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            background: var(--secondary);
            color: #fff;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* ===================== EXPENSE TYPE TABS ===================== */
        .choice-tabs { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .choice-tab {
            flex: 1;
            min-width: 220px;
            padding: 1rem;
            border-radius: var(--radius);
            border: 2px solid var(--border);
            background: white;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: center;
            color: #0f172a;
        }
        .choice-tab.active { border-color: var(--primary-light); background: #eff6ff; color: var(--primary); }
        .choice-panel { display: none; }
        .choice-panel.active { display: block; }

        /* ===================== EDIT MODAL ===================== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .modal-overlay.is-open { display: flex; }
        .modal-box {
            background: #fff;
            width: 100%;
            max-width: 480px;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .modal-box .card-header { display: flex; align-items: center; justify-content: space-between; }
        .modal-close-btn { background: transparent; border: none; color: #fff; font-size: 1.4rem; cursor: pointer; line-height: 1; }

        @media (max-width: 768px) {
            .grid-2 { grid-template-columns: 1fr; }
            .main-actions { grid-template-columns: 1fr; }
            .choice-tabs { flex-direction: column; }
            table { font-size: 0.85rem; }
            th, td { padding: 0.6rem; }
        }
    </style>
</head>
<body class="nc-app-body">
    <div class="container">
        <header>
            <div>
                <h1>المسؤولون والمصاريف</h1>
                <p>إدارة المسؤولين والمصاريف</p>
            </div>
        </header>

        <?php if ($view !== 'main'): ?>
            <a href="admin.php?view=main" class="back-link-btn">← رجوع للصفحة الرئيسية</a>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($view === 'main'): ?>
            <div class="main-actions">
                <a href="dashboard.php" class="main-action-btn main-action-btn--dashboard">
                    <span class="icon">🏠</span> العودة إلى لوحة التحكم
                </a>
                <a href="admin.php?view=create_admin" class="main-action-btn main-action-btn--admin">
                    <span class="icon">👤</span> إنشاء مسؤول جديد
                </a>
                <a href="admin.php?view=add_expense" class="main-action-btn main-action-btn--expense">
                    <span class="icon">💸</span> إضافة مصروف
                </a>
            </div>

            <div class="card">
                <div class="card-header"><h2>💰 إجمالي المصاريف (كل المسؤولين)</h2></div>
                <div class="card-body">
                    <p style="font-size:1.5rem; font-weight:800; color:var(--primary);"><?= number_format($total_all_expenses, 2, ',', ' ') ?> دج</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>📋 كل المصاريف</h2></div>
                <div class="card-body">
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>المسؤول</th>
                                    <th>المبلغ</th>
                                    <th>السبب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($all_expenses) === 0): ?>
                                    <tr><td colspan="4">لا توجد مصاريف مسجّلة.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($all_expenses as $expense): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($expense['created_at']))) ?></td>
                                            <td><?= htmlspecialchars($expense['admin_nom'] ?? '-') ?></td>
                                            <td><?= number_format((float)$expense['amount'], 2, ',', ' ') ?> دج</td>
                                            <td><?= htmlspecialchars($expense['cause']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'create_admin'): ?>
            <div class="card">
                <div class="card-header"><h2>إنشاء مسؤول جديد</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="add_admin" value="1">
                        <div class="grid grid-2">
                            <div class="form-group">
                                <label for="nom">اسم المسؤول</label>
                                <input type="text" id="nom" name="nom" required placeholder="مثال: أمين">
                            </div>
                            <div class="form-group">
                                <label for="password">كلمة المرور</label>
                                <input type="password" id="password" name="password" required placeholder="6 أحرف على الأقل">
                            </div>
                        </div>
                        <button type="submit" class="btn">إنشاء المسؤول</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>قائمة المسؤولين</h2></div>
                <div class="card-body">
                    <div style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>الاسم</th>
                                    <th>إجمالي المصاريف</th>
                                    <th>تاريخ الإنشاء</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($admins) === 0): ?>
                                    <tr><td colspan="4">لا يوجد مسؤولون.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($admins as $admin_row): ?>
                                        <tr>
                                            <td>#<?= (int)$admin_row['id'] ?></td>
                                            <td><?= htmlspecialchars($admin_row['nom']) ?></td>
                                            <td><?= number_format((float)$admin_row['expenses'], 2, ',', ' ') ?> دج</td>
                                            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($admin_row['created_at']))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'add_expense'): ?>
            <div class="choice-tabs">
                <button type="button" class="choice-tab active" data-choice="regular" onclick="setExpenseChoice('regular')">
                    💵 إضافة مصروف
                </button>
                <button type="button" class="choice-tab" data-choice="recurring" onclick="setExpenseChoice('recurring')">
                    🔁 إضافة مصروف تلقائي
                </button>
            </div>

            <div class="choice-panel active" data-panel="regular">
                <div class="card">
                    <div class="card-header"><h2>إضافة مصروف</h2></div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="add_expense" value="1">
                            <div class="grid grid-2">
                                <div class="form-group">
                                    <label for="amount">المبلغ</label>
                                    <input type="number" step="0.01" min="0" id="amount" name="amount" required placeholder="مثال: 1500.00">
                                </div>
                                <div class="form-group">
                                    <label for="cause">السبب</label>
                                    <textarea id="cause" name="cause" required placeholder="سبب المصروف..."></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn">حفظ المصروف</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2>مصاريفي</h2></div>
                    <div class="card-body">
                        <p><strong>المسؤول المتصل:</strong> <?= htmlspecialchars($current_admin['nom'] ?? 'N/A') ?></p>
                        <p><strong>إجمالي مصاريفي:</strong> <?= number_format((float)($current_admin['expenses'] ?? 0), 2, ',', ' ') ?> دج</p>
                    </div>
                </div>
            </div>

            <div class="choice-panel" data-panel="recurring">
                <div class="card">
                    <div class="card-header"><h2>إضافة مصروف تلقائي</h2></div>
                    <div class="card-body">
                        <p style="margin-bottom:1rem; color:var(--secondary); font-size:0.9rem;">
                            مثال: اسم المصروف "إيجار"، القيمة 1000، الفترة "شهرياً" — سيُضاف تلقائياً لإجمالي المصاريف كل شهر دون تدخل منك.
                        </p>
                        <form method="POST">
                            <input type="hidden" name="add_recurring_expense" value="1">
                            <div class="grid grid-2">
                                <div class="form-group">
                                    <label for="recurring_name">اسم المصروف</label>
                                    <input type="text" id="recurring_name" name="recurring_name" required placeholder="مثال: إيجار المحل">
                                </div>
                                <div class="form-group">
                                    <label for="recurring_amount">القيمة</label>
                                    <input type="number" step="0.01" min="0" id="recurring_amount" name="recurring_amount" required placeholder="مثال: 1000.00">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label for="recurring_period">الفترة</label>
                                    <select id="recurring_period" name="recurring_period">
                                        <option value="daily">يومياً</option>
                                        <option value="monthly" selected>شهرياً</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn">إنشاء المصروف التلقائي</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2>مصاريفي التلقائية</h2></div>
                    <div class="card-body">
                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>الاسم</th>
                                        <th>القيمة</th>
                                        <th>الفترة</th>
                                        <th>الحالة</th>
                                        <th>الاستحقاق القادم</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recurring_expenses) === 0): ?>
                                        <tr><td colspan="6">لا توجد مصاريف تلقائية بعد.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recurring_expenses as $rec): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($rec['name']) ?></td>
                                                <td><?= number_format((float)$rec['amount'], 2, ',', ' ') ?> دج</td>
                                                <td><?= $rec['period'] === 'daily' ? 'يومياً' : 'شهرياً' ?></td>
                                                <td>
                                                    <span class="badge <?= $rec['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                                        <?= $rec['is_active'] ? 'مفعّل' : 'معطّل' ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars(date('d/m/Y', strtotime($rec['next_due_at']))) ?></td>
                                                <td>
                                                    <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                                                        <button type="button" class="btn btn-outline btn-sm" onclick='openEditRecurringModal(<?= htmlspecialchars(json_encode($rec, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>✏️ تعديل</button>
                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="toggle_recurring_expense" value="1">
                                                            <input type="hidden" name="recurring_id" value="<?= (int)$rec['id'] ?>">
                                                            <button type="submit" class="btn <?= $rec['is_active'] ? 'btn-danger' : 'btn-success' ?> btn-sm">
                                                                <?= $rec['is_active'] ? '⏸️ تعطيل' : '▶️ تفعيل' ?>
                                                            </button>
                                                        </form>
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
            </div>

            <!-- Edit recurring-expense modal -->
            <div class="modal-overlay" id="editRecurringModalOverlay">
                <div class="modal-box">
                    <div class="card-header">
                        <h2>✏️ تعديل مصروف تلقائي</h2>
                        <button type="button" class="modal-close-btn" onclick="closeEditRecurringModal()">✕</button>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="update_recurring_expense" value="1">
                            <input type="hidden" name="recurring_id" id="editRecurringId">
                            <div class="form-group">
                                <label for="editRecurringName">اسم المصروف</label>
                                <input type="text" id="editRecurringName" name="recurring_name" required>
                            </div>
                            <div class="form-group">
                                <label for="editRecurringAmount">القيمة</label>
                                <input type="number" step="0.01" min="0" id="editRecurringAmount" name="recurring_amount" required>
                            </div>
                            <div class="form-group">
                                <label for="editRecurringPeriod">الفترة</label>
                                <select id="editRecurringPeriod" name="recurring_period">
                                    <option value="daily">يومياً</option>
                                    <option value="monthly">شهرياً</option>
                                </select>
                            </div>
                            <div style="display:flex; gap:0.75rem;">
                                <button type="submit" class="btn btn-success">حفظ التعديلات</button>
                                <button type="button" class="btn btn-outline" onclick="closeEditRecurringModal()">إلغاء</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function setExpenseChoice(choice) {
            document.querySelectorAll('.choice-tab').forEach((tab) => {
                tab.classList.toggle('active', tab.getAttribute('data-choice') === choice);
            });
            document.querySelectorAll('.choice-panel').forEach((panel) => {
                panel.classList.toggle('active', panel.getAttribute('data-panel') === choice);
            });
        }

        function openEditRecurringModal(rec) {
            document.getElementById('editRecurringId').value = rec.id;
            document.getElementById('editRecurringName').value = rec.name;
            document.getElementById('editRecurringAmount').value = rec.amount;
            document.getElementById('editRecurringPeriod').value = rec.period;
            document.getElementById('editRecurringModalOverlay').classList.add('is-open');
        }

        function closeEditRecurringModal() {
            document.getElementById('editRecurringModalOverlay').classList.remove('is-open');
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeEditRecurringModal();
        });
        document.addEventListener('click', (e) => {
            if (e.target && e.target.id === 'editRecurringModalOverlay') closeEditRecurringModal();
        });
    </script>
</body>
</html>
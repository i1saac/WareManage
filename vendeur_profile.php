<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'] ?? '';

if ($is_client || !in_array($user_type, ['vendeur', 'staff_admin'], true)) {
    die("<div class='error-container'><h2>⛔ الوصول مرفوض</h2><p>هذه الصفحة للبائعين فقط.</p><a href='dashboard.php' class='btn-primary'>العودة إلى لوحة التحكم</a></div>");
}

$user_id = intval($_SESSION['user_id']);
$staff = $conn->query("SELECT * FROM stuff WHERE id = $user_id")->fetch_assoc();

if (!$staff) {
    die("<div class='error-container'><h2>⚠️ الملف غير موجود</h2><p>تعذّر تحميل حسابك. اتصل بالإدارة.</p><a href='dashboard.php' class='btn-primary'>العودة إلى لوحة التحكم</a></div>");
}

$credit = number_format((float)($staff['credit'] ?? 0), 2, ',', ' ');
$daily = number_format((float)($staff['salaire_par_jour'] ?? 0), 2, ',', ' ');
$absences = intval($staff['nombre_d_absence'] ?? 0);
$monthly = number_format((float)($staff['salaire_par_mois'] ?? 0), 2, ',', ' ');

?>
<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>مساحة البائع — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <style>
        :root {
            --primary: #1e3a5f;
            --primary-light: #3b82f6;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #cbd5e1;
            --radius: 0.75rem;
            --shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%);
            color: var(--dark);
            min-height: 100vh;
        }
        .page {
            max-width: 920px;
            margin: 0 auto;
            padding: 2rem;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        header h1 {
            font-size: 1.75rem;
            margin: 0;
        }
        .btn-primary {
            display: inline-block;
            padding: 0.75rem 1.2rem;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            text-decoration: none;
            font-weight: 600;
        }
        .profile-card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
        }
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        .profile-item {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1rem;
        }
        .profile-item h3 {
            margin: 0 0 0.5rem;
            font-size: 0.95rem;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .profile-item p {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
        }
        .info {
            margin-top: 1.5rem;
        }
        .info p {
            margin: 0.75rem 0;
            line-height: 1.6;
        }
        .note {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            color: #1e3a8a;
        }
    </style>
</head>
<body>
<div class="page">
    <header>
        <div>
            <h1>👤 مساحة البائع</h1>
            <p>اعرض معلومات حسابك وحالتك.</p>
        </div>
        <a href="dashboard.php" class="btn-primary">← عودة</a>
    </header>

    <div class="profile-card">
        <div class="profile-grid">
            <div class="profile-item">
                <h3>الاسم</h3>
                <p><?= htmlspecialchars($staff['nom']) ?></p>
            </div>
            <div class="profile-item">
                <h3>الدور</h3>
                <p><?= htmlspecialchars($staff['post']) ?></p>
            </div>
            <div class="profile-item">
                <h3>الدين</h3>
                <p><?= $credit ?> دج</p>
            </div>
            <div class="profile-item">
                <h3>أجر/يوم</h3>
                <p><?= $daily ?> دج</p>
            </div>
            <div class="profile-item">
                <h3>غياب هذا الشهر</h3>
                <p><?= $absences ?></p>
            </div>
            <div class="profile-item">
                <h3>راتب/شهر</h3>
                <p><?= $monthly ?> دج</p>
            </div>
        </div>

        <div class="info">
            <p><strong>ملاحظة:</strong> الإدارة فقط تعدّل هذه البيانات. يمكنك هنا مراجعة حسابك والراتب والغياب.</p>
            <p>يُصفّر عدد الغيابات شهرياً ويمكن للمسؤول تحديثه يومياً.</p>
        </div>
    </div>
</div>
</body>
</html>

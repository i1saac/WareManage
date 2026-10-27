<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once 'config.php';

$user_id   = $_SESSION['user_id'] ?? null;
$user_nom  = $_SESSION['user_nom'] ?? 'زائر';
$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'] ?? '';

if (!$user_id || $is_client || $user_type !== 'admin') {
    echo '<div class="error-container" style="font-family:Arial, sans-serif; padding:2rem; text-align:center;">';
    echo '<h2>⛔ الوصول مرفوض</h2>';
    echo '<p>هذه الصفحة متاحة فقط للمسؤول.</p>';
    echo '<p><a href="dashboard.php" class="btn-primary" style="display:inline-block; margin-top:1rem; padding:0.75rem 1.25rem; background:#1e3a5f; color:#fff; text-decoration:none; border-radius:8px;">العودة إلى لوحة التحكم</a></p>';
    echo '</div>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم البنك</title>
    <link rel="stylesheet" href="theme.css">
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            margin: 0;
        }
        header {
            background: linear-gradient(135deg, #1e3a5f 0%, #1d4ed8 100%);
            color: white;
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 0.75rem;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 2rem;
        }
        h1 {
            margin-bottom: 1rem;
            font-size: 2rem;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
        }
        .menu-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .menu-card h3 {
            margin: 0.75rem 0;
            font-size: 1.1rem;
            color: #0f172a;
        }
        .btn {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.75rem 1.25rem;
            background: #1e40af;
            color: white;
            border-radius: 0.75rem;
            text-decoration: none;
            transition: transform 0.15s ease;
        }
        .btn:hover {
            transform: translateY(-1px);
        }
        .footer-link {
            display: inline-block;
            margin-top: 2rem;
            color: #334155;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background: white;
        }
    </style>
</head>
<body>
    <header>
        <div>👋 مرحباً، <?= htmlspecialchars($user_nom) ?> (المسؤول)</div>
        <div>
            <a href="dashboard.php">العودة إلى لوحة التحكم الرئيسية</a>
            <a href="logout.php">تسجيل الخروج</a>
        </div>
    </header>
    <div class="container">
        <h1>🏦 لوحة تحكم البنك</h1>
        <div class="menu-grid">
            <div class="menu-card">
                <h3>📦 منتجات البنك</h3>
                <a href="produit_bank.php" class="btn">فتح منتجات البنك</a>
            </div>
            <div class="menu-card">
                <h3>👥 عملاء البنك</h3>
                <a href="clients_bank.php" class="btn">فتح عملاء البنك</a>
            </div>
            <div class="menu-card">
                <h3>🧾 مبيعات البنك</h3>
                <a href="ventes_bank.php" class="btn">فتح مبيعات البنك</a>
            </div>
            <div class="menu-card">
                <h3>📥 مشتريات البنك</h3>
                <a href="bank.php" class="btn">فتح مشتريات البنك</a>
            </div>
        </div>
        <a href="dashboard.php" class="footer-link">← العودة إلى لوحة التحكم</a>
    </div>
</body>
</html>

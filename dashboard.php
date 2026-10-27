<?php
header('Content-Type: text/html; charset=utf-8');
// dashboard.php - Central Hub (Bleu Nuit & Bleu Ciel Design)
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_nom  = $_SESSION['user_nom'];
$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'];

// ============== ADMIN STATISTICS ==============

?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>لوحة التحكم — NettoClean</title>
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
            --danger: #ef4444;
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

        /* ===================== HEADER ===================== */
        header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: var(--shadow-lg);
        }

        header div {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
        }

        header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.15);
            border-radius: var(--radius);
            transition: all 0.2s;
        }

        header a:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-1px);
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

        /* ===================== TITRES ===================== */
        h1 {
            text-align: center;
            margin: 1.5rem 0;
            color: white;
            font-size: 2rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        h2 {
            margin: 1.5rem 0 1rem;
            color: white;
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        h2::before {
            content: '';
            width: 4px;
            height: 20px;
            background: var(--primary-light);
            border-radius: 2px;
        }

        /* ===================== STATS GRID ===================== */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow-lg);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
        }

        .stat-box:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .stat-box p:first-of-type {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        /* ===================== MENU GRID ===================== */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
        }

        .menu-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            border: 1px solid var(--border);
        }

        .menu-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .menu-card h3 {
            margin: 0.75rem 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .menu-card h3:first-child {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .btn {
            display: inline-block;
            margin-top: 0.75rem;
            padding: 0.6rem 1.25rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* ===================== BOUTON ACCUEIL PUBLIC ===================== */
        .public-btn {
            text-align: center;
            margin-top: 2.5rem;
        }

        .public-btn a {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .public-btn a:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* ===================== RESPONSIVE ===================== */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            h1 {
                font-size: 1.5rem;
            }

            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .stat-box {
                padding: 1rem;
            }

            .stat-number {
                font-size: 1.5rem;
            }

            .menu-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            header {
                padding: 1rem;
                flex-direction: column;
                text-align: center;
            }

            header div {
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .stat-grid {
                grid-template-columns: 1fr;
            }

            .menu-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="nc-app-body">

    <header>
        <div>👋 مرحباً، <?= htmlspecialchars($user_nom) ?> (<?= strtoupper($user_type) ?>)</div>
        <a href="logout.php">🔓 تسجيل الخروج</a>
    </header>

    <div class="container">

        <?php if (!$is_client && $user_type === 'admin'): ?>
            <h1>📊 لوحة تحكم المسؤول</h1>
            
            <h2>إدارة سريعة</h2>
            <div class="menu-grid">
                <div class="menu-card">
                    <h3>📋</h3>
                    <h3>المبيعات</h3>
                    <a href="sales.php" class="btn">إدارة المبيعات</a>
                </div>
                <div class="menu-card">
                    <h3>📦</h3>
                    <h3>المنتجات</h3>
                    <a href="products.php" class="btn">إدارة المنتجات</a>
                </div>
                <div class="menu-card">
                    <h3>👥</h3>
                    <h3>العملاء</h3>
                    <a href="clients.php" class="btn">إدارة العملاء</a>
                </div>
                <div class="menu-card">
                    <h3>👷</h3>
                    <h3>الموظفون</h3>
                    <a href="staff.php" class="btn">إدارة الموظفين</a>
                </div>
                <div class="menu-card">
                    <h3>🛡️</h3>
                    <h3>المسؤولون</h3>
                    <a href="admin.php" class="btn">إدارة المسؤولين</a>
                </div>
                <div class="menu-card">
                    <h3>🏬</h3>
                    <h3>المورّدون</h3>
                    <a href="fournisseurs.php" class="btn">إدارة المورّدين</a>
                </div>
                <div class="menu-card">
                    <h3>📥</h3>
                    <h3>المشتريات</h3>
                    <a href="purchases.php" class="btn">إدارة المشتريات</a>
                </div>
                <div class="menu-card">
                    <h3>🏦</h3>
                    <h3>لوحة بنك</h3>
                    <a href="dashboard_bank.php" class="btn">فتح لوحة البنك</a>
                </div>
                <div class="menu-card">
                    <h3>📈</h3>
                    <h3>الإحصائيات</h3>
                    <a href="stats.php" class="btn">عرض الإحصائيات</a>
                </div>
            </div>

        <?php elseif (!$is_client && ($user_type === 'vendeur' || $user_type === 'staff_admin')): ?>
            <h1>👷 لوحة تحكم البائع</h1>
            <div class="menu-grid">
                <div class="menu-card">
                    <h3>📋</h3>
                    <h3>المبيعات</h3>
                    <a href="sales.php" class="btn">المبيعات والطلبات</a>
                </div>
                <div class="menu-card">
                    <h3>👤</h3>
                    <h3>مساحتي</h3>
                    <a href="vendeur_profile.php" class="btn">ملفي الشخصي</a>
                </div>
            </div>

        <?php else: // Client ?>
            <h1>🏠 أهلاً <?= htmlspecialchars($user_nom) ?></h1>
            <div class="menu-grid">
                <div class="menu-card">
                    <h3>ℹ️</h3>
                    <h3>معلوماتي</h3>
                    <a href="mon_profil.php" class="btn">إدارة ملفي</a>
                </div>
                <div class="menu-card">
                    <h3>🛒</h3>
                    <h3>المنتجات</h3>
                    <a href="products.php" class="btn">المنتجات والطلب</a>
                </div>
                <div class="menu-card">
                    <h3>📋</h3>
                    <h3>طلباتي</h3>
                    <a href="sales.php" class="btn">طلباتي</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="public-btn">
            <a href="index.php">🏠 الصفحة العامة →</a>
        </div>

    </div>

</body>
</html>
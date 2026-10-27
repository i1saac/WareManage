<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$is_client = $_SESSION['is_client'] ?? false;
$user_type = $_SESSION['user_type'] ?? '';

if ($is_client || $user_type !== 'admin') {
    die("<div class='error-container'><h2>⛔ الوصول مرفوض</h2><p>ليست لديك الصلاحيات.</p><a href='dashboard.php' class='btn-primary'>العودة إلى لوحة التحكم</a></div>");
}

$now = new DateTimeImmutable('now');
$monthStart = $now->format('Y-m-01 00:00:00');
$monthEnd = $now->format('Y-m-t 23:59:59');
$yearStart = $now->format('Y-01-01 00:00:00');
$yearEnd = $now->format('Y-12-31 23:59:59');

function getScalar($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0.0;
    }
    $row = $result->fetch_assoc();
    return $row ? floatval($row[array_key_first($row)]) : 0.0;
}

/** Marge sur les ventes confirmées dans la période : Σ (montant ligne − prix_achat × quantité) */
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

function getPeriodExpenses(mysqli $conn, string $start, string $end): float {
    $sql = 'SELECT COALESCE(SUM(amount), 0) AS t FROM admin_expenses WHERE created_at >= ? AND created_at <= ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0.0;
    }
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['t'] : 0.0;
}

$lowStockProducts = $conn->query("SELECT * FROM product WHERE nombre_de_carton <= low_stock ORDER BY nombre_de_carton ASC, id ASC");
$topSellingProduct = $conn->query("SELECT * FROM product ORDER BY nombre_de_vente DESC, id ASC LIMIT 1")->fetch_assoc();
$topBenefitProduct = $conn->query("SELECT * FROM product ORDER BY montant_de_vente DESC, id ASC LIMIT 1")->fetch_assoc();

$incomeTotal = getScalar($conn, "SELECT COALESCE(SUM(versement_total), 0) AS total FROM client");
$myIncomeTotal = getScalar($conn, "SELECT COALESCE(SUM(mon_versement_total), 0) AS total FROM fournisseur");
$creditTotal = getScalar($conn, "SELECT COALESCE(SUM(credit), 0) AS total FROM client");
$monCreditTotal = getScalar($conn, "SELECT COALESCE(SUM(mon_credit), 0) AS total FROM fournisseur");
$vendorsSalaryTotal = getScalar($conn, "SELECT COALESCE(SUM(salaire_par_mois), 0) AS total FROM stuff WHERE post = 'vendeur'");
$vendorsCreditTotal = getScalar($conn, "SELECT COALESCE(SUM(credit), 0) AS total FROM stuff WHERE post = 'vendeur'");
$vendorsCost = $vendorsSalaryTotal + $vendorsCreditTotal;
$vendorsCostYearly = $vendorsSalaryTotal * 12 + $vendorsCreditTotal;

$expensesFromLines = getScalar($conn, 'SELECT COALESCE(SUM(amount), 0) AS total FROM admin_expenses');
$expensesFromAdminCol = getScalar($conn, 'SELECT COALESCE(SUM(expenses), 0) AS total FROM admin');
$expensesCost = $expensesFromLines > 0 ? $expensesFromLines : $expensesFromAdminCol;

$benefitTotal = getScalar($conn, 'SELECT COALESCE(SUM(benefit), 0) AS total FROM product');
$realBenefit = $benefitTotal - $vendorsCost - $expensesCost;

$monthSales = $conn->query("SELECT COALESCE(SUM(versement),0) AS versement_total, COALESCE(SUM(credit),0) AS credit_total FROM sales WHERE `time` BETWEEN '$monthStart' AND '$monthEnd'")->fetch_assoc();
$yearSales = $conn->query("SELECT COALESCE(SUM(versement),0) AS versement_total, COALESCE(SUM(credit),0) AS credit_total FROM sales WHERE `time` BETWEEN '$yearStart' AND '$yearEnd'")->fetch_assoc();
$monthPurchases = $conn->query("SELECT COALESCE(SUM(mon_versement),0) AS mon_versement_total, COALESCE(SUM(mon_credit),0) AS mon_credit_total FROM purchases WHERE `time` BETWEEN '$monthStart' AND '$monthEnd'")->fetch_assoc();
$yearPurchases = $conn->query("SELECT COALESCE(SUM(mon_versement),0) AS mon_versement_total, COALESCE(SUM(mon_credit),0) AS mon_credit_total FROM purchases WHERE `time` BETWEEN '$yearStart' AND '$yearEnd'")->fetch_assoc();

$benefitMonth = getPeriodBenefit($conn, $monthStart, $monthEnd);
$benefitYear = getPeriodBenefit($conn, $yearStart, $yearEnd);
$expensesMonth = getPeriodExpenses($conn, $monthStart, $monthEnd);
$expensesYear = getPeriodExpenses($conn, $yearStart, $yearEnd);

$realBenefitMonth = $benefitMonth - $expensesMonth - $vendorsCost;
$realBenefitYear = $benefitYear - $expensesYear - $vendorsCostYearly;

$topClients = $conn->query("SELECT id, nom, telephone, credit, versement_total, (versement_total - credit) AS activity_score FROM client ORDER BY activity_score DESC LIMIT 3");

function formatMoney($value) {
    return number_format((float)$value, 2, ',', ' ') . ' دج';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>الإحصائيات — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <meta name="theme-color" content="#1e3a5f">
    <style>
        :root {
            --primary: #1e3a5f;
            --primary-dark: #0f2a45;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f0f9ff;
            --border: #b8d4f0;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
            --radius-lg: 0.75rem;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #0f2a45 0%, #1e3a5f 50%, #3b82f6 100%);
            min-height: 100vh;
            color: var(--dark);
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
        }
        .top-bar {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 1.5rem;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.65rem 1.25rem;
            border-radius: var(--radius);
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            box-shadow: var(--shadow);
        }
        .btn-primary:hover {
            filter: brightness(1.05);
        }
        .card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }
        .card > h2 {
            color: var(--primary);
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .card > h2::before {
            content: '';
            width: 4px;
            height: 20px;
            background: var(--primary-light);
            border-radius: 2px;
        }
        .small-note {
            font-size: 0.9rem;
            color: var(--gray);
            margin-top: 0.35rem;
            font-weight: normal;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }
        .stat-card {
            background: var(--light);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.15rem;
        }
        .stat-card h3 {
            font-size: 0.95rem;
            color: var(--gray);
            font-weight: 600;
            margin: 0 0 0.5rem;
        }
        .stat-card p {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin: 0;
        }
        .stat-card.highlight {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-color: #6ee7b7;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        th, td {
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th {
            background: var(--light);
            color: var(--primary);
            font-weight: 600;
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #f8fafc; }
    </style>
</head>
<body>
    <header>📊 إحصائيات الإدارة</header>
    <div class="container">
        <div class="top-bar">
            <a href="dashboard.php" class="btn-primary">← العودة إلى لوحة التحكم</a>
        </div>

        <div class="card">
            <h2>ملخص عام <span class="small-note">نظرة على المخزون والعملاء والمورّدين والهوامش.</span></h2>
            <div class="cards">
                <div class="stat-card">
                    <h3>إجمالي دخل العملاء</h3>
                    <p><?= formatMoney($incomeTotal) ?></p>
                </div>
                <div class="stat-card">
                    <h3>إجمالي دخلي (مورّدون)</h3>
                    <p><?= formatMoney($myIncomeTotal) ?></p>
                </div>
                <div class="stat-card">
                    <h3>إجمالي دين العملاء</h3>
                    <p><?= formatMoney($creditTotal) ?></p>
                </div>
                <div class="stat-card">
                    <h3>إجمالي ديني (مورّدون)</h3>
                    <p><?= formatMoney($monCreditTotal) ?></p>
                </div>
                <div class="stat-card">
                    <h3>تكلفة البائعين</h3>
                    <p><?= formatMoney($vendorsCost) ?></p>
                </div>
                <div class="stat-card">
                    <h3>مصاريف (مسؤولون)</h3>
                    <p><?= formatMoney($expensesCost) ?></p>
                    <p class="small-note" style="margin-top:0.5rem;font-size:0.8rem;">مجموع المصاريف المسجّلة.</p>
                </div>
                <div class="stat-card highlight">
                    <h3>إجمالي ربح المنتجات</h3>
                    <p><?= formatMoney($benefitTotal) ?></p>
                </div>
                <div class="stat-card highlight">
                    <h3>الربح الفعلي</h3>
                    <p><?= formatMoney($realBenefit) ?></p>
                    <p class="small-note" style="margin-top:0.5rem;font-size:0.8rem;">إجمالي الربح − تكلفة البائعين − المصاريف</p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>قيم الشهر الحالي</h2>
            <div class="cards">
                <div class="stat-card">
                    <h3>دفعات العملاء</h3>
                    <p><?= formatMoney($monthSales['versement_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>دفعات المورّدين</h3>
                    <p><?= formatMoney($monthPurchases['mon_versement_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>دين العملاء (شهر)</h3>
                    <p><?= formatMoney($monthSales['credit_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>دين المورّدين (شهر)</h3>
                    <p><?= formatMoney($monthPurchases['mon_credit_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>مصاريف (شهر)</h3>
                    <p><?= formatMoney($expensesMonth) ?></p>
                </div>
                <div class="stat-card highlight">
                    <h3>إجمالي الربح (شهر)</h3>
                    <p><?= formatMoney($benefitMonth) ?></p>
                    <p class="small-note" style="margin-top:0.5rem;font-size:0.75rem;">هامش المبيعات المعتمدة في الشهر</p>
                </div>
                <div class="stat-card highlight">
                    <h3>الربح الفعلي (شهر)</h3>
                    <p><?= formatMoney($realBenefitMonth) ?></p>
                    <p class="small-note" style="margin-top:0.5rem;font-size:0.75rem;">ربح الشهر − مصاريف الشهر − تكلفة البائعين</p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>قيم السنة الحالية</h2>
            <div class="cards">
                <div class="stat-card">
                    <h3>دفعات العملاء</h3>
                    <p><?= formatMoney($yearSales['versement_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>دفعات المورّدين</h3>
                    <p><?= formatMoney($yearPurchases['mon_versement_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>دين العملاء (سنة)</h3>
                    <p><?= formatMoney($yearSales['credit_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>دين المورّدين (سنة)</h3>
                    <p><?= formatMoney($yearPurchases['mon_credit_total'] ?? 0) ?></p>
                </div>
                <div class="stat-card">
                    <h3>مصاريف (سنة)</h3>
                    <p><?= formatMoney($expensesYear) ?></p>
                </div>
                <div class="stat-card highlight">
                    <h3>إجمالي الربح (سنة)</h3>
                    <p><?= formatMoney($benefitYear) ?></p>
                    <p class="small-note" style="margin-top:0.5rem;font-size:0.75rem;">هامش المبيعات المعتمدة في السنة</p>
                </div>
                <div class="stat-card highlight">
                    <h3>الربح الفعلي (سنة)</h3>
                    <p><?= formatMoney($realBenefitYear) ?></p>
                    <p class="small-note" style="margin-top:0.5rem;font-size:0.75rem;">ربح السنة − مصاريف السنة − (رواتب البائعين ×12 + ديونهم)</p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>منتجات قريبة من النفاد</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>مرجع</th>
                        <th>اسم</th>
                        <th>مخزون كراتين</th>
                        <th>حد تنبيه</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($lowStockProducts && $lowStockProducts->num_rows > 0): ?>
                        <?php while ($product = $lowStockProducts->fetch_assoc()): ?>
                            <tr>
                                <td><?= (int)$product['id'] ?></td>
                                <td><?= htmlspecialchars($product['reference']) ?></td>
                                <td><?= htmlspecialchars($product['nom']) ?></td>
                                <td><?= (int)$product['nombre_de_carton'] ?></td>
                                <td><?= (int)$product['low_stock'] ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5">لا يوجد منتج قريب من النفاد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>أكثر منتج مبيعاً</h2>
            <?php if ($topSellingProduct): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>مرجع</th>
                            <th>اسم</th>
                            <th>كمية بيع</th>
                            <th>مبلغ بيع</th>
                            <th>ربح</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= (int)$topSellingProduct['id'] ?></td>
                            <td><?= htmlspecialchars($topSellingProduct['reference']) ?></td>
                            <td><?= htmlspecialchars($topSellingProduct['nom']) ?></td>
                            <td><?= (int)$topSellingProduct['nombre_de_vente'] ?></td>
                            <td><?= formatMoney($topSellingProduct['montant_de_vente']) ?></td>
                            <td><?= formatMoney($topSellingProduct['benefit']) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <p>لا يوجد منتج.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>أعلى مبلغ بيع لمنتج</h2>
            <?php if ($topBenefitProduct): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>مرجع</th>
                            <th>اسم</th>
                            <th>كمية بيع</th>
                            <th>مبلغ بيع</th>
                            <th>ربح</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= (int)$topBenefitProduct['id'] ?></td>
                            <td><?= htmlspecialchars($topBenefitProduct['reference']) ?></td>
                            <td><?= htmlspecialchars($topBenefitProduct['nom']) ?></td>
                            <td><?= (int)$topBenefitProduct['nombre_de_vente'] ?></td>
                            <td><?= formatMoney($topBenefitProduct['montant_de_vente']) ?></td>
                            <td><?= formatMoney($topBenefitProduct['benefit']) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <p>لا يوجد منتج.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>أكثر العملاء نشاطاً</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>الاسم</th>
                        <th>الهاتف</th>
                        <th>إجمالي الدفعات</th>
                        <th>الدين</th>
                        <th>نشاط</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($topClients && $topClients->num_rows > 0): ?>
                        <?php while ($client = $topClients->fetch_assoc()): ?>
                            <tr>
                                <td><?= (int)$client['id'] ?></td>
                                <td><?= htmlspecialchars($client['nom']) ?></td>
                                <td><?= htmlspecialchars($client['telephone'] ?? '') ?></td>
                                <td><?= formatMoney($client['versement_total']) ?></td>
                                <td><?= formatMoney($client['credit']) ?></td>
                                <td><?= formatMoney($client['activity_score']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6">لا يوجد عملاء.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

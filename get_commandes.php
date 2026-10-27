<?php
header('Content-Type: text/html; charset=utf-8');
require_once 'config.php';

if (!isset($_GET['client_id'])) {
    die('<p style="color: red;">خطأ: معرّف العميل مفقود.</p>');
}

$client_id = (int)$_GET['client_id'];

$commandes = $conn->query("
    SELECT s.id, s.montant, s.status, s.time, s.source,
           COUNT(si.id) as nombre_articles,
           COALESCE(v.nom, '—') as vendeur_nom,
           c.nom as client_nom
    FROM sales s
    LEFT JOIN sale_items si ON s.id = si.sale_id
    LEFT JOIN stuff v ON s.seller_stuff_id = v.id
    LEFT JOIN client c ON s.client_id = c.id
    WHERE s.client_id = $client_id
    GROUP BY s.id
    ORDER BY s.time DESC
");

if (!$commandes) {
    die('<p style="color: red;">خطأ أثناء جلب الطلبات.</p>');
}

if ($commandes->num_rows == 0) {
    echo '<p style="text-align: center; padding: 30px; color: #64748b;">لا توجد طلبات لهذا العميل.</p>';
    exit();
}

$styles = '
<style>
    .commandes-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    .commandes-table th, .commandes-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #e2e8f0;
    }
    .commandes-table th {
        background: #f1f5f9;
        font-weight: 600;
        color: #334155;
    }
    .commandes-table tr:hover {
        background: #f8fafc;
    }
    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-block;
    }
    .status-en_cours {
        background: #fef3c7;
        color: #d97706;
    }
    .status-valide {
        background: #dcfce7;
        color: #16a34a;
    }
    .status-annule {
        background: #fee2e2;
        color: #dc2626;
    }
    .payment-badge {
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .payment-cash {
        background: #c7d2e0;
        color: #1e293b;
    }
    .payment-credit {
        background: #dbeafe;
        color: #0c4a6e;
    }
    .payment-pending {
        background: #fce7f3;
        color: #be185d;
    }
    .commandes-summary {
        background: #f1f5f9;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border-left: 4px solid #1e3a5f;
    }
    .commandes-summary p {
        margin: 5px 0;
        color: #334155;
    }
    .commandes-summary strong {
        color: #1e3a5f;
    }
</style>
';

echo $styles;

$total = 0;
$count = 0;
while ($row = $commandes->fetch_assoc()) {
    $total += $row['montant'];
    $count++;
}

$commandes->data_seek(0);

echo '<div class="commandes-summary">';
echo '<p><strong>' . $count . '</strong> طلب(ات)</p>';
echo '<p>المبلغ الإجمالي: <strong>' . number_format($total, 2, ',', ' ') . ' دج</strong></p>';
echo '</div>';

echo '<table class="commandes-table">';
echo '<tr>
    <th>التاريخ</th>
    <th>العميل</th>
    <th>المبلغ</th>
    <th>المصدر</th>
    <th>البائع</th>
    <th>الحالة</th>
</tr>';

while ($cmd = $commandes->fetch_assoc()) {
    $status_class = 'status-' . $cmd['status'];
    
    $status_text = ucfirst(str_replace('_', ' ', $cmd['status']));
    $source_text = htmlspecialchars($cmd['source'] ?? '');
    
    echo '<tr>';
    echo '<td>' . date('d/m/Y H:i', strtotime($cmd['time'])) . '</td>';
    echo '<td><strong>' . htmlspecialchars($cmd['client_nom']) . '</strong></td>';
    echo '<td>' . number_format($cmd['montant'], 2, ',', ' ') . ' دج</td>';
    echo '<td>' . $source_text . '</td>';
    echo '<td>' . htmlspecialchars($cmd['vendeur_nom']) . '</td>';
    echo '<td><span class="status-badge ' . $status_class . '">' . $status_text . '</span></td>';
    echo '</tr>';
}

echo '</table>';
?>

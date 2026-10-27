<?php
// index.php - Public Home Page (Fixed version)
session_start();

if (!file_exists('config.php')) {
    die("خطأ: ملف config.php غير موجود. أنشئه أولاً.");
}

require_once 'config.php';

// Fetch products
$sql = "SELECT id, reference, nom, image, quantité, detail_prix, display_mode 
        FROM product 
        ORDER BY nom ASC";
$result = $conn->query($sql);

$products = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>NettoClean — منتجات التنظيف</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1e3a5f">

    <style>
        :root {
            --primary: #1e3a5f;
            --primary-light: #3b82f6;
            --success: #16a34a;
            --danger: #dc2626;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body.nc-public-body {
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
        }
        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 15px 20px;
            text-align: center;
            font-size: 1.4rem;
            font-weight: bold;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .search-container {
            padding: 20px;
            max-width: 600px;
            margin: 0 auto;
        }
        .search-bar {
            width: 100%;
            padding: 15px 20px;
            border: none;
            border-radius: 50px;
            font-size: 1.1rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
            gap: 15px;
            padding: 0 20px 100px;
        }
        .product-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            padding: 14px;
        }
        .product-image {
            width: 100%;
            height: 120px;
            object-fit: contain;
            background: #f8fafc;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .product-name {
            font-size: 1.05rem;
            margin: 8px 0;
        }
        .product-name.product-display-normal { font-weight: 500; }
        .product-name.product-display-red { font-weight: 800; }
        .product-name.product-display-blue { font-weight: 300; font-size: 0.98rem; }
        .product-name.product-display-green { font-weight: 500; }
        .product-ref {
            font-size: 0.82rem;
            color: #64748b;
        }
        .stock-status {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.82rem;
            font-weight: 600;
            text-align: center;
            margin: 10px 0;
        }
        .in-stock { background:#dcfce7; color:#16a34a; }
        .out-stock { background:#fee2e2; color:#dc2626; }
        .price {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--primary);
            margin: 8px 0;
        }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 15px;
            text-align: center;
            box-shadow: 0 -4px 15px rgba(0,0,0,0.1);
        }
        .big-btn {
            display: block;
            width: 90%;
            max-width: 340px;
            margin: 10px auto;
            padding: 16px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-size: 1.15rem;
            font-weight: bold;
        }
    </style>
</head>
<body class="nc-public-body">

    <header>
        🧼 NettoClean — مستودع التنظيف
    </header>

    <div class="search-container">
        <input type="text" id="searchInput" class="search-bar" placeholder="🔍 ابحث عن منتج...">
    </div>

    <div class="products-grid" id="productsGrid">
        <!-- Filled by JavaScript -->
    </div>

    <div class="footer">
        <a href="login.php" class="big-btn">🔑 تسجيل الدخول / إنشاء حساب عميل</a>
    </div>

    <script>
        const allProducts = <?php echo json_encode($products); ?> || [];

        function productDisplayClass(dm) {
            let m = dm === 'green' ? 'normal' : (dm || 'normal');
            if (!['normal', 'red', 'blue'].includes(m)) m = 'normal';
            return 'product-display-' + m;
        }

        function getStockHTML(q) {
            return q > 0 
                ? `<div class="stock-status in-stock">✅ متوفر</div>`
                : `<div class="stock-status out-stock">⚠️ قريباً في المخزون</div>`;
        }

        function renderProducts(products) {
            const grid = document.getElementById('productsGrid');
            grid.innerHTML = '';

            if (products.length === 0) {
                grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;padding:50px;color:#666;">لا يوجد منتج</p>';
                return;
            }

            products.forEach(p => {
                const card = document.createElement('div');
                card.className = 'product-card';
                card.innerHTML = `
                    ${p.image ? `<img class="product-image" src="uploads/products/${encodeURIComponent(p.image)}" alt="${p.nom}">` : ''}
                    <div class="product-ref">Ref: ${p.reference}</div>
                    <div class="product-name ${productDisplayClass(p.display_mode)}">${p.nom}</div>
                    ${getStockHTML(p.quantité)}
                    <div class="price">${parseFloat(p.detail_prix).toFixed(2)} DA</div>
                    <button onclick="alert('سجّل الدخول لطلب هذا المنتج')" style="width:100%;padding:12px;background:#1e3a5f;color:white;border:none;border-radius:12px;cursor:pointer;">
                        التفاصيل
                    </button>
                `;
                grid.appendChild(card);
            });
        }

        // Live search
        document.getElementById('searchInput').addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            const filtered = allProducts.filter(p => 
                p.nom.toLowerCase().includes(term) || p.reference.toLowerCase().includes(term)
            );
            renderProducts(filtered);
        });

        // Initial load
        renderProducts(allProducts);
    </script>
</body>
</html>
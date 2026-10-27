<?php
header('Content-Type: text/html; charset=utf-8');
// mon_profil.php - My Profile (For Clients to edit their info)
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$is_client = $_SESSION['is_client'] ?? false;

// Only Clients can access this page
if (!$is_client) {
    echo "<h2 style='text-align:center; margin-top:50px; color:red;'>الوصول مرفوض!<br>هذه الصفحة للعملاء فقط.</h2>";
    echo "<p style='text-align:center;'><a href='dashboard.php'>العودة إلى لوحة التحكم</a></p>";
    exit();
}

// Get client info
$client = $conn->query("SELECT id, nom, telephone, created_at, credit, versement_total FROM client WHERE id = $user_id")->fetch_assoc();

$success = '';
$error = '';

// Handle POST request to update profile
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_info') {
        $nom = escape($_POST['nom']);
        $telephone = escape($_POST['telephone']);
        
        // Check if new name exists (exclude current user)
        $check = $conn->query("SELECT id FROM client WHERE nom = '$nom' AND id != $user_id");
        if ($check->num_rows > 0) {
            $error = "هذا الاسم مستخدم، اختر اسماً آخر.";
        } else {
            $stmt = $conn->prepare("UPDATE client SET nom = ?, telephone = ? WHERE id = ?");
            $stmt->bind_param("ssi", $nom, $telephone, $user_id);
            if ($stmt->execute()) {
                $success = "تم تحديث معلوماتك بنجاح!";
                $_SESSION['user_nom'] = $nom;
                $client['nom'] = $nom;
                $client['telephone'] = $telephone;
            } else {
                $error = "خطأ أثناء التحديث.";
            }
        }
    }
    
    if ($action === 'change_password') {
        $old_password = $_POST['old_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify old password
        $client_check = $conn->query("SELECT password FROM client WHERE id = $user_id")->fetch_assoc();
        
        if (!password_verify($old_password, $client_check['password'])) {
            $error = "كلمة المرور القديمة غير صحيحة.";
        } elseif (strlen($new_password) < 6) {
            $error = "يجب أن تكون كلمة المرور الجديدة 6 أحرف على الأقل.";
        } elseif ($new_password !== $confirm_password) {
            $error = "كلمتا المرور غير متطابقتين.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE client SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $user_id);
            if ($stmt->execute()) {
                $success = "تم تغيير كلمة المرور بنجاح!";
            } else {
                $error = "خطأ أثناء التحديث.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>معلوماتي — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1e3a5f">

    <style>
        :root { --primary: #1e3a5f; --success: #16a34a; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { 
            font-family: 'Segoe UI', sans-serif; 
            background: #f8fafc; 
            color: #1e2937;
        }
        header {
            background: var(--primary); 
            color: white; 
            padding: 18px; 
            text-align: center; 
            font-size: 1.5rem;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .container { padding: 20px; max-width: 800px; margin: 0 auto; }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .alert-success {
            background: #dcfce7;
            color: var(--success);
            border: 1px solid var(--success);
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #dc2626;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            transition: 0.3s;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: 0.3s;
        }

        .btn-submit {
            background: var(--primary);
            color: white;
        }

        .btn-submit:hover {
            background: #1e3a8a;
        }

        .btn-cancel {
            background: #64748b;
            color: white;
        }

        .btn-cancel:hover {
            background: #475569;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        .info-box {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary);
        }

        .info-label {
            font-weight: 600;
            color: #64748b;
            font-size: 0.9rem;
        }

        .info-value {
            font-size: 1.1rem;
            color: #1e2937;
            margin-top: 5px;
        }

        .button-group {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .logout-link {
            text-decoration: none;
            color: white;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

    <header>
        <div>👤 معلوماتي</div>
        <a href="logout.php" class="logout-link">تسجيل الخروج</a>
    </header>

    <div class="container">

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= $error ?></div>
        <?php endif; ?>

        <!-- Informations Personnelles -->
        <div class="card">
            <h2 class="section-title">📋 معلوماتي الشخصية</h2>

            <div class="info-box">
                <div class="info-label">معرّف العميل:</div>
                <div class="info-value">#<?= $client['id'] ?></div>
            </div>

            <div class="info-box">
                <div class="info-label">تاريخ التسجيل:</div>
                <div class="info-value"><?= date('d/m/Y H:i', strtotime($client['created_at'])) ?></div>
            </div>

            <div class="info-box">
                <div class="info-label">الدين الحالي:</div>
                <div class="info-value"><?= number_format($client['credit'] ?? 0, 2) ?> دج</div>
            </div>

            <div class="info-box">
                <div class="info-label">إجمالي الدفعات:</div>
                <div class="info-value"><?= number_format($client['versement_total'] ?? 0, 2) ?> دج</div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="update_info">

                <div class="form-row">
                    <div class="form-group">
                        <label for="nom">اسم المستخدم:</label>
                        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($client['nom']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="telephone">الهاتف:</label>
                        <input type="tel" id="telephone" name="telephone" value="<?= htmlspecialchars($client['telephone'] ?? '') ?>" placeholder="+212 612 345 678">
                    </div>
                </div>

                <div class="button-group">
                    <button type="submit" class="btn btn-submit">💾 حفظ التعديلات</button>
                </div>
            </form>
        </div>

        <!-- Changer le Mot de Passe -->
        <div class="card">
            <h2 class="section-title">🔐 تغيير كلمة المرور</h2>

            <form method="POST">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label for="old_password">كلمة المرور القديمة:</label>
                    <input type="password" id="old_password" name="old_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">كلمة المرور الجديدة:</label>
                    <input type="password" id="new_password" name="new_password" placeholder="6 أحرف على الأقل" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">تأكيد كلمة المرور:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <div class="button-group">
                    <button type="submit" class="btn btn-submit">🔄 تغيير كلمة المرور</button>
                </div>
            </form>
        </div>

        <!-- Navigation -->
        <div style="text-align: center; margin-top: 30px;">
            <a href="dashboard.php" class="back-link">← العودة إلى لوحة التحكم</a>
        </div>

    </div>

</body>
</html>

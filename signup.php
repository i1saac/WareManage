<?php
// signup.php - Client Registration
session_start();
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nom = trim($_POST['nom']);
    $telephone = trim($_POST['telephone']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    if (empty($nom) || empty($telephone) || empty($password)) {
        $error = "جميع الحقول مطلوبة";
    } elseif ($password !== $confirm_password) {
        $error = "كلمتا المرور غير متطابقتين";
    } elseif (strlen($password) < 6) {
        $error = "يجب أن تكون كلمة المرور 6 أحرف على الأقل";
    } else {
        // Check if username already exists in client or stuff
        $stmt = $conn->prepare("SELECT id FROM client WHERE LOWER(nom) = LOWER(?)");
        $stmt->bind_param("s", $nom);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "اسم المستخدم مستخدم مسبقاً";
            $stmt->close();
        } else {
            $stmt->close();
            $stmt = $conn->prepare("SELECT id FROM stuff WHERE LOWER(nom) = LOWER(?)");
            $stmt->bind_param("s", $nom);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "اسم المستخدم مستخدم مسبقاً";
                $stmt->close();
            } else {
                $stmt->close();
                $stmt = $conn->prepare("SELECT id FROM admin WHERE LOWER(nom) = LOWER(?)");
                $stmt->bind_param("s", $nom);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $error = "اسم المستخدم مستخدم مسبقاً";
                    $stmt->close();
                } else {
                    $stmt->close();

                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Insert new client with approved = 0 (pending admin approval)
                    $stmt = $conn->prepare("INSERT INTO client (nom, telephone, password, approved) VALUES (?, ?, ?, 0)");
                    $stmt->bind_param("sss", $nom, $telephone, $hashed_password);
                    if ($stmt->execute()) {
                        $success = "✅ تم إنشاء الحساب بنجاح.<br>حسابك الآن قيد المراجعة من الإدارة، وسيتم تفعيله بعد تحديد نوع العميل والموافقة عليه.";
                    } else {
                        $error = "حدث خطأ أثناء إنشاء الحساب. حاول مرة أخرى.";
                    }
                    $stmt->close();
                }
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
    <title>إنشاء حساب — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1e3a5f">

    <style>
        :root {
            --primary: #1e3a5f;
            --primary-light: #3b82f6;
            --success: #16a34a;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body.nc-auth-body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 30px 25px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        h2 {
            text-align: center;
            margin-bottom: 25px;
            color: var(--primary);
            font-size: 1.8rem;
        }
        .form-group {
            margin-bottom: 18px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #374151;
        }
        input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 1.05rem;
        }
        input:focus {
            border-color: var(--primary);
            outline: none;
        }
        .btn {
            width: 100%;
            padding: 15px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }
        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success {
            background: #dcfce7;
            color: var(--success);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            line-height: 1.5;
        }
        .link {
            text-align: center;
            margin-top: 25px;
        }
        .link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .logo {
            text-align: center;
            font-size: 2.8rem;
            margin-bottom: 10px;
        }
    </style>
</head>
<body class="nc-auth-body">

    <div class="container">
        <div class="logo">🧼</div>
        <h2>إنشاء حساب عميل</h2>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= $success ?></div>
            <div style="text-align:center;">
                <a href="login.php" class="btn" style="background:#16a34a; display:inline-block; width:auto; padding:12px 30px;">
                    الانتقال لتسجيل الدخول
                </a>
            </div>
        <?php else: ?>

        <form method="POST">
            <div class="form-group">
                <label>الاسم الكامل / اسم الشركة</label>
                <input type="text" name="nom" required autofocus>
            </div>

            <div class="form-group">
                <label>الهاتف</label>
                <input type="tel" name="telephone" required>
            </div>

            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="password" required>
            </div>

            <div class="form-group">
                <label>تأكيد كلمة المرور</label>
                <input type="password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn">إنشاء حسابي</button>
        </form>

        <div class="link">
            لديك حساب؟ <a href="login.php">تسجيل الدخول</a>
        </div>

        <?php endif; ?>

        <div style="text-align:center; margin-top:25px;">
            <a href="index.php" style="color:#64748b; text-decoration:none;">← العودة للصفحة الرئيسية</a>
        </div>
    </div>

</body>
</html>
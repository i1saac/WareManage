<?php
// login.php
session_start();
require_once 'config.php';

$error = '';

function login_password_verify($password, $storedHash) {
    // Support both hashed passwords and legacy plain-text passwords
    if (password_verify($password, $storedHash)) {
        return true;
    }

    $info = password_get_info($storedHash);
    if ($info['algo'] === 0 && $password === $storedHash) {
        return true;
    }

    return false;
}

function upgrade_password_hash($conn, $table, $id, $password) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE $table SET password = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $newHash, $id);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nom = trim($_POST['nom']);
    $password = trim($_POST['password']);

    if (empty($nom) || empty($password)) {
        $error = "يرجى تعبئة جميع الحقول";
    } else {
        // Check in admin table first
        $stmt = $conn->prepare("SELECT id, nom, password FROM admin WHERE LOWER(nom) = LOWER(?)");
        $stmt->bind_param("s", $nom);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (login_password_verify($password, $user['password'])) {
                if (password_get_info($user['password'])['algo'] === 0) {
                    upgrade_password_hash($conn, 'admin', $user['id'], $password);
                }

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nom'] = $user['nom'];
                $_SESSION['user_type'] = 'admin';
                $_SESSION['is_client'] = false;
                
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "كلمة المرور غير صحيحة";
            }
        } else {
            // Check in stuff table (vendeur/admin staff)
            $stmt = $conn->prepare("SELECT id, nom, password, post FROM stuff WHERE LOWER(nom) = LOWER(?) AND post IN ('vendeur', 'admin')");
            $stmt->bind_param("s", $nom);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                if (login_password_verify($password, $user['password'])) {
                    if (password_get_info($user['password'])['algo'] === 0) {
                        upgrade_password_hash($conn, 'stuff', $user['id'], $password);
                    }

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_nom'] = $user['nom'];
                    $_SESSION['user_type'] = ($user['post'] === 'admin') ? 'staff_admin' : 'vendeur';
                    $_SESSION['is_client'] = false;
                    
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = "كلمة المرور غير صحيحة";
                }
            } else {
                // Check in client table
                $stmt = $conn->prepare("SELECT id, nom, password, approved FROM client WHERE LOWER(nom) = LOWER(?)");
                $stmt->bind_param("s", $nom);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    if ($user['approved'] == 0) {
                        $error = "لم يتم اعتماد حسابك بعد من الإدارة";
                    } elseif (login_password_verify($password, $user['password'])) {
                        if (password_get_info($user['password'])['algo'] === 0) {
                            upgrade_password_hash($conn, 'client', $user['id'], $password);
                        }

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_nom'] = $user['nom'];
                        $_SESSION['user_type'] = 'client';
                        $_SESSION['is_client'] = true;
                        
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        $error = "كلمة المرور غير صحيحة";
                    }
                } else {
                    $error = "اسم المستخدم غير موجود";
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
    <title>تسجيل الدخول — NettoClean</title>
    <link rel="stylesheet" href="theme.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1e3a5f">

    <style>
        :root {
            --primary: #1e3a5f;
            --primary-light: #3b82f6;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body.nc-auth-body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            padding: 30px 25px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 380px;
            border: 1px solid rgba(255,255,255,0.4);
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
            transition: all 0.3s;
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
        .btn:hover {
            background: #0f2a45;
        }
        .error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.95rem;
        }
        .link {
            text-align: center;
            margin-top: 25px;
            font-size: 1rem;
        }
        .link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .logo {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
    </style>
</head>
<body class="nc-auth-body">

    <div class="login-container">
        <div class="logo">🧼</div>
        <h2>تسجيل الدخول</h2>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>اسم المستخدم</label>
                <input type="text" name="nom" required autofocus>
            </div>

            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="password" required>
            </div>

            <button type="submit" class="btn">دخول</button>
        </form>

        <div class="link">
            ليس لديك حساب؟ <a href="signup.php">إنشاء حساب عميل</a>
        </div>

        <div style="text-align:center; margin-top:30px;">
            <a href="index.php" style="color:#64748b; text-decoration:none;">← العودة للصفحة الرئيسية</a>
        </div>
    </div>

</body>
</html>
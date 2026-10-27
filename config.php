<?php
// config.php - Database Connection (Simple & Secure)

//define('DB_HOST', 'sql212.infinityfree.com');
//define('DB_USER', 'if0_41621461');           // Change if your username is different
//define('DB_PASSWORD', 'kzVewE5xXt');           // Put your MySQL password here (empty for XAMPP)
//define('DB_NAME', 'if0_41621461_warehouse_db');   // Change to your actual database name
define('DB_HOST', 'sql305.infinityfree.com');
define('DB_USER', 'if0_42358454');
define('DB_PASSWORD', 'Note8Pro1');
define('DB_NAME', 'if0_42358454_ware_db');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME,3306);

// Check connection
if ($conn->connect_error) {
    die("خطأ في الاتصال بقاعدة البيانات: " . $conn->connect_error);
}

// Set charset to utf8mb4 for Arabic/French support
$conn->set_charset("utf8mb4");

class CompatResult {
    private $result;

    public function __construct($result) {
        $this->result = $result;
    }

    public function fetchAll() {
        if (!$this->result) {
            return [];
        }
        $rows = [];
        while ($row = $this->result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function fetch() {
        if (!$this->result) {
            return null;
        }
        return $this->result->fetch_assoc();
    }

    public function fetchColumn() {
        $row = $this->fetch();
        return $row ? current($row) : false;
    }
}

class CompatStatement {
    private $conn;
    private $sql;
    private $stmt;

    public function __construct($conn, $sql) {
        $this->conn = $conn;
        $this->sql = $sql;
    }

    private function prepareWithParams($params) {
        $sql = $this->sql;
        $values = [];
        $types = '';

        if (!empty($params)) {
            preg_match_all('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);
            $placeholders = $matches[1] ?? [];

            foreach ($placeholders as $placeholder) {
                if (!array_key_exists($placeholder, $params)) {
                    throw new Exception("Missing parameter {$placeholder}");
                }

                $value = $params[$placeholder];
                $types .= $this->getType($value);
                $values[] = $value;
            }

            $sql = preg_replace('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', '?', $sql);
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($this->conn->error);
        }

        if (!empty($values)) {
            $stmt->bind_param($types, ...$values);
        }

        return $stmt;
    }

    private function getType($value) {
        if (is_int($value)) {
            return 'i';
        }
        if (is_double($value)) {
            return 'd';
        }
        return 's';
    }

    public function execute($params = []) {
        $this->stmt = $this->prepareWithParams($params);
        return $this->stmt->execute();
    }

    public function fetchAll() {
        if (!$this->stmt || !$this->stmt->result_metadata()) {
            return [];
        }

        $result = $this->stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function fetch() {
        if (!$this->stmt || !$this->stmt->result_metadata()) {
            return null;
        }

        $result = $this->stmt->get_result();
        return $result ? $result->fetch_assoc() : null;
    }

    public function fetchColumn() {
        $row = $this->fetch();
        return $row ? current($row) : false;
    }
}

class CompatPDO {
    private $conn;
    private $inTransaction = false;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function query($sql) {
        $result = $this->conn->query($sql);
        if ($result === false) {
            throw new Exception($this->conn->error);
        }
        return new CompatResult($result);
    }

    public function prepare($sql) {
        return new CompatStatement($this->conn, $sql);
    }

    public function beginTransaction() {
        $this->conn->begin_transaction();
        $this->inTransaction = true;
        return true;
    }

    public function commit() {
        $this->conn->commit();
        $this->inTransaction = false;
        return true;
    }

    public function rollBack() {
        $this->conn->rollback();
        $this->inTransaction = false;
        return true;
    }

    public function inTransaction() {
        return $this->inTransaction;
    }

    public function lastInsertId() {
        return $this->conn->insert_id;
    }
}

// PDO connection for pages that use PDO (such as achats_bank.php), with a fallback for shared hosting.
$pdo = null;
if (class_exists('PDO') && extension_loaded('pdo_mysql')) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASSWORD,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        $pdo = null;
    }
}

if (!$pdo) {
    $pdo = new CompatPDO($conn);
}

// Optional: Helpful function to make queries easier
function query($sql) {
    global $conn;
    return $conn->query($sql);
}

function escape($string) {
    global $conn;
    return $conn->real_escape_string($string);
}

function ensureColumn($table, $column, $definition) {
    global $conn;
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN $definition");
    }
}

ensureColumn('purchases', 'mon_credit', 'mon_credit decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('purchases', 'total_actuel', 'total_actuel decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('purchases', 'remise', 'remise decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('purchases', 'mon_versement', 'mon_versement decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('purchases', 'credit_actuel', 'credit_actuel decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('fournisseur', 'mon_credit', 'mon_credit decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('fournisseur', 'mon_versement_total', 'mon_versement_total decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('client', 'activity_score', 'activity_score decimal(12,2) AS (versement_total - credit) STORED');
ensureColumn('stuff', 'credit', 'credit decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('stuff', 'salaire_par_jour', 'salaire_par_jour decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('stuff', 'nombre_d_absence', 'nombre_d_absence int(11) NOT NULL DEFAULT 0');
ensureColumn('stuff', 'salaire_par_mois', 'salaire_par_mois decimal(12,2) NOT NULL DEFAULT 0.00');
ensureColumn('client', 'approved', 'approved TINYINT(1) NOT NULL DEFAULT 0');
ensureColumn('client', 'type', "type ENUM('supergross', 'gross', 'detail') NOT NULL DEFAULT 'detail'");
ensureColumn('product', 'display_mode', "display_mode ENUM('normal', 'red', 'blue', 'green') NOT NULL DEFAULT 'normal'");

// Affichage produit : poids d'écriture (normal / gras rouge / fin bleu) — retirer vert
$resDm = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'display_mode'");
if ($resDm && ($rowDm = $resDm->fetch_assoc()) && strpos($rowDm['COLUMN_TYPE'] ?? '', 'green') !== false) {
    $conn->query("UPDATE product SET display_mode = 'normal' WHERE display_mode = 'green'");
    $conn->query("ALTER TABLE product MODIFY COLUMN display_mode ENUM('normal','red','blue') NOT NULL DEFAULT 'normal'");
}

// Achats : plus de colonne payment_type
$resPt = $conn->query("SHOW COLUMNS FROM purchases LIKE 'payment_type'");
if ($resPt && $resPt->num_rows > 0) {
    $conn->query('ALTER TABLE purchases DROP COLUMN payment_type');
}
ensureColumn('sales', 'admin_id', 'admin_id int(11) DEFAULT NULL COMMENT "Admin créateur ou confirmateur"');
ensureColumn('sales', 'seller_stuff_id', 'seller_stuff_id int(11) DEFAULT NULL COMMENT "Vendeur qui a marqué prêt"');

$res = $conn->query("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'admin_id'");
if ($res && ($col = $res->fetch_assoc()) && ($col['IS_NULLABLE'] ?? '') === 'NO') {
    $conn->query("ALTER TABLE `sales` MODIFY `admin_id` int(11) DEFAULT NULL");
}

// Foreign key constraints are now properly defined in warehouse_db.sql
// - sales.admin_id references admin.id (nullable for commandes client avant confirmation)
// - purchases.stuff_id references admin.id

$conn->query("CREATE TABLE IF NOT EXISTS `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `admin_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cause` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `fk_admin_expenses_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$fk_check = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_sales_admin'");
if ($fk_check && $fk_check->num_rows === 0) {
    $conn->query("ALTER TABLE sales ADD CONSTRAINT `fk_sales_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin`(`id`) ON DELETE SET NULL ON UPDATE CASCADE");
}

$fk_seller = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_sales_seller_stuff'");
if ($fk_seller && $fk_seller->num_rows === 0) {
    $conn->query("ALTER TABLE sales ADD CONSTRAINT `fk_sales_seller_stuff` FOREIGN KEY (`seller_stuff_id`) REFERENCES `stuff`(`id`) ON DELETE SET NULL ON UPDATE CASCADE");
}

// Retrait des colonnes d'achat cumulées : coût = prix_achat * nombre_de_vente (généré dans benefit)
$migAch = $conn->query("SHOW COLUMNS FROM `product` LIKE 'nombre_d_achat'");
if ($migAch && $migAch->num_rows > 0) {
    $conn->query("ALTER TABLE `product` DROP COLUMN `benefit`");
    $conn->query("ALTER TABLE `product` DROP COLUMN `nombre_d_achat`");
    $conn->query("ALTER TABLE `product` DROP COLUMN `montant_d_achat`");
    $conn->query("ALTER TABLE `product` ADD COLUMN `benefit` decimal(12,2) GENERATED ALWAYS AS (`montant_de_vente` - (`prix_achat` * `nombre_de_vente`)) STORED");
}
?>
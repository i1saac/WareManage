<?php

function ensure_clients_bank_total_ventes($conn) {
    $res = $conn->query("SHOW COLUMNS FROM clients_bank LIKE 'total_ventes'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE clients_bank ADD COLUMN total_ventes DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Somme des total_ttc des ventes du client'");
    }
}

function refresh_client_total_ventes($conn, $client_id = null) {
    ensure_clients_bank_total_ventes($conn);
    if ($client_id !== null && (int)$client_id > 0) {
        $cid = (int)$client_id;
        $stmt = $conn->prepare(
            "UPDATE clients_bank cb
             SET total_ventes = (
                 SELECT COALESCE(SUM(vb.total_ttc), 0)
                 FROM vente_bank vb
                 WHERE vb.clients_bank_id = cb.id
             )
             WHERE cb.id = ?"
        );
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $stmt->close();
        return;
    }
    $conn->query(
        "UPDATE clients_bank cb
         SET total_ventes = (
             SELECT COALESCE(SUM(vb.total_ttc), 0)
             FROM vente_bank vb
             WHERE vb.clients_bank_id = cb.id
         )"
    );
}

function get_client_total_ventes($conn, $client_id) {
    ensure_clients_bank_total_ventes($conn);
    $cid = (int)$client_id;
    if ($cid <= 0) {
        return 0.0;
    }
    $stmt = $conn->prepare("SELECT total_ventes FROM clients_bank WHERE id = ?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['total_ventes'] : 0.0;
}

<?php
function ensure_collection_schema() {
    static $ready = false;
    if ($ready) return;

    DB::q("CREATE TABLE IF NOT EXISTS collection_cycles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        portfolio_name VARCHAR(180) DEFAULT NULL,
        month_key CHAR(7) NOT NULL,
        status ENUM('draft','active','review','closed','archived') NOT NULL DEFAULT 'draft',
        created_by INT NOT NULL,
        started_at DATETIME DEFAULT NULL,
        submitted_at DATETIME DEFAULT NULL,
        closed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_cycle_month (month_key),
        KEY idx_cycle_status (status),
        KEY idx_cycle_creator (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::q("CREATE TABLE IF NOT EXISTS collection_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT NOT NULL,
        user_id INT NOT NULL,
        assigned_count INT NOT NULL DEFAULT 0,
        uploaded_count INT NOT NULL DEFAULT 0,
        status ENUM('pending','working','submitted','approved') NOT NULL DEFAULT 'pending',
        submitted_at DATETIME DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cycle_user (cycle_id,user_id),
        KEY idx_assignment_cycle (cycle_id),
        KEY idx_assignment_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::q("CREATE TABLE IF NOT EXISTS collection_employee_collections (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT NOT NULL,
        employee_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_employee_collection_cycle_user (cycle_id,employee_id),
        KEY idx_employee_collection_creator (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::q("CREATE TABLE IF NOT EXISTS collection_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT DEFAULT NULL,
        client_id INT NOT NULL,
        user_id INT NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        note TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event_client (client_id),
        KEY idx_event_cycle (cycle_id),
        KEY idx_event_user (user_id),
        KEY idx_event_type (event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::q("CREATE TABLE IF NOT EXISTS collection_archive_clients (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT NOT NULL,
        original_client_id INT NOT NULL,
        employee_id INT DEFAULT NULL,
        snapshot_json LONGTEXT NOT NULL,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_archive_cycle (cycle_id),
        KEY idx_archive_client (original_client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::q("CREATE TABLE IF NOT EXISTS collection_notes (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT DEFAULT NULL,
        client_id INT NOT NULL,
        user_id INT NOT NULL,
        note_type VARCHAR(40) NOT NULL DEFAULT 'general',
        body TEXT NOT NULL,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_note_client (client_id),
        KEY idx_note_cycle (cycle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    DB::q("CREATE TABLE IF NOT EXISTS sheet_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        cycle_id INT DEFAULT NULL,
        action VARCHAR(40) NOT NULL,
        filename VARCHAR(255) DEFAULT NULL,
        client_count INT DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sheetlog_cycle (cycle_id),
        KEY idx_sheetlog_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [];
    foreach (DB::all("SHOW COLUMNS FROM clients") as $column) {
        $columns[$column['Field']] = true;
    }
    $clientColumns = [
        'cycle_id' => "ALTER TABLE clients ADD COLUMN cycle_id INT DEFAULT NULL AFTER owner_user_id",
        'last_activity_at' => "ALTER TABLE clients ADD COLUMN last_activity_at DATETIME DEFAULT NULL",
        'ptp_amount' => "ALTER TABLE clients ADD COLUMN ptp_amount DECIMAL(14,2) DEFAULT NULL",
        'ptp_status' => "ALTER TABLE clients ADD COLUMN ptp_status VARCHAR(30) DEFAULT NULL",
        'dcr_code' => "ALTER TABLE clients ADD COLUMN dcr_code VARCHAR(50) DEFAULT NULL",
        'collection_state' => "ALTER TABLE clients ADD COLUMN collection_state VARCHAR(40) DEFAULT 'new'",
        'submitted_at' => "ALTER TABLE clients ADD COLUMN submitted_at DATETIME DEFAULT NULL",
    ];
    foreach ($clientColumns as $name => $sql) {
        if (!isset($columns[$name])) DB::q($sql);
    }

    $sheetLogColumns = [];
    foreach (DB::all("SHOW COLUMNS FROM sheet_log") as $column) {
        $sheetLogColumns[$column['Field']] = true;
    }
    if (!isset($sheetLogColumns['cycle_id'])) {
        DB::q("ALTER TABLE sheet_log ADD COLUMN cycle_id INT DEFAULT NULL AFTER user_id");
    }

    $ready = true;
}

function collection_log_event($cycleId, $clientId, $userId, $type, $old = null, $new = null, $note = null) {
    ensure_collection_schema();
    DB::insert('collection_events', [
        'cycle_id' => $cycleId ?: null,
        'client_id' => (int)$clientId,
        'user_id' => (int)$userId,
        'event_type' => $type,
        'old_value' => $old === null ? null : (is_string($old) ? $old : json_encode($old, JSON_UNESCAPED_UNICODE)),
        'new_value' => $new === null ? null : (is_string($new) ? $new : json_encode($new, JSON_UNESCAPED_UNICODE)),
        'note' => $note,
    ]);
}

-- Revizii & Mentenanta V2 (pagina unica): planuri X / Y, ciclu 5 + 1, lucrari, executii pe operatiuni.
-- Se aplica si la rulare (ReviziiV2Model::ensureSchema), doar cand lipsesc tabele; tot acolo se
-- populeaza O SINGURA DATA catalogul operatiunilor si sabloanele X / Y standard (fara intervale).
-- Tabelele vechi Revizii (revizii_planuri / revizii_istoric / revizii_sas_*) nu se modifica.

CREATE TABLE IF NOT EXISTS revizii_v2_operatii (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cod VARCHAR(60) NOT NULL,
    componenta VARCHAR(120) NOT NULL,
    tip_operatie VARCHAR(20) NOT NULL DEFAULT 'inlocuire',
    operatie VARCHAR(190) NOT NULL,
    categorie VARCHAR(30) NOT NULL DEFAULT 'altele',
    sistem_fluid VARCHAR(40) NULL,
    aplicabil VARCHAR(120) NOT NULL DEFAULT 'cap_tractor,camion',
    factori_uzura VARCHAR(500) NULL,
    criterii_candidate VARCHAR(120) NULL,
    valori_candidate VARCHAR(255) NULL,
    component_key VARCHAR(10) NULL,
    lubrifiant VARCHAR(120) NULL,
    puncte_gresare SMALLINT UNSIGNED NULL,
    descriere TEXT NULL,
    de_verificat TINYINT(1) NOT NULL DEFAULT 0,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rv2_op_cod (cod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_sabloane (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cod VARCHAR(60) NOT NULL,
    nume VARCHAR(120) NOT NULL,
    tip CHAR(1) NOT NULL DEFAULT 'X',
    parinte_id INT UNSIGNED NULL,
    versiune INT UNSIGNED NOT NULL DEFAULT 1,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rv2_sablon_cod (cod),
    CONSTRAINT fk_rv2_sablon_parinte FOREIGN KEY (parinte_id) REFERENCES revizii_v2_sabloane (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_sablon_operatii (
    sablon_id INT UNSIGNED NOT NULL,
    operatie_id INT UNSIGNED NOT NULL,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (sablon_id, operatie_id),
    CONSTRAINT fk_rv2_so_sablon FOREIGN KEY (sablon_id) REFERENCES revizii_v2_sabloane (id) ON DELETE CASCADE,
    CONSTRAINT fk_rv2_so_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_planuri (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(120) NOT NULL,
    tip_vehicul VARCHAR(30) NULL,
    marca VARCHAR(50) NULL,
    model VARCHAR(50) NULL,
    sablon_x_id INT UNSIGNED NOT NULL,
    sablon_y_id INT UNSIGNED NULL,
    interval_km INT UNSIGNED NULL,
    interval_ore INT UNSIGNED NULL,
    interval_luni SMALLINT UNSIGNED NULL,
    logica VARCHAR(3) NOT NULL DEFAULT 'or',
    y_prag_km INT UNSIGNED NULL,
    y_interval_km INT UNSIGNED NULL,
    y_toleranta_km INT UNSIGNED NULL,
    politica_x VARCHAR(10) NOT NULL DEFAULT 'reset',
    sursa_km VARCHAR(10) NOT NULL DEFAULT 'auto',
    prag_km INT UNSIGNED NULL,
    prag_ore INT UNSIGNED NULL,
    prag_zile SMALLINT UNSIGNED NULL,
    cost_estimat_x DECIMAL(12,2) NULL,
    cost_estimat_y DECIMAL(12,2) NULL,
    responsabili VARCHAR(255) NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rv2_plan_x FOREIGN KEY (sablon_x_id) REFERENCES revizii_v2_sabloane (id),
    CONSTRAINT fk_rv2_plan_y FOREIGN KEY (sablon_y_id) REFERENCES revizii_v2_sabloane (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_plan_reguli (
    plan_id INT UNSIGNED NOT NULL,
    operatie_id INT UNSIGNED NOT NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    interval_km INT UNSIGNED NULL,
    interval_ore INT UNSIGNED NULL,
    interval_luni SMALLINT UNSIGNED NULL,
    logica VARCHAR(3) NOT NULL DEFAULT 'or',
    coef_ralanti_km DECIMAL(6,2) NULL,
    cantitate DECIMAL(10,3) NULL,
    um VARCHAR(10) NULL,
    produs_id INT UNSIGNED NULL,
    cost_estimat DECIMAL(12,2) NULL,
    PRIMARY KEY (plan_id, operatie_id),
    CONSTRAINT fk_rv2_pr_plan FOREIGN KEY (plan_id) REFERENCES revizii_v2_planuri (id) ON DELETE CASCADE,
    CONSTRAINT fk_rv2_pr_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_vehicule (
    vehicle_id INT UNSIGNED NOT NULL PRIMARY KEY,
    plan_id INT UNSIGNED NULL,
    x_de_la_ultima_y SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    y_efectuate SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    referinta_data DATETIME NULL,
    referinta_km DECIMAL(12,1) NULL,
    referinta_ore DECIMAL(10,1) NULL,
    ultima_y_data DATETIME NULL,
    ultima_y_km DECIMAL(12,1) NULL,
    origine_grila_km DECIMAL(12,1) NULL,
    observatii VARCHAR(500) NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rv2_veh_plan FOREIGN KEY (plan_id) REFERENCES revizii_v2_planuri (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_vehicul_reguli (
    vehicle_id INT UNSIGNED NOT NULL,
    operatie_id INT UNSIGNED NOT NULL,
    activ TINYINT(1) NULL,
    interval_km INT UNSIGNED NULL,
    interval_ore INT UNSIGNED NULL,
    interval_luni SMALLINT UNSIGNED NULL,
    cantitate DECIMAL(10,3) NULL,
    motiv VARCHAR(255) NULL,
    PRIMARY KEY (vehicle_id, operatie_id),
    CONSTRAINT fk_rv2_vr_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_lucrari (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NULL,
    tip CHAR(1) NOT NULL DEFAULT 'X',
    nr_x SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nr_y SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    km_tinta DECIMAL(12,1) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'programata',
    activ_vehicle_id INT UNSIGNED NULL,
    data_programata DATE NULL,
    data_executie DATETIME NULL,
    km_executie DECIMAL(12,1) NULL,
    km_sursa VARCHAR(10) NULL,
    ore_executie DECIMAL(10,1) NULL,
    tehnician VARCHAR(120) NULL,
    observatii TEXT NULL,
    cost_manopera DECIMAL(12,2) NULL,
    cost_materiale DECIMAL(12,2) NULL,
    cost_total DECIMAL(12,2) NULL,
    completa TINYINT(1) NOT NULL DEFAULT 0,
    snapshot LONGTEXT NULL,
    created_by INT UNSIGNED NULL,
    finalized_by INT UNSIGNED NULL,
    finalized_at DATETIME NULL,
    anulat_motiv VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rv2_lucrare_activa (activ_vehicle_id),
    KEY idx_rv2_lucrare_vehicul (vehicle_id, status, data_executie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_lucrari_operatii (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lucrare_id INT UNSIGNED NOT NULL,
    operatie_id INT UNSIGNED NOT NULL,
    sectiune CHAR(1) NOT NULL DEFAULT 'X',
    componenta VARCHAR(120) NOT NULL,
    operatie VARCHAR(190) NOT NULL,
    tip_operatie VARCHAR(20) NOT NULL,
    categorie VARCHAR(30) NULL,
    regula_snapshot TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    motiv VARCHAR(255) NULL,
    cantitate_estimata DECIMAL(10,3) NULL,
    cantitate_reala DECIMAL(10,3) NULL,
    um VARCHAR(10) NULL,
    produs_id INT UNSIGNED NULL,
    recipient_id INT UNSIGNED NULL,
    miscare_id BIGINT UNSIGNED NULL,
    cost_material DECIMAL(12,2) NULL,
    observatii VARCHAR(500) NULL,
    executie_id INT UNSIGNED NULL,
    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_rv2_lo (lucrare_id, operatie_id),
    CONSTRAINT fk_rv2_lo_lucrare FOREIGN KEY (lucrare_id) REFERENCES revizii_v2_lucrari (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_executii (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    operatie_id INT UNSIGNED NOT NULL,
    lucrare_id INT UNSIGNED NULL,
    tip_executie VARCHAR(20) NOT NULL,
    reseteaza TINYINT(1) NOT NULL DEFAULT 1,
    data_executie DATETIME NOT NULL,
    km DECIMAL(12,1) NULL,
    km_sursa VARCHAR(10) NULL,
    ore DECIMAL(10,1) NULL,
    cantitate DECIMAL(10,3) NULL,
    um VARCHAR(10) NULL,
    produs_id INT UNSIGNED NULL,
    miscare_id BIGINT UNSIGNED NULL,
    cost DECIMAL(12,2) NULL,
    tehnician VARCHAR(120) NULL,
    observatii VARCHAR(500) NULL,
    idempotency_key VARCHAR(80) NOT NULL,
    anulat_la DATETIME NULL,
    anulat_motiv VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rv2_exec_key (idempotency_key),
    KEY idx_rv2_exec_vo (vehicle_id, operatie_id, data_executie),
    CONSTRAINT fk_rv2_exec_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_documente (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lucrare_id INT UNSIGNED NOT NULL,
    document_path VARCHAR(255) NOT NULL,
    nume_original VARCHAR(255) NOT NULL,
    mime VARCHAR(120) NULL,
    marime INT UNSIGNED NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    KEY idx_rv2_doc_lucrare (lucrare_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizii_v2_setari (
    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- Chat Interactiv (LPG Connect): lume 3D multiplayer, panou informativ, chat
-- 2026-11-20
--
-- CONTEXT
--   ?page=chat_interactiv. Tabelele sunt izolate (prefix chat_interactiv_),
--   nu ating modulele Fleet. Identitatea = utilizatori.id (fără conturi noi).
--   Notele expirate / arhivate NU se șterg: ies de pe panoul activ, rămân în
--   tabel și în istoric (chat_interactiv_note_history).
--   Integrarea WhatsApp de mai târziu scrie în aceleași note (sistemul central
--   de sarcini), nu într-o copie.
--
--   Schema se creează și singură la prima deschidere a paginii
--   (ChatInteractivService::ensureSchema), ca pe VPS să nu fie nevoie de
--   rularea manuală a fișierului.
--
-- SAFETY CONTRACT
--   * Additive: doar tabele noi (CREATE TABLE IF NOT EXISTS) + o cameră implicită.
-- =====================================================================

CREATE TABLE IF NOT EXISTS chat_interactiv_rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cod VARCHAR(40) NOT NULL,
    nume VARCHAR(120) NOT NULL,
    max_participanti SMALLINT UNSIGNED NOT NULL DEFAULT 16,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_chat_interactiv_rooms_cod (cod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO chat_interactiv_rooms (cod, nume, max_participanti, activ, created_at)
VALUES ('sediu', 'Sediul LPG', 16, 1, NOW());

CREATE TABLE IF NOT EXISTS chat_interactiv_avatars (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    avatar_key VARCHAR(40) NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_ci_avatars_user FOREIGN KEY (user_id) REFERENCES utilizatori (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_interactiv_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    categorie ENUM('noutate', 'personal', 'termen') NOT NULL,
    titlu VARCHAR(200) NOT NULL,
    descriere TEXT NULL,
    autor_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NULL COMMENT 'personal: proprietarul; termen: angajatul atribuit',
    importanta ENUM('normala', 'ridicata', 'critica') NOT NULL DEFAULT 'normala',
    prioritate ENUM('scazuta', 'normala', 'ridicata', 'urgenta') NOT NULL DEFAULT 'normala',
    status ENUM('activ', 'in_lucru', 'finalizat', 'arhivat') NOT NULL DEFAULT 'activ',
    termen DATETIME NULL,
    reminder_minute INT UNSIGNED NULL COMMENT 'minute înainte de termen',
    reminder_trimis_la DATETIME NULL,
    atasament_original VARCHAR(255) NULL,
    atasament_stocat VARCHAR(255) NULL,
    atasament_mime VARCHAR(120) NULL,
    sursa VARCHAR(30) NOT NULL DEFAULT 'chat_interactiv' COMMENT 'ex. whatsapp mai târziu',
    publicat_la DATETIME NOT NULL,
    finalizat_la DATETIME NULL,
    arhivat_la DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ci_notes_board (room_id, categorie, status, publicat_la),
    INDEX idx_ci_notes_owner (owner_id, categorie, status),
    INDEX idx_ci_notes_autor (autor_id),
    INDEX idx_ci_notes_reminder (status, reminder_trimis_la, termen),
    CONSTRAINT fk_ci_notes_room FOREIGN KEY (room_id) REFERENCES chat_interactiv_rooms (id),
    CONSTRAINT fk_ci_notes_autor FOREIGN KEY (autor_id) REFERENCES utilizatori (id),
    CONSTRAINT fk_ci_notes_owner FOREIGN KEY (owner_id) REFERENCES utilizatori (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publicul unei noutăți: fără rânduri = toți cei cu acces la pagină.
CREATE TABLE IF NOT EXISTS chat_interactiv_note_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    tip ENUM('rol', 'utilizator') NOT NULL,
    valoare VARCHAR(60) NOT NULL,
    UNIQUE KEY uq_ci_note_perm (note_id, tip, valoare),
    CONSTRAINT fk_ci_note_perm_note FOREIGN KEY (note_id) REFERENCES chat_interactiv_notes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_interactiv_note_reads (
    note_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    citit_la DATETIME NOT NULL,
    confirmat_la DATETIME NULL,
    PRIMARY KEY (note_id, user_id),
    CONSTRAINT fk_ci_note_reads_note FOREIGN KEY (note_id) REFERENCES chat_interactiv_notes (id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_note_reads_user FOREIGN KEY (user_id) REFERENCES utilizatori (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_interactiv_note_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    actiune VARCHAR(40) NOT NULL,
    detalii TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ci_note_history_note (note_id, id),
    CONSTRAINT fk_ci_note_history_note FOREIGN KEY (note_id) REFERENCES chat_interactiv_notes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_interactiv_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    mesaj TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ci_messages_room (room_id, id),
    CONSTRAINT fk_ci_messages_room FOREIGN KEY (room_id) REFERENCES chat_interactiv_rooms (id),
    CONSTRAINT fk_ci_messages_user FOREIGN KEY (user_id) REFERENCES utilizatori (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ultimul mesaj citit (numărul de mesaje necitite).
CREATE TABLE IF NOT EXISTS chat_interactiv_chat_reads (
    user_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NOT NULL,
    last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, room_id),
    CONSTRAINT fk_ci_chat_reads_user FOREIGN KEY (user_id) REFERENCES utilizatori (id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_chat_reads_room FOREIGN KEY (room_id) REFERENCES chat_interactiv_rooms (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

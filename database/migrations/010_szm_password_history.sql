-- =============================================================================
-- 010_szm_password_history.sql
-- Historial de contraseñas por usuario — previene reutilización.
--
-- AuthService::resetPassword() y UserController::update() consultan esta tabla
-- antes de aceptar una nueva contraseña para asegurarse de que no coincida con
-- ninguna de las últimas N anteriores (configurable en AuthService, default: 5).
-- =============================================================================

CREATE TABLE IF NOT EXISTS szm_password_history (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL COMMENT 'FK a szm_users.id',
    password_hash VARCHAR(255) NOT NULL   COMMENT 'Hash bcrypt de la contraseña anterior',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_ph_user_created (user_id, created_at DESC),

    CONSTRAINT fk_ph_user
        FOREIGN KEY (user_id)
        REFERENCES szm_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- Migration 005 — szm_audit_log
-- Registro de auditoría de acciones críticas del sistema.
--
-- Acciones estándar del núcleo:
--   user.login            — inicio de sesión exitoso
--   user.login_failed     — intento de login fallido
--   user.locked           — cuenta bloqueada por intentos fallidos
--   user.logout           — cierre de sesión
--   password.reset_request — solicitud de recuperación de contraseña
--   password.reset        — contraseña restablecida exitosamente
--   model.created         — registro creado (trait Auditable)
--   model.updated         — registro modificado (trait Auditable)
--   model.deleted         — registro eliminado (trait Auditable)
-- =============================================================================

CREATE TABLE IF NOT EXISTS szm_audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NULL     DEFAULT NULL  COMMENT 'NULL = acción del sistema',
    action      VARCHAR(100)    NOT NULL               COMMENT 'Clave de acción: modulo.evento',
    entity_type VARCHAR(150)    NULL     DEFAULT NULL  COMMENT 'FQCN del modelo afectado',
    entity_id   INT UNSIGNED    NULL     DEFAULT NULL  COMMENT 'ID del registro afectado',
    old_values  JSON            NULL     DEFAULT NULL  COMMENT 'Estado anterior (columnas relevantes)',
    new_values  JSON            NULL     DEFAULT NULL  COMMENT 'Estado nuevo (columnas modificadas)',
    ip          VARCHAR(45)     NULL     DEFAULT NULL,
    user_agent  VARCHAR(500)    NULL     DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_audit_user_id             (user_id),
    INDEX idx_audit_action              (action),
    INDEX idx_audit_entity              (entity_type, entity_id),
    INDEX idx_audit_created_at          (created_at),

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES szm_users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log de acciones críticas del sistema';
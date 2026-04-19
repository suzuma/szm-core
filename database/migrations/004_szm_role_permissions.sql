-- =============================================================================
-- Migration 004 — szm_role_permissions
-- Tabla pivot: asignación de permisos a roles (M:M).
-- =============================================================================

CREATE TABLE IF NOT EXISTS szm_role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (role_id, permission_id),
    INDEX idx_rp_permission_id (permission_id),

    CONSTRAINT fk_rp_role
        FOREIGN KEY (role_id) REFERENCES szm_roles (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_rp_permission
        FOREIGN KEY (permission_id) REFERENCES szm_permissions (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Pivot: roles ↔ permisos';
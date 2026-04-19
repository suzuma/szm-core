-- =============================================================================
-- Migration 002 — szm_permissions
-- Permisos atómicos del sistema RBAC.
-- Convención de nombre: 'modulo.accion'  (ej. 'users.edit', 'reports.view')
-- =============================================================================

CREATE TABLE IF NOT EXISTS szm_permissions (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name       VARCHAR(100)     NOT NULL COMMENT 'Slug: modulo.accion',
    label      VARCHAR(150)     NOT NULL COMMENT 'Etiqueta legible',
    `group`    VARCHAR(50)      NOT NULL DEFAULT '' COMMENT 'Módulo agrupador: users, reports…',
    created_at DATETIME         NULL     DEFAULT NULL,
    updated_at DATETIME         NULL     DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE  KEY uk_permissions_name (name),
    INDEX   idx_permissions_group (`group`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Permisos atómicos del sistema RBAC';
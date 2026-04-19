-- =============================================================================
-- Migration 001 — szm_roles
-- Tabla de roles del sistema RBAC.
-- =============================================================================

CREATE TABLE IF NOT EXISTS szm_roles (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50)      NOT NULL COMMENT 'Slug único: admin, user, rh…',
    label      VARCHAR(100)     NOT NULL COMMENT 'Etiqueta legible: Administrador…',
    created_at DATETIME         NULL     DEFAULT NULL,
    updated_at DATETIME         NULL     DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE  KEY uk_roles_name (name)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Roles del sistema RBAC';
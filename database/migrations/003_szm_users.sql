-- =============================================================================
-- Migration 003 — szm_users
-- Usuarios del sistema — campos mínimos de autenticación y RBAC.
-- Los proyectos extienden esta tabla agregando columnas de perfil.
-- =============================================================================

CREATE TABLE IF NOT EXISTS szm_users (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    role_id              INT UNSIGNED  NOT NULL,

    -- Identificación
    name                 VARCHAR(150)  NOT NULL,
    email                VARCHAR(254)  NOT NULL,

    -- Seguridad
    password             VARCHAR(255)  NOT NULL                COMMENT 'bcrypt hash',
    active               TINYINT(1)    NOT NULL DEFAULT 1      COMMENT '1=activo 0=desactivado',
    failed_attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'Intentos fallidos de login',
    locked_until         DATETIME      NULL     DEFAULT NULL   COMMENT 'Bloqueado hasta esta fecha',

    -- Auditoría de sesión
    last_login_at        DATETIME      NULL     DEFAULT NULL,
    last_login_ip        VARCHAR(45)   NULL     DEFAULT NULL,

    -- Recuperación de contraseña
    reset_token          CHAR(64)      NULL     DEFAULT NULL   COMMENT 'SHA-256 hex del token en texto plano',
    reset_token_expires  DATETIME      NULL     DEFAULT NULL,

    created_at           DATETIME      NULL     DEFAULT NULL,
    updated_at           DATETIME      NULL     DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE  KEY uk_users_email   (email),
    INDEX   idx_users_role_id    (role_id),
    INDEX   idx_users_active     (active),
    INDEX   idx_users_reset_token (reset_token),

    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES szm_roles (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Usuarios del sistema — núcleo de autenticación';
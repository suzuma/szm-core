-- =============================================================================
-- Seed 001 — Roles del núcleo
-- Roles mínimos requeridos por el framework.
-- Los proyectos pueden agregar más roles según su dominio.
-- =============================================================================

INSERT IGNORE INTO szm_roles (name, label, created_at, updated_at) VALUES
    ('admin', 'Administrador',  NOW(), NOW()),
    ('user',  'Usuario',        NOW(), NOW());
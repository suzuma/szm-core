-- =============================================================================
-- Seed 002 — Permisos del núcleo
-- Permisos atómicos base. Los proyectos amplían este catálogo.
-- Convención: 'modulo.accion'
-- =============================================================================

INSERT IGNORE INTO szm_permissions (name, label, `group`, created_at, updated_at) VALUES
    -- Usuarios
    ('users.view',    'Ver usuarios',           'users', NOW(), NOW()),
    ('users.create',  'Crear usuarios',          'users', NOW(), NOW()),
    ('users.edit',    'Editar usuarios',          'users', NOW(), NOW()),
    ('users.delete',  'Eliminar usuarios',        'users', NOW(), NOW()),

    -- Roles y permisos
    ('roles.view',    'Ver roles',               'roles', NOW(), NOW()),
    ('roles.edit',    'Gestionar roles',          'roles', NOW(), NOW()),

    -- Auditoría
    ('audit.view',    'Ver log de auditoría',    'audit', NOW(), NOW()),

    -- Configuración del sistema
    ('settings.view', 'Ver configuración',    'settings', NOW(), NOW()),
    ('settings.edit', 'Editar configuración', 'settings', NOW(), NOW());


-- =============================================================================
-- Asignación de permisos al rol 'admin' (id = 1)
-- El rol admin tiene acceso a todos los permisos del núcleo.
-- =============================================================================

INSERT IGNORE INTO szm_role_permissions (role_id, permission_id)
SELECT
    (SELECT id FROM szm_roles WHERE name = 'admin'),
    id
FROM szm_permissions;
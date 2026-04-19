-- =============================================================================
-- Migration 007 — blog_categories
-- Categorías jerárquicas para el módulo Blog/Noticias.
-- Soporta un nivel de sub-categoría mediante parent_id.
-- =============================================================================

CREATE TABLE IF NOT EXISTS blog_categories (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,

    parent_id   INT UNSIGNED  NULL     DEFAULT NULL   COMMENT 'NULL = categoría raíz',
    name        VARCHAR(120)  NOT NULL,
    slug        VARCHAR(140)  NOT NULL                COMMENT 'URL-friendly único',
    description TEXT          NULL     DEFAULT NULL,
    sort_order  SMALLINT      NOT NULL DEFAULT 0      COMMENT 'Orden de aparición en menú',
    active      TINYINT(1)    NOT NULL DEFAULT 1,

    created_at  DATETIME      NULL     DEFAULT NULL,
    updated_at  DATETIME      NULL     DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE  KEY uk_blog_categories_slug   (slug),
    INDEX   idx_blog_categories_parent    (parent_id),
    INDEX   idx_blog_categories_active    (active),

    CONSTRAINT fk_blog_cat_parent
        FOREIGN KEY (parent_id) REFERENCES blog_categories (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Categorías del blog con soporte de jerarquía simple';
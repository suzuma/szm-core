-- =============================================================================
-- Migration 009 — blog_tags
-- Etiquetas planas (sin jerarquía) para clasificación transversal de posts.
-- =============================================================================

CREATE TABLE IF NOT EXISTS blog_tags (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,

    name        VARCHAR(80)   NOT NULL,
    slug        VARCHAR(100)  NOT NULL                COMMENT 'URL-friendly único',

    created_at  DATETIME      NULL     DEFAULT NULL,
    updated_at  DATETIME      NULL     DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE  KEY uk_blog_tags_slug (slug),
    INDEX   idx_blog_tags_name    (name)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Etiquetas del blog para clasificación cruzada de posts';
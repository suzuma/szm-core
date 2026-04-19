-- =============================================================================
-- Migration 008 — blog_posts
-- Artículos del blog. FK a szm_users (autor) y blog_categories.
-- status: draft | published | scheduled
-- published_at: NULL=borrador, pasado=publicado, futuro=programado
-- =============================================================================

CREATE TABLE IF NOT EXISTS blog_posts (
    id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Relaciones
    author_id         INT UNSIGNED    NOT NULL               COMMENT 'FK szm_users.id',
    category_id       INT UNSIGNED    NULL     DEFAULT NULL  COMMENT 'FK blog_categories.id',

    -- Contenido
    title             VARCHAR(255)    NOT NULL,
    slug              VARCHAR(280)    NOT NULL               COMMENT 'URL-friendly único',
    excerpt           VARCHAR(500)    NULL     DEFAULT NULL  COMMENT 'Resumen manual; si NULL se auto-genera',
    content           LONGTEXT        NOT NULL               COMMENT 'HTML del editor (TinyMCE)',

    -- Imagen destacada
    featured_image    VARCHAR(500)    NULL     DEFAULT NULL  COMMENT 'Ruta relativa desde /public',
    featured_image_alt VARCHAR(255)  NULL     DEFAULT NULL,

    -- Estado y publicación
    status            ENUM('draft','published','scheduled')
                                      NOT NULL DEFAULT 'draft',
    published_at      DATETIME        NULL     DEFAULT NULL  COMMENT 'NULL=borrador; futuro=programado',

    -- SEO
    meta_title        VARCHAR(70)     NULL     DEFAULT NULL  COMMENT 'Si NULL usa title',
    meta_description  VARCHAR(165)    NULL     DEFAULT NULL,

    -- Métricas
    views             INT UNSIGNED    NOT NULL DEFAULT 0,
    reading_time      TINYINT UNSIGNED NOT NULL DEFAULT 0   COMMENT 'Minutos estimados de lectura',

    created_at        DATETIME        NULL     DEFAULT NULL,
    updated_at        DATETIME        NULL     DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE  KEY uk_blog_posts_slug        (slug),
    INDEX   idx_blog_posts_author         (author_id),
    INDEX   idx_blog_posts_category       (category_id),
    INDEX   idx_blog_posts_status         (status),
    INDEX   idx_blog_posts_published_at   (published_at),

    CONSTRAINT fk_blog_posts_author
        FOREIGN KEY (author_id) REFERENCES szm_users (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_blog_posts_category
        FOREIGN KEY (category_id) REFERENCES blog_categories (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Artículos del blog — núcleo del módulo de contenido';
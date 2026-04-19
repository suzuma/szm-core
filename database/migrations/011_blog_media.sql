-- =============================================================================
-- Migration 011 — blog_media
-- Biblioteca de medios del blog. Polimórfica: un post puede tener
-- múltiples imágenes en distintas colecciones (featured, gallery, etc.).
-- =============================================================================

CREATE TABLE IF NOT EXISTS blog_media (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Propietario del archivo (polimórfico simple)
    post_id         INT UNSIGNED    NULL     DEFAULT NULL   COMMENT 'NULL = media huérfano (recién subido)',
    collection      VARCHAR(60)     NOT NULL DEFAULT 'default'
                                                            COMMENT 'featured | gallery | document',

    -- Archivo
    filename        VARCHAR(255)    NOT NULL               COMMENT 'Nombre almacenado en disco',
    original_name   VARCHAR(255)    NOT NULL               COMMENT 'Nombre original del archivo subido',
    path            VARCHAR(500)    NOT NULL               COMMENT 'Ruta relativa desde storage/uploads/',
    url             VARCHAR(500)    NOT NULL               COMMENT 'URL pública del recurso',
    mime_type       VARCHAR(100)    NOT NULL,
    size_bytes      INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Metadatos imagen
    width           SMALLINT UNSIGNED NULL  DEFAULT NULL,
    height          SMALLINT UNSIGNED NULL  DEFAULT NULL,
    alt_text        VARCHAR(255)    NULL     DEFAULT NULL,

    -- Auditoría
    uploaded_by     INT UNSIGNED    NULL     DEFAULT NULL   COMMENT 'FK szm_users.id',
    created_at      DATETIME        NULL     DEFAULT NULL,

    PRIMARY KEY (id),
    INDEX   idx_blog_media_post       (post_id),
    INDEX   idx_blog_media_collection (collection),
    INDEX   idx_blog_media_uploader   (uploaded_by),

    CONSTRAINT fk_blog_media_post
        FOREIGN KEY (post_id) REFERENCES blog_posts (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT fk_blog_media_uploader
        FOREIGN KEY (uploaded_by) REFERENCES szm_users (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Biblioteca de medios del blog — polimórfica por colección';
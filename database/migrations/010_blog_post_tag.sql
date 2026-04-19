-- =============================================================================
-- Migration 010 — blog_post_tag
-- Tabla pivot M:N entre blog_posts y blog_tags.
-- =============================================================================

CREATE TABLE IF NOT EXISTS blog_post_tag (
    post_id     INT UNSIGNED  NOT NULL,
    tag_id      INT UNSIGNED  NOT NULL,

    PRIMARY KEY (post_id, tag_id),
    INDEX   idx_post_tag_tag (tag_id),

    CONSTRAINT fk_post_tag_post
        FOREIGN KEY (post_id) REFERENCES blog_posts (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_post_tag_tag
        FOREIGN KEY (tag_id) REFERENCES blog_tags (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Pivot M:N posts ↔ tags';
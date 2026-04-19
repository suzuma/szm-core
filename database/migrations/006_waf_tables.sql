-- =============================================================================
-- Migration 006 — WAF Tables
-- Tablas del Web Application Firewall (Core\Security\Waf\Waf).
-- Prefijo: waf_*_szm
-- =============================================================================

-- -----------------------------------------------------------------------------
-- waf_blocked_ips_szm — IPs y fingerprints bloqueados
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS waf_blocked_ips_szm (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ip_address   VARCHAR(45)   NOT NULL,
    fingerprint  VARCHAR(64)   NULL     DEFAULT NULL  COMMENT 'Hash de navegador único',
    risk_score   SMALLINT      NOT NULL DEFAULT 0     COMMENT 'Puntuación acumulada (0-20+)',
    is_banned    TINYINT(1)    NOT NULL DEFAULT 0     COMMENT '1 = bloqueado actualmente',
    ban_until    DATETIME      NULL     DEFAULT NULL  COMMENT 'Fecha de liberación automática',

    -- Geolocalización (opcional, se completa si hay servicio GeoIP)
    city         VARCHAR(100)  NOT NULL DEFAULT 'Unknown',
    country      VARCHAR(100)  NOT NULL DEFAULT 'Unknown',
    isp          VARCHAR(150)  NOT NULL DEFAULT 'Unknown',

    -- Auditoría
    reason       TEXT          NULL     DEFAULT NULL  COMMENT 'Historial de razones de bloqueo',
    last_attempt DATETIME      NULL     DEFAULT NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_waf_blocked_ip          (ip_address),
    INDEX idx_waf_blocked_fingerprint (fingerprint),
    INDEX idx_waf_blocked_is_banned   (is_banned),
    INDEX idx_waf_blocked_ban_until   (ban_until)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='IPs y fingerprints bloqueados por el WAF';


-- -----------------------------------------------------------------------------
-- waf_attack_logs_szm — Log de ataques detectados
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS waf_attack_logs_szm (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ip_address     VARCHAR(45)   NOT NULL,
    fingerprint    VARCHAR(64)   NULL     DEFAULT NULL,
    user_agent     TEXT          NULL     DEFAULT NULL,
    rule_triggered VARCHAR(100)  NOT NULL COMMENT 'Regla que disparó el bloqueo',
    parameter      VARCHAR(100)  NULL     DEFAULT NULL COMMENT 'Parámetro GET/POST afectado',
    payload        TEXT          NULL     DEFAULT NULL COMMENT 'Valor sospechoso (truncado)',
    uri            VARCHAR(500)  NULL     DEFAULT NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_waf_attack_ip       (ip_address),
    INDEX idx_waf_attack_rule     (rule_triggered),
    INDEX idx_waf_attack_created  (created_at)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de ataques detectados por el WAF';


-- -----------------------------------------------------------------------------
-- waf_requests_szm — Registro de peticiones para rate limiting
-- Se purga automáticamente (WAF::maintenance())
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS waf_requests_szm (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45)  NOT NULL,
    uri        VARCHAR(500) NULL     DEFAULT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_waf_req_ip         (ip_address),
    INDEX idx_waf_req_created    (created_at)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Peticiones recientes para rate limiting del WAF';


-- -----------------------------------------------------------------------------
-- waf_cloud_ranges_szm — Rangos IP de proveedores cloud (AWS, GCP…)
-- Se actualiza con WAF::updateCloudRanges()
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS waf_cloud_ranges_szm (
    id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    provider  VARCHAR(50)   NOT NULL COMMENT 'aws | google | cloud',
    ip_range  VARCHAR(50)   NOT NULL COMMENT 'CIDR original: 3.5.140.0/22',
    ip_start  VARBINARY(16) NOT NULL COMMENT 'Límite inferior en binario (inet_pton)',
    ip_end    VARBINARY(16) NOT NULL COMMENT 'Límite superior en binario',

    PRIMARY KEY (id),
    INDEX idx_waf_cloud_range (ip_start, ip_end),
    INDEX idx_waf_cloud_provider (provider)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Rangos IP de proveedores cloud para detección de ataques';
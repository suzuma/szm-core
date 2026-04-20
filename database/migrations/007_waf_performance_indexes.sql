-- =============================================================================
-- Migración 007 — Índices de performance para tablas del WAF
-- =============================================================================
-- Propósito:
--   Optimizar las consultas más frecuentes y críticas del WAF bajo carga alta.
--   Sin estos índices, cada request que activa fetchBehaviorHistory() o
--   detectFuzzing() ejecuta un full scan sobre tablas que pueden crecer rápido.
--
-- Impacto esperado:
--   - fetchBehaviorHistory(): de O(n) a O(log n) con idx_requests_ip_created
--   - detectFuzzing():        de O(n) a O(log n) con idx_attack_logs_ip_rule_created
--   - checkIpStatus():        ya tiene INDEX(ip_address), no requiere cambios
--
-- Instrucciones:
--   Ejecutar una sola vez en producción. Seguro de re-ejecutar ().
-- =============================================================================

-- -----------------------------------------------------------------------------
-- waf_requests_szm
-- Tabla de rate limiting — consultada en CADA request del WAF.
--
-- Índice compuesto (ip_address, created_at):
--   Cubre el WHERE ip_address = ? AND created_at >= ? de fetchBehaviorHistory()
--   y el COUNT de rateLimit(). Sin él, MySQL escanea toda la tabla por ip_address
--   y luego filtra por fecha en memoria.
-- -----------------------------------------------------------------------------
ALTER TABLE waf_requests_szm
    ADD INDEX  idx_requests_ip_created (ip_address, created_at);

-- -----------------------------------------------------------------------------
-- waf_attack_logs_szm
-- Tabla de logs de ataque — consultada por detectFuzzing() cada request.
--
-- Índice compuesto (ip_address, rule_triggered, created_at):
--   Cubre el WHERE (ip_address = ? OR fingerprint = ?) AND created_at >= ?
--   de detectFuzzing(). El campo rule_triggered permite además filtrar por
--   tipo de ataque en consultas de auditoría sin full scan.
-- -----------------------------------------------------------------------------
ALTER TABLE waf_attack_logs_szm
    ADD INDEX  idx_attack_logs_ip_rule_created (ip_address, rule_triggered, created_at);

-- -----------------------------------------------------------------------------
-- waf_attack_logs_szm — índice por fingerprint
-- detectFuzzing() filtra también por fingerprint. Sin este índice, el OR
-- con fingerprint degrada el índice anterior a un full scan.
-- -----------------------------------------------------------------------------
ALTER TABLE waf_attack_logs_szm
    ADD INDEX  idx_attack_logs_fingerprint_created (fingerprint, created_at);
-- Migration 009 — Coordenadas geográficas en IPs bloqueadas
-- Añade latitud y longitud para poder pintar los ataques en un mapa.
-- Columnas nullable: registros previos quedan con NULL (se muestran sin pin).

ALTER TABLE waf_blocked_ips_szm
    ADD COLUMN latitude  DECIMAL(10, 7) NULL DEFAULT NULL AFTER isp,
    ADD COLUMN longitude DECIMAL(10, 7) NULL DEFAULT NULL AFTER latitude;
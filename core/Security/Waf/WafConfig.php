<?php

namespace Core\Security\Waf;

final class WafConfig
{
// =========================================================================
    // RATE LIMITING
    // =========================================================================

    /** Máximo de requests permitidos por IP en 60 segundos antes de bloquear */
    const RATE_LIMIT_PER_MINUTE = 60;

    /** Probabilidad (1-100) de ejecutar limpieza de registros antiguos en MySQL */
    const RATE_LIMIT_CLEANUP_PROBABILITY = 5;

    /** Minutos que se conservan registros de rate limit en MySQL */
    const RATE_LIMIT_RECORD_TTL_MINUTES = 5;

    // =========================================================================
    // PUNTAJES DE RIESGO
    // =========================================================================

    /** Puntaje acumulado que dispara el baneo automático */
    const BAN_THRESHOLD_SCORE = 20;

    /** Puntos sumados por un bloqueo inmediato (honeypot, herramienta conocida, etc.) */
    const IMMEDIATE_BAN_SCORE = 20;

    /** Puntos sumados por un evento sospechoso normal */
    const NORMAL_BLOCK_SCORE = 5;

    // =========================================================================
    // DURACIÓN DE BANEOS
    // =========================================================================

    /** Horas que dura un baneo automático */
    const BAN_DURATION_HOURS = 24;

    /** Días que se conservan logs de ataques antes de limpiarlos */
    const ATTACK_LOG_RETENTION_DAYS = 30;

    /** Horas que se conservan registros de requests en MySQL */
    const REQUEST_LOG_RETENTION_HOURS = 1;

    // =========================================================================
    // DETECCIÓN DE COMPORTAMIENTO NO HUMANO
    // =========================================================================

    /** Requests en 5 segundos que indican velocidad no humana */
    const NON_HUMAN_SPEED_THRESHOLD = 15;

    /** Ventana de tiempo (segundos) para detectar velocidad no humana */
    const NON_HUMAN_SPEED_WINDOW_SECONDS = 5;

    /** Puntos sumados al detectar velocidad no humana */
    const NON_HUMAN_SPEED_RISK_SCORE = 6;

    // =========================================================================
    // DETECCIÓN DE FUZZING
    // =========================================================================

    /** Alertas de seguridad en la ventana de tiempo que indican fuzzing activo */
    const FUZZING_ALERT_THRESHOLD = 10;

    /** Ventana de tiempo (minutos) para detectar fuzzing */
    const FUZZING_WINDOW_MINUTES = 2;

    /** Puntos sumados al detectar fuzzing */
    const FUZZING_RISK_SCORE = 10;

    // =========================================================================
    // DETECCIÓN DE NAVEGACIÓN SOSPECHOSA
    // =========================================================================

    /** Matches de patrones sensibles en ventana que indican escaneo secuencial */
    const SUSPICIOUS_NAV_MATCH_THRESHOLD = 3;

    /** Requests en ventana analizados para navegación sospechosa */
    const SUSPICIOUS_NAV_HISTORY_LIMIT = 10;

    /** Ventana de tiempo (segundos) para analizar navegación sospechosa */
    const SUSPICIOUS_NAV_WINDOW_SECONDS = 20;

    /** Puntos sumados al detectar navegación sospechosa */
    const SUSPICIOUS_NAV_RISK_SCORE = 8;

    // =========================================================================
    // DETECCIÓN DE COMPORTAMIENTO IA
    // =========================================================================

    /** Requests en 10 segundos que indican comportamiento de bot IA */
    const AI_BEHAVIOR_SPEED_THRESHOLD = 10;

    /** Ventana de tiempo (segundos) para detectar velocidad de bot IA */
    const AI_BEHAVIOR_SPEED_WINDOW_SECONDS = 10;

    /** Puntos sumados al detectar velocidad de bot IA */
    const AI_BEHAVIOR_SPEED_RISK_SCORE = 8;

    /** Accesos a endpoints críticos que confirman comportamiento de bot IA */
    const AI_BEHAVIOR_CRITICAL_ENDPOINT_THRESHOLD = 3;

    /** Ventana de tiempo (segundos) para analizar endpoints críticos */
    const AI_BEHAVIOR_ENDPOINT_WINDOW_SECONDS = 30;

    /** Puntos sumados al detectar múltiples endpoints críticos */
    const AI_BEHAVIOR_ENDPOINT_RISK_SCORE = 10;

    // =========================================================================
    // DETECCIÓN DE INFERENCE JUMP
    // =========================================================================

    /** Mínimo de requests en historial para poder inferir un patrón */
    const INFERENCE_HISTORY_MIN_REQUESTS = 3;

    /** Ventana de tiempo (segundos) para analizar historial de inferencia */
    const INFERENCE_HISTORY_WINDOW_SECONDS = 30;

    /** Requests en 10 segundos considerados velocidad alta en inference */
    const INFERENCE_HIGH_SPEED_THRESHOLD = 8;

    /** Endpoints API distintos que indican exploración automatizada */
    const INFERENCE_UNIQUE_ENDPOINTS_THRESHOLD = 4;

    // =========================================================================
    // DETECCIÓN DE PAYLOADS IA
    // =========================================================================

    /** Longitud mínima de payload para análisis (evita consumo innecesario de CPU) */
    const AI_PAYLOAD_MIN_LENGTH = 10;

    /** Longitud mínima para considerar SQL generado por IA (estructuras complejas) */
    const AI_PAYLOAD_SQL_MIN_LENGTH = 250;

    /** Puntos sumados al detectar prompt injection */
    const AI_PROMPT_INJECTION_RISK_SCORE = 15;

    // =========================================================================
    // CACHE Y RENDIMIENTO
    // =========================================================================

    /** TTL en segundos para cache de geodatos en Redis */
    const GEO_CACHE_TTL_SECONDS = 86400; // 24 horas

    /** Timeout en segundos para consultas a ip-api.com */
    const GEO_API_TIMEOUT_SECONDS = 2;

    /** TTL en segundos para bloqueos rápidos en Redis */
    const REDIS_RATE_LIMIT_TTL_SECONDS = 60;

    /** Máximo de bytes a procesar en normalize() para evitar DoS por CPU */
    const NORMALIZE_MAX_INPUT_BYTES = 10240; // 10KB
}
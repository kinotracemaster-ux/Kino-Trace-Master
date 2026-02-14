<?php
/**
 * helpers/subdomain.php
 *
 * Detects the client code from the subdomain when the app is
 * accessed via *.kino-trace.com.
 *
 * Usage:
 *   require_once __DIR__ . '/helpers/subdomain.php';
 *   $sub = getSubdomain();          // e.g. "losmonte"
 *   $code = resolveClientCode($sub); // validated against DB
 */

/**
 * Base domain for subdomain detection.
 * Change this if you move to a different domain.
 */
define('APP_BASE_DOMAIN', getenv('APP_BASE_DOMAIN') ?: 'kino-trace.com');

/**
 * Extract the subdomain portion from the current HTTP_HOST.
 *
 * Returns null when:
 *  - Running on localhost / Railway default URL
 *  - The host IS the base domain (no subdomain)
 *  - The subdomain is "www"
 *
 * @return string|null  Lowercase subdomain or null
 */
function getSubdomain(): ?string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // Strip port if present
    $host = strtolower(explode(':', $host)[0]);

    $base = strtolower(APP_BASE_DOMAIN);

    // Must end with .basedomain  (e.g. "losmonte.kino-trace.com")
    if (!str_ends_with($host, '.' . $base)) {
        return null;
    }

    // Extract the part before .basedomain
    $sub = substr($host, 0, strlen($host) - strlen('.' . $base));

    // Ignore empty, "www", or anything with dots (multi-level sub)
    if ($sub === '' || $sub === 'www' || str_contains($sub, '.')) {
        return null;
    }

    return $sub;
}

/**
 * Resolve a subdomain to a client code.
 *
 * First checks the `subdominio` column in control_clientes,
 * then falls back to matching by `codigo`.
 *
 * @param  string|null $subdomain
 * @return string|null  Client code or null if not found
 */
function resolveClientCode(?string $subdomain): ?string
{
    if ($subdomain === null || $subdomain === '') {
        return null;
    }

    global $centralDb;
    if (!isset($centralDb)) {
        return null;
    }

    // 1) Try matching subdominio column
    try {
        $stmt = $centralDb->prepare(
            'SELECT codigo FROM control_clientes WHERE subdominio = ? AND activo = 1 LIMIT 1'
        );
        $stmt->execute([$subdomain]);
        $code = $stmt->fetchColumn();
        if ($code) {
            return $code;
        }
    } catch (PDOException $e) {
        // Column may not exist yet — fall through
    }

    // 2) Fallback: subdomain === codigo
    $stmt = $centralDb->prepare(
        'SELECT codigo FROM control_clientes WHERE codigo = ? AND activo = 1 LIMIT 1'
    );
    $stmt->execute([$subdomain]);
    $code = $stmt->fetchColumn();

    return $code ?: null;
}

/**
 * Convenience: detect subdomain and resolve to client code in one call.
 *
 * @return string|null  Client code or null
 */
function getClientFromSubdomain(): ?string
{
    return resolveClientCode(getSubdomain());
}

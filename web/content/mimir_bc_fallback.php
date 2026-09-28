<?php

/**
 * Mímir-BC-fallback.
 *
 * odata.php laadt dit bestand als enige goedgekeurde hook (Tim Falken, 2026-09-28).
 * Faalt Mímir, dan gaat dezelfde read naar de pre-Mímir BC-route: $baseUrl,
 * $auth_list[environment van het gevraagde bedrijf], $environment en de filecache.
 * Na de eerste fout in dit PHP-proces wordt Mímir overgeslagen.
 */

function &odata_mimir_circuit_state(): array
{
    static $state = [
        'open' => false,
        'error' => null,
    ];
    return $state;
}

function odata_mimir_circuit_open(): bool
{
    $state = &odata_mimir_circuit_state();
    return $state['open'] === true;
}

function odata_mimir_last_error(): ?Throwable
{
    $state = &odata_mimir_circuit_state();
    return $state['error'] instanceof Throwable ? $state['error'] : null;
}

function odata_mimir_trip(Throwable $exception): void
{
    $state = &odata_mimir_circuit_state();
    if ($state['open'] === true) {
        return;
    }
    $state['open'] = true;
    $state['error'] = $exception;
}

function odata_mimir_circuit_reset(): void
{
    $state = &odata_mimir_circuit_state();
    $state['open'] = false;
    $state['error'] = null;
}

function odata_mimir_connect_timeout_seconds(): int
{
    return 10;
}

function odata_mimir_timeout_seconds_for_sapi(string $sapi): int
{
    return strtolower($sapi) === 'cli' ? 600 : 90;
}

function odata_mimir_timeout_seconds(): int
{
    return odata_mimir_timeout_seconds_for_sapi(PHP_SAPI);
}

function odata_mimir_fail(Exception $exception): void
{
    odata_mimir_trip($exception);
    throw $exception;
}

function odata_bc_rethrow_mimir(): void
{
    $previous = odata_mimir_last_error();
    if ($previous instanceof Throwable) {
        throw $previous;
    }
    throw new Exception('Mímir mislukt.');
}

function odata_auth_is_usable($auth): bool
{
    if (!is_array($auth)) {
        return false;
    }
    $user = trim((string) ($auth['user'] ?? ''));
    if ($user === '') {
        return false;
    }
    $mode = (string) ($auth['mode'] ?? '');
    if ($mode !== 'basic' && $mode !== 'ntlm') {
        return false;
    }
    return array_key_exists('pass', $auth);
}

/**
 * require van auth.php binnen een functie maakt BC-variabelen lokaal.
 * Kopieer ze naar $GLOBALS zodat de fallback ze daarna ziet.
 */
function talos_import_web_auth_php(?string $path = null): void
{
    if ($path === null) {
        $path = dirname(__DIR__) . '/auth.php';
    }
    if (!is_file($path)) {
        return;
    }
    $real = realpath($path);
    if ($real !== false) {
        $included = get_included_files();
        foreach ($included as $file) {
            $includedReal = realpath($file);
            if ($includedReal !== false && $includedReal === $real) {
                return;
            }
        }
    }

    $import = function (string $authPath): array {
        require $authPath;
        $names = ['baseUrl', 'environment', 'auth', 'auth_list', 'mimirApi', 'mimirBase'];
        $out = [];
        foreach ($names as $name) {
            if (isset($$name)) {
                $out[$name] = $$name;
            }
        }
        return $out;
    };
    $loaded = $import($path);
    foreach ($loaded as $name => $value) {
        $GLOBALS[$name] = $value;
    }
}

function odata_bc_base_url(): ?string
{
    global $baseUrl;
    if (!isset($baseUrl) || !is_string($baseUrl)) {
        return null;
    }
    $base = trim($baseUrl);
    if ($base === '' || stripos($base, 'mimir.invalid') !== false) {
        return null;
    }
    return $base;
}

/**
 * @return list<string>
 */
function odata_bc_environment_list(): array
{
    global $environment, $auth_list;
    $list = [];
    if (isset($environment)) {
        $raw = is_array($environment) ? $environment : [$environment];
        foreach ($raw as $item) {
            $env = trim((string) $item);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            $list[] = $env;
        }
    }
    if ($list === [] && isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $name => $entry) {
            if (!is_string($name) || $name === '' || strcasecmp($name, 'mimir') === 0) {
                continue;
            }
            if (!odata_auth_is_usable($entry)) {
                continue;
            }
            $list[] = $name;
        }
    }
    return array_values(array_unique($list));
}

function odata_bc_environment(): ?string
{
    $list = odata_bc_environment_list();
    return $list[0] ?? null;
}

function odata_bc_environment_from_url(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('#(?:^|/)([^/]+)/ODataV4(?:/|$)#i', $path, $match) !== 1) {
        return null;
    }
    $env = trim(rawurldecode($match[1]));
    if ($env === '' || strcasecmp($env, 'mimir') === 0) {
        return null;
    }
    return $env;
}

function odata_bc_remember_company_environment(string $company, string $env): void
{
    $company = trim($company);
    $env = trim($env);
    if ($company === '' || $env === '' || strcasecmp($env, 'mimir') === 0) {
        return;
    }
    if (!isset($GLOBALS['talos_bc_company_environments']) || !is_array($GLOBALS['talos_bc_company_environments'])) {
        $GLOBALS['talos_bc_company_environments'] = [];
    }
    $GLOBALS['talos_bc_company_environments'][$company] = $env;
}

function odata_bc_environment_for_company(string $company): ?string
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    $map = $GLOBALS['talos_bc_company_environments'] ?? null;
    if (!is_array($map) || !isset($map[$company])) {
        return null;
    }
    $env = trim((string) $map[$company]);
    if ($env === '' || strcasecmp($env, 'mimir') === 0) {
        return null;
    }
    return $env;
}

function odata_bc_auth_for_named_environment(string $env): ?array
{
    global $auth_list, $auth;
    if (isset($auth_list) && is_array($auth_list) && isset($auth_list[$env]) && odata_auth_is_usable($auth_list[$env])) {
        return $auth_list[$env];
    }
    $list = odata_bc_environment_list();
    if (count($list) === 1 && strcasecmp($list[0], $env) === 0 && isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    return null;
}

function odata_bc_auth_for_fallback(array $passed): ?array
{
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    global $auth, $auth_list;
    if (isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    if (!isset($auth_list) || !is_array($auth_list)) {
        return null;
    }
    foreach (odata_bc_environment_list() as $envName) {
        if (isset($auth_list[$envName]) && odata_auth_is_usable($auth_list[$envName])) {
            return $auth_list[$envName];
        }
    }
    foreach ($auth_list as $entry) {
        if (odata_auth_is_usable($entry)) {
            return $entry;
        }
    }
    return null;
}

/**
 * Auth voor het environment in de URL of van het bedrijf. Niet de primaire
 * $auth als die bij een ander environment hoort.
 */
function odata_bc_auth_for_request(string $url, string $company = ''): ?array
{
    $env = odata_bc_environment_from_url($url);
    if ($env === null && $company !== '') {
        $env = odata_bc_environment_for_company($company);
    }
    if ($env !== null) {
        return odata_bc_auth_for_named_environment($env);
    }
    return odata_bc_auth_for_fallback([]);
}

function odata_bc_credentials_configured(): bool
{
    if (odata_bc_base_url() === null || odata_bc_environment_list() === []) {
        return false;
    }
    return odata_bc_auth_for_fallback([]) !== null;
}

function odata_mimir_log_fallback(Throwable $exception): void
{
    $message = $exception->getMessage();
    $redactions = [];
    $apiKey = function_exists('odata_mimir_api_key') ? odata_mimir_api_key() : '';
    if ($apiKey !== '') {
        $redactions[] = $apiKey;
    }
    global $auth, $auth_list;
    if (isset($auth) && is_array($auth) && isset($auth['pass']) && is_string($auth['pass']) && $auth['pass'] !== '') {
        $redactions[] = $auth['pass'];
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (is_array($entry) && isset($entry['pass']) && is_string($entry['pass']) && $entry['pass'] !== '') {
                $redactions[] = $entry['pass'];
            }
        }
    }
    foreach ($redactions as $secret) {
        $message = str_replace($secret, '[redacted]', $message);
    }
    $sanitized = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);
    if (is_string($sanitized)) {
        $message = $sanitized;
    }
    error_log('[Talos] Mímir failed, falling back to direct OData: ' . $message);
}

/**
 * @template T
 * @param callable(): T $viaMimir
 * @param callable(): T $viaDirect
 * @return T
 */
function odata_mimir_or_direct(callable $viaMimir, callable $viaDirect)
{
    if (odata_mimir_circuit_open()) {
        $original = odata_mimir_last_error();
        if (!odata_bc_credentials_configured()) {
            if ($original instanceof Throwable) {
                throw $original;
            }
            throw new Exception('Mímir eerder mislukt.');
        }
        odata_mimir_log_fallback($original instanceof Throwable ? $original : new Exception('Mímir overgeslagen na eerdere fout.'));
        return $viaDirect();
    }

    try {
        return $viaMimir();
    } catch (Throwable $exception) {
        odata_mimir_trip($exception);
        if (!odata_bc_credentials_configured()) {
            throw $exception;
        }
        odata_mimir_log_fallback($exception);
        return $viaDirect();
    }
}

function odata_bc_url_from_odata_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $base = odata_bc_base_url();
    if ($base === null) {
        return $url;
    }
    $base = rtrim($base, '/') . '/';
    $query = (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '')
        ? ('?' . $parts['query'])
        : '';

    if ($host === 'mimir.invalid') {
        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#^/([^/]+)(/.+)$#', $path, $match) !== 1) {
            return $url;
        }
        $env = trim(rawurldecode($match[1]));
        if ($env === '' || strcasecmp($env, 'mimir') === 0) {
            $company = '';
            if (function_exists('odata_mimir_parse_entity_url')) {
                $parsed = odata_mimir_parse_entity_url($url);
                if (is_array($parsed)) {
                    $company = (string) ($parsed['company'] ?? '');
                }
            }
            $resolved = odata_bc_environment_for_company($company);
            if ($resolved === null) {
                $resolved = odata_bc_environment();
            }
            if ($resolved === null) {
                return $url;
            }
            $env = $resolved;
        }
        return $base . $env . $match[2] . $query;
    }

    if ($host === '' && isset($parts['path'])) {
        $path = ltrim((string) $parts['path'], '/');
        if ($path === '') {
            return $url;
        }
        return $base . $path . $query;
    }

    return $url;
}

function talos_mimir_bc_request(string $method, string $path, ?array $jsonBody = null): array
{
    $apiKey = odata_mimir_api_key();
    if ($apiKey === '') {
        throw new Exception('Mímir API-sleutel ontbreekt ($mimirApi).');
    }

    if (odata_mimir_circuit_open()) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir overgeslagen na eerdere fout in dit verzoek.');
    }

    $url = odata_mimir_base_url() . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
        'X-API-Key: ' . $apiKey,
    ];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => odata_mimir_connect_timeout_seconds(),
        CURLOPT_TIMEOUT => odata_mimir_timeout_seconds(),
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Talos-MimirClient/1.0',
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            curl_close($ch);
            throw new Exception('Mímir request JSON encode mislukt.');
        }
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        odata_mimir_fail(new Exception('Mímir cURL error: ' . $err));
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : $raw;
        odata_mimir_fail(new Exception('Mímir HTTP ' . $code . ': ' . $message));
    }
    if (!is_array($decoded)) {
        odata_mimir_fail(new Exception('Mímir gaf ongeldige JSON terug.'));
    }
    $errorField = $decoded['error'] ?? null;
    if ($errorField !== null && $errorField !== '' && $errorField !== false) {
        $message = is_string($errorField) ? $errorField : (string) json_encode($errorField, JSON_UNESCAPED_UNICODE);
        odata_mimir_fail(new Exception('Mímir error: ' . $message));
    }
    return $decoded;
}

/**
 * @return list<array<string, mixed>>
 */
function talos_mimir_bc_companies_impl(?string $environment = null): array
{
    $response = odata_mimir_request('GET', 'companies.php');
    $items = $response['value'] ?? null;
    if (!is_array($items)) {
        throw new Exception("Mímir companies-antwoord mist 'value'.");
    }
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? $item['Name'] ?? ''));
        $env = trim((string) ($item['environment'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($environment !== null && $environment !== '' && $env !== '' && strcasecmp($env, $environment) !== 0) {
            continue;
        }
        odata_bc_remember_company_environment($name, $env);
        $rows[] = ['Name' => $name, 'environment' => $env];
    }
    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_direct_companies_as_rows(?string $environmentFilter = null): array
{
    $envs = odata_bc_environment_list();
    if ($environmentFilter !== null && trim($environmentFilter) !== '' && strcasecmp(trim($environmentFilter), 'mimir') !== 0) {
        $envs = [trim($environmentFilter)];
    }
    $base = odata_bc_base_url();
    if ($base === null || $envs === []) {
        odata_bc_rethrow_mimir();
    }
    $base = rtrim((string) $base, '/') . '/';

    $out = [];
    foreach ($envs as $env) {
        $auth = odata_bc_auth_for_named_environment($env);
        if ($auth === null) {
            odata_bc_rethrow_mimir();
        }

        $companyUrl = $base . $env . '/ODataV4/Company?$select=Name,Display_Name';
        try {
            $rows = odata_get_all_direct($companyUrl, $auth, 300);
        } catch (Exception $ignored) {
            $rows = odata_get_all_direct($base . $env . '/ODataV4/Companies?$select=Name,Display_Name', $auth, 300);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            odata_bc_remember_company_environment($name, $env);
            $out[] = ['Name' => $name, 'environment' => $env];
        }
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function talos_mimir_bc_companies_as_rows(?string $environment = null): array
{
    $fromMimir = static function () use ($environment): array {
        return talos_mimir_bc_companies_impl($environment);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($environment): array {
            return odata_direct_companies_as_rows($environment);
        }
    );
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function talos_mimir_bc_query_impl(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    consolelog("Mímir query company=$company table=$table\n");

    $body = [
        'company' => $company,
        'table' => $table,
        'max_age' => max(0, $ttlSeconds),
        'top' => 0,
    ];

    $select = trim((string) ($odataQuery['$select'] ?? $odataQuery['select'] ?? ''));
    if ($select !== '') {
        $cols = [];
        foreach (explode(',', $select) as $col) {
            $col = trim($col);
            if ($col !== '') {
                $cols[] = $col;
            }
        }
        if ($cols !== []) {
            $body['select'] = $cols;
        }
    }

    $filter = trim((string) ($odataQuery['$filter'] ?? $odataQuery['filter'] ?? ''));
    if ($filter !== '') {
        $body['filter'] = $filter;
    }

    $response = odata_mimir_request('POST', 'query.php', $body);
    if (!isset($response['value']) || !is_array($response['value'])) {
        throw new Exception("Mímir query-antwoord mist 'value'.");
    }
    /** @var list<array<string, mixed>> $value */
    $value = $response['value'];
    return $value;
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_direct_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    $env = odata_bc_environment_for_company($company);
    $mapped = $env !== null;
    if ($env === null) {
        $env = odata_bc_environment();
    }
    $base = odata_bc_base_url();
    $auth = $env !== null ? odata_bc_auth_for_named_environment($env) : null;
    if ($auth === null && !$mapped) {
        $auth = odata_bc_auth_for_fallback([]);
    }
    if ($env === null || $base === null || $auth === null) {
        odata_bc_rethrow_mimir();
    }

    $params = [];
    foreach (['$select', '$filter', '$orderby', '$expand', '$top', '$skip', 'select', 'filter'] as $key) {
        if (!array_key_exists($key, $odataQuery)) {
            continue;
        }
        $value = trim((string) $odataQuery[$key]);
        if ($value === '') {
            continue;
        }
        $odataKey = ($key === 'select' || $key === 'filter') ? ('$' . $key) : $key;
        $params[$odataKey] = $value;
    }

    $url = rtrim((string) $base, '/') . '/' . $env . "/ODataV4/Company('" . rawurlencode($company) . "')/" . $table;
    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function talos_mimir_bc_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    $fromMimir = static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
        return talos_mimir_bc_query_impl($company, $table, $odataQuery, $ttlSeconds);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
            return odata_direct_query($company, $table, $odataQuery, $ttlSeconds);
        }
    );
}

/**
 * @return list<array<string, mixed>>
 */
function talos_mimir_bc_fetch_impl(string $url, int $ttlSeconds): array
{
    consolelog("Mímir fetch $url\n");

    $companies = odata_mimir_parse_companies_url($url);
    if ($companies !== null) {
        return talos_mimir_bc_companies_impl($companies['environment']);
    }

    $parsed = odata_mimir_parse_entity_url($url);
    if ($parsed === null) {
        throw new Exception('Mímir: OData-URL kon niet worden vertaald naar company/table: ' . $url);
    }

    return talos_mimir_bc_query_impl($parsed['company'], $parsed['entity'], $parsed['query'], $ttlSeconds);
}

/**
 * @return list<array<string, mixed>>
 */
function talos_mimir_bc_fetch_all(string $url, int $ttlSeconds): array
{
    $fromMimir = static function () use ($url, $ttlSeconds): array {
        return talos_mimir_bc_fetch_impl($url, $ttlSeconds);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($url, $ttlSeconds): array {
            $directUrl = odata_bc_url_from_odata_url($url);
            $auth = odata_bc_auth_for_request($directUrl);
            if ($auth === null) {
                odata_bc_rethrow_mimir();
            }
            return odata_get_all_direct($directUrl, $auth, $ttlSeconds);
        }
    );
}

function talos_mimir_bc_get_all(string $url, array $auth, $ttlSeconds = 300): array
{
    consolelog("Fetching $url\n");
    $ttlSeconds = max(0, (int) $ttlSeconds);

    if (odata_mimir_enabled()) {
        return odata_mimir_or_direct(
            static function () use ($url, $ttlSeconds): array {
                return talos_mimir_bc_fetch_impl($url, $ttlSeconds === 0 ? 3600 : $ttlSeconds);
            },
            static function () use ($url, $auth, $ttlSeconds): array {
                $directUrl = odata_bc_url_from_odata_url($url);
                $directAuth = odata_bc_auth_for_request($directUrl);
                if ($directAuth === null && odata_bc_environment_from_url($directUrl) === null) {
                    $directAuth = odata_bc_auth_for_fallback($auth);
                }
                if ($directAuth === null) {
                    odata_bc_rethrow_mimir();
                }
                return odata_get_all_direct($directUrl, $directAuth, $ttlSeconds);
            }
        );
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

function odata_get_all_direct(string $url, array $auth, $ttlSeconds = 300): array
{
    $ttlSeconds = max(1, (int) $ttlSeconds);
    if (isset($GLOBALS['TALOS_ODATA_BC_FETCH']) && is_callable($GLOBALS['TALOS_ODATA_BC_FETCH'])) {
        return $GLOBALS['TALOS_ODATA_BC_FETCH']($url, $auth, $ttlSeconds);
    }

    maybe_cleanup_expired_cache_files();

    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);

    if (is_file($cachePath)) {
        consolelog("Found in cache.\n");
        $cached = read_cache_payload($cachePath, $ttlSeconds);
        if ($cached['valid']) {
            consolelog("Returning data.\n");
            return $cached['data'];
        }

        if ($cached['delete']) {
            consolelog("Cache expired.\n");
            @unlink($cachePath);
        }
    }

    $all = [];
    $next = $url;

    while ($next) {
        $resp = odata_get_json($next, $auth);

        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new Exception("OData response missing 'value' array");
        }

        $all = array_merge($all, $resp['value']);
        $next = $resp['@odata.nextLink'] ?? null;
        consolelog("Reading next chunk...\n");
    }

    consolelog("Fetched. Now caching...\n");
    write_cache_json($cachePath, $all, $ttlSeconds, $url);
    consolelog("Done, returning data.\n");
    return $all;
}

function talos_mimir_bc_cache_key(string $url, array $auth): string
{
    $circuitOpen = function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open();
    if (!function_exists('odata_mimir_enabled') || !odata_mimir_enabled() || $circuitOpen) {
        talos_import_web_auth_php();
    }

    $user = (string) ($auth['user'] ?? '');
    if ($circuitOpen) {
        $env = odata_bc_environment_from_url($url);
        if ($env === null && function_exists('odata_mimir_parse_entity_url')) {
            $parsed = odata_mimir_parse_entity_url($url);
            if (is_array($parsed)) {
                $env = odata_bc_environment_for_company((string) ($parsed['company'] ?? ''));
            }
        }
        if ($env === null) {
            $env = implode(',', odata_bc_environment_list());
        }
        return $url . '|' . $user . '|' . $env;
    }

    $environmentKey = '';
    if (function_exists('getActiveEnvironments')) {
        $environmentKey = implode(',', getActiveEnvironments());
    } else {
        global $environment;
        if (is_array($environment)) {
            $environmentKey = implode(',', array_map('strval', $environment));
        } else {
            $environmentKey = (string) $environment;
        }
    }
    if (strcasecmp($environmentKey, 'mimir') === 0) {
        $environmentKey = implode(',', odata_bc_environment_list());
    }

    return $url . '|' . $user . '|' . $environmentKey;
}

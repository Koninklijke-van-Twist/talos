<?php

if (!function_exists('talosNormalizeEnvironmentList')) {
    function talosNormalizeEnvironmentList($environmentValue): array
    {
        if (is_array($environmentValue)) {
            $list = array_values(array_filter(array_map('strval', $environmentValue), static function (string $item): bool {
                return trim($item) !== '';
            }));
            return array_values(array_unique($list));
        }

        $single = trim((string) $environmentValue);
        return $single === '' ? [] : [$single];
    }
}

if (!function_exists('talosMimirEnabled')) {
    /**
     * Mímir is actief zodra $mimirApi in auth.php gezet is.
     */
    function talosMimirEnabled(): bool
    {
        global $mimirApi;
        if (isset($mimirApi) && is_string($mimirApi) && trim($mimirApi) !== '') {
            return true;
        }

        return function_exists('odata_mimir_enabled') && odata_mimir_enabled();
    }
}

if (!function_exists('talosEnsureOdataLoaded')) {
    function talosEnsureOdataLoaded(): void
    {
        if (function_exists('odata_mimir_companies_as_rows')) {
            return;
        }

        $odataPath = __DIR__ . '/odata.php';
        if (is_file($odataPath)) {
            require_once $odataPath;
        }
    }
}

if (!function_exists('getActiveEnvironments')) {
    function getActiveEnvironments(): array
    {
        global $environment;

        $resolved = talosNormalizeEnvironmentList($environment ?? []);
        if (!empty($resolved)) {
            return $resolved;
        }

        // Geen lokale BC-config: bij Mímir environments afleiden uit companies.php.
        if (talosMimirEnabled()) {
            $cached = $GLOBALS['talos_mimir_active_environments'] ?? null;
            if (is_array($cached) && $cached !== []) {
                return array_values(array_map('strval', $cached));
            }

            try {
                talosEnsureOdataLoaded();
                if (!function_exists('odata_mimir_companies_as_rows')) {
                    return [];
                }

                $rows = odata_mimir_companies_as_rows(null);
                $envs = [];
                $seen = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $env = trim((string) ($row['environment'] ?? ''));
                    if ($env === '' || isset($seen[$env])) {
                        continue;
                    }
                    $seen[$env] = true;
                    $envs[] = $env;
                }
                sort($envs, SORT_NATURAL | SORT_FLAG_CASE);
                $GLOBALS['talos_mimir_active_environments'] = $envs;
                return $envs;
            } catch (Throwable $ignored) {
                return [];
            }
        }

        return ['kvtmdlive_aad'];
    }
}

if (!function_exists('getPrimaryEnvironment')) {
    function getPrimaryEnvironment(): string
    {
        $environments = getActiveEnvironments();
        return (string) ($environments[0] ?? '');
    }
}

if (!function_exists('getAuthForEnvironment')) {
    function getAuthForEnvironment(string $environmentName): array
    {
        global $auth_list;

        if (!isset($auth_list[$environmentName]) || !is_array($auth_list[$environmentName])) {
            // Mímir-modus zonder BC-auth: leftover callers krijgen lege auth i.p.v. exception.
            if (talosMimirEnabled()) {
                return [];
            }
            throw new InvalidArgumentException('Unknown environment: ' . $environmentName);
        }

        return $auth_list[$environmentName];
    }
}

if (!function_exists('setCompanyEnvironmentMap')) {
    function setCompanyEnvironmentMap(array $map): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $normalized = [];
        foreach ($map as $companyName => $environmentName) {
            $company = trim((string) $companyName);
            $environment = trim((string) $environmentName);
            if ($company === '' || $environment === '') {
                continue;
            }

            $normalized[$company] = $environment;
        }

        $_SESSION['company_environment_map'] = $normalized;
    }
}

if (!function_exists('getEnvironmentForCompany')) {
    function getEnvironmentForCompany(string $company): ?string
    {
        $companyName = trim($company);
        if ($companyName === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $map = $_SESSION['company_environment_map'] ?? [];
        if (!is_array($map)) {
            return null;
        }

        $environmentName = (string) ($map[$companyName] ?? '');
        return $environmentName === '' ? null : $environmentName;
    }
}

// Zonder Mímir blijft ontbrekende BC-auth een harde fout. Met Mímir is $auth een lege sentinel.
if (talosMimirEnabled()) {
    try {
        $primaryEnvironment = getPrimaryEnvironment();
        $auth = $primaryEnvironment === '' ? [] : getAuthForEnvironment($primaryEnvironment);
    } catch (InvalidArgumentException $ignored) {
        $auth = [];
    }
} else {
    $auth = getAuthForEnvironment(getPrimaryEnvironment());
}

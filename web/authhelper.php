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

if (!function_exists('getActiveEnvironments')) {
    function getActiveEnvironments(): array
    {
        global $environment;

        $resolved = talosNormalizeEnvironmentList($environment ?? []);
        if (!empty($resolved)) {
            return $resolved;
        }

        return ['kvtmdlive_aad'];
    }
}

if (!function_exists('getPrimaryEnvironment')) {
    function getPrimaryEnvironment(): string
    {
        $environments = getActiveEnvironments();
        return (string) $environments[0];
    }
}

if (!function_exists('getAuthForEnvironment')) {
    function getAuthForEnvironment(string $environmentName): array
    {
        global $auth_list;

        if (!isset($auth_list[$environmentName]) || !is_array($auth_list[$environmentName])) {
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

$auth = getAuthForEnvironment(getPrimaryEnvironment());

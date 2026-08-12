<!DOCTYPE html>
<html lang="<?= h(getCurrentLanguage()) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(LOC('page.overdue_invoices.title')) ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f4f6f9;
            color: #333;
            min-height: 100vh;
        }

        header {
            background: #fff;
            border-bottom: 2px solid #d0d7e2;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        header img {
            height: 40px;
            width: auto;
        }

        header h1 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a2a44;
            flex: 1;
        }

        .language-switch {
            position: relative;
        }

        .dept-access-gear {
            position: fixed;
            top: 4.25rem;
            right: 0.85rem;
            z-index: 1400;
            width: 40px;
            height: 40px;
            border: 1px solid #c7ced9;
            border-radius: 8px;
            background: #fff;
            color: #1a2a44;
            font-size: 1.15rem;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }

        .dept-access-panel {
            position: fixed;
            top: 7rem;
            right: 0.85rem;
            width: min(420px, calc(100vw - 1.5rem));
            max-height: calc(100vh - 4.5rem);
            overflow: auto;
            background: #fff;
            border: 1px solid #d0d7e2;
            border-radius: 10px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18);
            z-index: 1400;
            display: none;
            padding: 0.9rem 1rem 1rem;
        }

        .dept-access-panel.open {
            display: block;
        }

        .dept-access-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.85rem;
        }

        .dept-access-panel-header strong {
            color: #1a2a44;
        }

        .dept-access-panel-close {
            border: 1px solid #c7ced9;
            background: #fff;
            border-radius: 6px;
            padding: 0.2rem 0.45rem;
            cursor: pointer;
        }

        .dept-access-add-row {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 0.9rem;
            position: relative;
        }

        .dept-access-add-row label {
            font-weight: 600;
            color: #1a2a44;
            font-size: 0.9rem;
        }

        .dept-access-add-controls {
            display: flex;
            gap: 0.45rem;
        }

        .dept-access-add-controls input {
            flex: 1;
            padding: 0.45rem 0.55rem;
            border: 1px solid #c7ced9;
            border-radius: 6px;
        }

        .dept-access-add-controls button {
            border: 1px solid #1a2a44;
            background: #1a2a44;
            color: #fff;
            border-radius: 6px;
            padding: 0.45rem 0.7rem;
            cursor: pointer;
        }

        .dept-access-suggestions {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 0.15rem);
            background: #fff;
            border: 1px solid #c7ced9;
            border-radius: 8px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            max-height: 220px;
            overflow: auto;
            display: none;
            z-index: 5;
        }

        .dept-access-suggestions.open {
            display: block;
        }

        .dept-access-suggestion {
            width: 100%;
            text-align: left;
            border: 0;
            background: #fff;
            padding: 0.5rem 0.65rem;
            cursor: pointer;
        }

        .dept-access-suggestion:hover,
        .dept-access-suggestion.active {
            background: #edf5ff;
        }

        .dept-access-suggestion small {
            display: block;
            color: #66788f;
        }

        .dept-access-users-title {
            font-weight: 600;
            color: #1a2a44;
            margin-bottom: 0.45rem;
        }

        .dept-access-group {
            margin-bottom: 0.75rem;
        }

        .dept-access-group-separator {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #44566f;
            background: #eef2f7;
            border: 1px solid #d5dde8;
            border-radius: 6px;
            padding: 0.35rem 0.5rem;
            margin-bottom: 0.35rem;
        }

        .dept-access-user-btn {
            width: 100%;
            text-align: left;
            border: 1px solid #d5dde8;
            background: #fff;
            border-radius: 6px;
            padding: 0.45rem 0.55rem;
            margin-bottom: 0.3rem;
            cursor: pointer;
            color: #1a2a44;
        }

        .dept-access-user-btn:hover {
            background: #edf5ff;
            border-color: #90b4da;
        }

        .dept-access-modal {
            position: fixed;
            inset: 0;
            background: rgba(12, 24, 43, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1500;
        }

        .dept-access-modal.open {
            display: flex;
        }

        .dept-access-modal-content {
            width: min(480px, 100%);
            max-height: 90vh;
            overflow: auto;
            background: #fff;
            border-radius: 10px;
            border: 1px solid #d0d7e2;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
            padding: 1.1rem 1.2rem;
        }

        .dept-access-modal-content h2 {
            margin: 0 0 0.85rem;
            font-size: 1.05rem;
            color: #1a2a44;
        }

        .dept-access-checks {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            margin-bottom: 1rem;
            max-height: 50vh;
            overflow: auto;
        }

        .dept-access-check-line {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.92rem;
            color: #1a2a44;
        }

        .dept-access-modal-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .dept-access-modal-actions button {
            border-radius: 6px;
            padding: 0.45rem 0.75rem;
            cursor: pointer;
        }

        .dept-access-modal-save {
            background: #1a2a44;
            color: #fff;
            border: 1px solid #1a2a44;
        }

        .dept-access-modal-cancel {
            background: #fff;
            color: #1a2a44;
            border: 1px solid #c7ced9;
        }

        .admin-open-btn {
            border: 1px solid #c7ced9;
            border-radius: 6px;
            background: #1a2a44;
            color: #fff;
            padding: 0.45rem 0.65rem;
            font-size: 0.84rem;
            cursor: pointer;
        }

        .language-switch button {
            width: 38px;
            height: 28px;
            padding: 0;
            border: 1px solid #c7ced9;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .language-switch button svg,
        .language-switch a svg {
            width: 26px;
            height: 18px;
            display: block;
        }

        .language-menu {
            position: absolute;
            top: calc(100% + 0.35rem);
            right: 0;
            background: #fff;
            border: 1px solid #c7ced9;
            border-radius: 8px;
            box-shadow: 0 5px 16px rgba(0, 0, 0, 0.12);
            padding: 0.35rem;
            display: none;
            gap: 0.35rem;
            z-index: 20;
        }

        .language-menu.open {
            display: flex;
        }

        .language-menu a {
            width: 32px;
            height: 24px;
            border: 1px solid #dbe2ea;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            background: #fff;
        }

        main {
            padding: 1rem;
            max-width: 1800px;
            margin: 0 auto;
        }

        .alert {
            border-radius: 6px;
            padding: 0.85rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.92rem;
        }

        .alert-danger {
            background: #fff0f0;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-info {
            background: #e8f4fd;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .summary {
            background: #fff;
            border: 1px solid #d0d7e2;
            border-radius: 8px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #555;
        }

        .summary strong {
            color: #c0392b;
        }

        .filters {
            background: #fff;
            border: 1px solid #d0d7e2;
            border-radius: 8px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 0.6rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .filters label {
            font-weight: 600;
            color: #1a2a44;
        }

        .filters select,
        .filters button {
            padding: 0.45rem 0.55rem;
            border: 1px solid #c7ced9;
            border-radius: 6px;
            background: #fff;
            font-size: 0.9rem;
        }

        .filters button {
            background: #1a2a44;
            color: #fff;
            cursor: pointer;
        }

        .filters .checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 600;
            color: #1a2a44;
            margin-left: 0.25rem;
        }

        .filters .checkbox-label input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            margin: 0;
        }

        .pager {
            margin-top: 0.75rem;
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .pager a,
        .pager span {
            display: inline-block;
            border: 1px solid #c7ced9;
            border-radius: 6px;
            padding: 0.35rem 0.55rem;
            background: #fff;
            color: #1a2a44;
            text-decoration: none;
            font-size: 0.88rem;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            font-size: 0.88rem;
        }

        thead th {
            background: #1a2a44;
            color: #fff;
            padding: 0.7rem 0.9rem;
            text-align: left;
            white-space: nowrap;
            font-weight: 600;
        }

        tbody tr:nth-child(even) {
            background: #f8f9fb;
        }

        tbody tr:hover {
            background: #eef2f8;
        }

        tbody td {
            padding: 0.65rem 0.9rem;
            border-bottom: 1px solid #e8ecf2;
            vertical-align: middle;
        }

        .badge-days {
            display: inline-block;
            padding: 0.2em 0.55em;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.85em;
        }

        .badge-days.critical {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-days.warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-days.mild {
            background: #ffeeba;
            color: #856404;
        }

        .badge-status {
            display: inline-block;
            padding: 0.25em 0.6em;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.8em;
            white-space: nowrap;
        }

        .badge-status.status-open {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-status.status-checked {
            background: #c3e6cb;
            color: #155724;
        }

        .badge-status.status-planned {
            background: #d1f5f5;
            color: #0e6674;
        }

        .badge-status.status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.status-invoiced {
            background: #e2e3e5;
            color: #383d41;
        }

        .badge-status.status-closed {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.status-unknown {
            background: #f4f6f9;
            color: #666;
        }

        .status-filter-buttons {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .status-filter-buttons label {
            font-weight: 600;
            margin-right: 0.5rem;
        }

        .status-filter-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .live-select-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }

        .live-select-filters label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1a2a44;
            margin-right: 0;
        }

        .live-select-filters select {
            padding: 0.35rem 0.55rem;
            border: 1px solid #c7ced9;
            border-radius: 6px;
            background: #fff;
            font-size: 0.86rem;
            max-width: 220px;
        }

        .status-filter-btn {
            padding: 0.35rem 0.75rem;
            border: 2px solid #d0d7e2;
            background: #fff;
            border-radius: 16px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .status-filter-btn:hover {
            border-color: #1a2a44;
        }

        .status-filter-btn.active {
            background: #1a2a44;
            color: #fff;
            border-color: #1a2a44;
        }

        .search-bar {
            background: #fff;
            border: 1px solid #d0d7e2;
            border-radius: 8px;
            padding: 0.6rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .search-bar input[type="search"] {
            flex: 1;
            border: 1px solid #c7ced9;
            border-radius: 6px;
            padding: 0.45rem 0.7rem;
            font-size: 0.9rem;
            color: #333;
            background: #fafbfc;
            outline: none;
            min-width: 0;
        }

        .search-bar input[type="search"]:focus {
            border-color: #1a2a44;
            background: #fff;
        }

        .amount {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .stream-loading-row td {
            text-align: center;
            color: #5a6d85;
            font-style: italic;
            background: #f4f8fc;
        }

        .row-inspectable {
            cursor: pointer;
        }

        .row-inspectable:hover {
            outline: 1px solid #90b4da;
            background: #edf5ff !important;
        }

        .stream-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #c5d2e2;
            border-top-color: #1a2a44;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 0.45rem;
            vertical-align: -2px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .json-modal {
            position: fixed;
            inset: 0;
            background: rgba(17, 25, 40, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 1rem;
        }

        .json-modal.open {
            display: flex;
        }

        .json-modal-content {
            width: min(980px, 100%);
            max-height: 88vh;
            overflow: hidden;
            background: #0f1724;
            color: #dbe7ff;
            border-radius: 10px;
            border: 1px solid #2a3a52;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.35);
            display: flex;
            flex-direction: column;
        }

        .json-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.65rem 0.8rem;
            border-bottom: 1px solid #2a3a52;
        }

        .json-modal-header strong {
            color: #e8f0ff;
        }

        .json-modal-close {
            border: 1px solid #3a4f6f;
            background: #1a2740;
            color: #dbe7ff;
            border-radius: 6px;
            padding: 0.2rem 0.45rem;
            cursor: pointer;
        }

        .json-modal pre {
            margin: 0;
            padding: 0.9rem;
            overflow: auto;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 0.84rem;
            line-height: 1.35;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .json-key {
            color: #8cc8ff;
        }

        .json-string {
            color: #a4e8c7;
        }

        .json-number {
            color: #ffd08a;
        }

        .json-boolean {
            color: #f5a6ff;
        }

        .json-null {
            color: #ff9a9a;
        }        .company-required-modal {
            position: fixed;
            inset: 0;
            background: rgba(12, 24, 43, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 1300;
        }

        .company-required-modal.open {
            display: flex;
        }

        .company-required-modal-content {
            width: min(420px, 100%);
            background: #fff;
            border: 1px solid #d0d7e2;
            border-radius: 10px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
            padding: 1.25rem 1.35rem;
        }

        .company-required-modal-content h2 {
            margin: 0 0 0.55rem;
            font-size: 1.15rem;
            color: #1a2a44;
        }

        .company-required-modal-content p {
            margin: 0 0 1rem;
            color: #44566f;
            line-height: 1.4;
        }

        .company-required-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .company-required-form label {
            font-weight: 600;
            color: #1a2a44;
        }

        .company-required-form select,
        .company-required-form button {
            padding: 0.55rem 0.65rem;
            border: 1px solid #c7ced9;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .company-required-form button {
            background: #1a2a44;
            color: #fff;
            border-color: #1a2a44;
            cursor: pointer;
        }

        .company-required-form button:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }        @media (max-width: 600px) {
            header h1 {
                font-size: 0.95rem;
            }

            thead th,
            tbody td {
                padding: 0.5rem 0.6rem;
            }

        }
    </style>
</head>

<body>

    <?php if (!empty($isAdminUser)): ?>
        <button type="button" class="dept-access-gear" id="dept-access-gear"
            title="<?= h(LOC('dept_access.button_title')) ?>"
            aria-label="<?= h(LOC('dept_access.button_title')) ?>">⚙</button>

        <div class="dept-access-panel" id="dept-access-panel" aria-hidden="true">
            <div class="dept-access-panel-header">
                <strong><?= h(LOC('dept_access.panel_title')) ?></strong>
                <button type="button" class="dept-access-panel-close"
                    id="dept-access-panel-close"><?= h(LOC('dept_access.close')) ?></button>
            </div>

            <div class="dept-access-add-row">
                <label for="dept-access-email"><?= h(LOC('dept_access.email_label')) ?></label>
                <div class="dept-access-add-controls">
                    <input type="text" id="dept-access-email" autocomplete="off"
                        placeholder="<?= h(LOC('dept_access.email_placeholder')) ?>">
                    <button type="button" id="dept-access-add"><?= h(LOC('dept_access.add')) ?></button>
                </div>
                <div class="dept-access-suggestions" id="dept-access-suggestions"></div>
            </div>

            <div class="dept-access-users-title"><?= h(LOC('dept_access.users_title')) ?></div>
            <div id="dept-access-user-list"></div>
        </div>

        <div class="dept-access-modal" id="dept-access-modal" aria-hidden="true">
            <div class="dept-access-modal-content" role="dialog" aria-modal="true">
                <h2 id="dept-access-modal-title"></h2>
                <div class="dept-access-checks" id="dept-access-checks"></div>
                <div class="dept-access-modal-actions">
                    <button type="button" class="dept-access-modal-cancel"
                        id="dept-access-modal-cancel"><?= h(LOC('dept_access.cancel')) ?></button>
                    <button type="button" class="dept-access-modal-save"
                        id="dept-access-modal-save"><?= h(LOC('dept_access.save')) ?></button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    $currentLanguage = getCurrentLanguage();
    $showCompanyColumn = $selectedCompany === '';
    $currentUserEmail = (string) ($_SESSION['user']['email'] ?? '');
    $canInspectRows = $currentUserEmail === '' || in_array($currentUserEmail, $ictUsers ?? [], true);
    $projectManagerDefault = trim((string) ($projectManagerDefaultSelection ?? ''));
    $projectManagerFilterDefault = trim((string) ($projectManagerFilterDefaultSelection ?? ''));
    $allowedProjectManagerList = talosPmUniqueStrings((array) ($allowedProjectManagers ?? []));
    $allProjectManagerList = talosPmUniqueStrings((array) ($allProjectManagers ?? []));
    $projectManagerDisplayLookup = is_array($projectManagerDisplayMap ?? null) ? $projectManagerDisplayMap : [];
    $projectManagerOwnLabel = trim((string) ($currentUserSetup['project_manager_name'] ?? ''));
    if ($projectManagerOwnLabel === '' && $projectManagerDefault !== '') {
        $projectManagerOwnLabel = (string) ($projectManagerDisplayLookup[$projectManagerDefault] ?? $projectManagerDefault);
    }
    $authDebug = [
        'email' => (string) ($_SESSION['user']['email'] ?? ''),
        'admin' => !empty($_SESSION['user']['admin']),
        'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'server_addr' => (string) ($_SERVER['SERVER_ADDR'] ?? ''),
        'trusted_requester' => function_exists('is_trusted_requester') ? is_trusted_requester() : null,
        'email_normalized' => strtolower(trim((string) ($_SESSION['user']['email'] ?? ''))),
        'ict_users_normalized' => array_values(array_map(
            static fn($email): string => strtolower(trim((string) $email)),
            is_array($ictUsers ?? null) ? $ictUsers : []
        )),
        'ict_match' => (static function () use ($ictUsers): bool {
            $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
            foreach (is_array($ictUsers ?? null) ? $ictUsers : [] as $candidate) {
                if (strtolower(trim((string) $candidate)) === $email) {
                    return true;
                }
            }
            return false;
        })(),
    ];
    $languageLinks = [];
    foreach (array_keys(SUPPORTED_LANGUAGES) as $languageCode) {
        if ($languageCode === $currentLanguage) {
            continue;
        }

        $query = $_GET;
        $query['lang'] = $languageCode;
        $languageLinks[] = [
            'code' => $languageCode,
            'url' => '?' . http_build_query($query),
        ];
    }
    ?>

    <header>
        <img src="logo-website.png" alt="KVT">
        <h1><?= h(LOC('page.overdue_invoices.heading')) ?></h1>
        <div class="language-switch" id="language-switch">
            <button type="button" id="language-switch-button" aria-label="Language">
                <?= getLanguageFlagSvg($currentLanguage) ?>
            </button>
            <div class="language-menu" id="language-menu">
                <?php foreach ($languageLinks as $languageLink): ?>
                    <a href="<?= h($languageLink['url']) ?>" aria-label="<?= h($languageLink['code']) ?>">
                        <?= getLanguageFlagSvg($languageLink['code']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </header>

    <main>
        <?php if (!empty($availableCompanies) && empty($requiresCompanySelection)): ?>
            <form method="get" class="filters">
                <label for="company-filter"><?= h(LOC('filter.company')) ?></label>
                <select id="company-filter" name="company">
                    <?php foreach ($availableCompanies as $companyName): ?>
                        <option value="<?= h($companyName) ?>" <?= $companyName === $selectedCompany ? 'selected' : '' ?>>
                            <?= h($companyName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="hide_sap_imports" value="0">
                <label for="hide-sap-imports" class="checkbox-label">
                    <input type="checkbox" id="hide-sap-imports" name="hide_sap_imports" value="1"
                        <?= !empty($hideSapImports) ? 'checked' : '' ?>>
                    <?= h(LOC('filter.hide_sap_imports')) ?>
                </label>
                <?php if (!empty($showOdataErrorDetails)): ?>
                    <input type="hidden" name="debug_odata" value="1">
                <?php endif; ?>
                <?php if (!empty($debugFetchAllRules)): ?>
                    <input type="hidden" name="debug_all_rules" value="1">
                <?php endif; ?>
                <button type="submit"><?= h(LOC('filter.apply')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($debugFetchAllRules)): ?>
            <div class="alert alert-info"><?= h(LOC('debug.fetch_all_rules_enabled')) ?></div>
            <?php if (!empty($debugCompanyResults)): ?>
                <div class="alert alert-info" style="white-space: pre-wrap; font-family: Consolas, monospace;">
                    <?php
                    $totalRowsFetched = 0;
                    $okCompanies = 0;
                    $failedCompanies = 0;
                    foreach ($debugCompanyResults as $companyResult) {
                        $totalRowsFetched += (int) ($companyResult['count'] ?? 0);
                        if (!empty($companyResult['ok'])) {
                            $okCompanies++;
                        } else {
                            $failedCompanies++;
                        }
                    }
                    echo h(LOC('debug.company_fetch_summary', $okCompanies, $failedCompanies, $totalRowsFetched, (int) $allLinesWithoutPlanningDate));
                    echo "\n\n";
                    foreach ($debugCompanyResults as $companyResult) {
                        $companyName = (string) ($companyResult['company'] ?? '');
                        $ok = !empty($companyResult['ok']);
                        $count = (int) ($companyResult['count'] ?? 0);
                        if ($ok) {
                            echo h(LOC('debug.company_fetch_ok', $companyName, $count)) . "\n";
                        } else {
                            $errorText = (string) ($companyResult['error'] ?? '');
                            echo h(LOC('debug.company_fetch_error', $companyName, $errorText)) . "\n";
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($requiresCompanySelection)): ?>
            <div class="alert alert-info"><?= h(LOC('filter.company_required_body')) ?></div>
        <?php elseif ($odataError !== null): ?>
            <div class="alert alert-danger"><?= h(LOC('error.odata_failed')) ?></div>
            <?php if (!empty($odataErrorPublic)): ?>
                <div class="alert alert-danger" style="white-space: pre-wrap; font-family: Consolas, monospace;">
                    <?= h($odataErrorPublic) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($showOdataErrorDetails)): ?>
                <div class="alert alert-info" style="white-space: pre-wrap; font-family: Consolas, monospace;">
                    <?= h($odataError) ?>
                </div>
            <?php endif; ?>
        <?php elseif (empty($pendingInvoiceLines) && empty($upcomingInvoiceLines)): ?>
            <div class="alert alert-info"><?= h(LOC('msg.no_overdue_invoices')) ?></div>
        <?php else: ?>
            <div class="status-filter-buttons" id="status-filter-buttons"
                data-all-label="<?= h(LOC('filter.status_all')) ?>">
                <label><?= h(LOC('filter.status')) ?>:</label>
                <div class="status-filter-list" id="status-filter-list"></div>
                <div class="live-select-filters">
                    <label for="project-manager-filter"><?= h(LOC('filter.project_manager')) ?>:</label>
                    <select id="project-manager-filter" data-all-label="<?= h(LOC('filter.project_manager_all')) ?>"
                        data-default="<?= h($projectManagerFilterDefault) ?>"></select>
                    <label for="cost-center-code-filter"><?= h(LOC('filter.cost_center_code')) ?>:</label>
                    <select id="cost-center-code-filter"
                        data-all-label="<?= h(LOC('filter.cost_center_code_all')) ?>"></select>
                    <label for="created-by-filter"><?= h(LOC('filter.created_by')) ?>:</label>
                    <select id="created-by-filter" data-all-label="<?= h(LOC('filter.created_by_all')) ?>"></select>
                </div>
            </div>

            <div class="search-bar" id="search-bar">
                <input type="search" id="table-search" placeholder="<?= h(LOC('filter.search_placeholder')) ?>"
                    autocomplete="off">
            </div>

            <?php if (!empty($pendingInvoiceLines)): ?>
                <div class="summary">
                    <strong><?= h(LOC('section.overdue')) ?>:</strong>
                    <?= h(LOC('summary.total')) ?> <strong
                        id="overdue-summary-count"><?= count($pendingInvoiceLines) ?></strong>
                    <span
                        id="overdue-summary-rule"><?= count($pendingInvoiceLines) === 1 ? h(LOC('summary.rule_singular')) : h(LOC('summary.rule_plural')) ?></span>
                    -
                    <?= h(LOC('summary.amount')) ?>:
                    <strong id="overdue-summary-amount">
                        <?php
                        $totalOpen = 0.0;
                        foreach ($pendingInvoiceLines as $line) {
                            $totalOpen += (float) ($line['Line_Amount'] ?? 0);
                        }
                        echo h(formatCurrency($totalOpen));
                        ?>
                    </strong>
                </div>

                <div class="table-wrapper" style="margin-bottom: 1rem;">
                    <table>
                        <thead>
                            <tr>
                                <th data-col="job"><?= h(LOC('table.job')) ?></th>
                                <th data-col="status"><?= h(LOC('table.status')) ?></th>
                                <th data-col="accountmanager"><?= h(LOC('table.accountmanager')) ?></th>
                                <th data-col="project_manager"><?= h(LOC('table.project_manager')) ?></th>
                                <th data-col="cost_center_code"><?= h(LOC('table.cost_center_code')) ?></th>
                                <th data-col="description"><?= h(LOC('table.description')) ?></th>
                                <th data-col="planning_date"><?= h(LOC('table.planning_date')) ?></th>
                                <th data-col="days"><?= h(LOC('table.days_overdue')) ?></th>
                                <th data-col="qty" class="amount"><?= h(LOC('table.quantity_to_invoice')) ?></th>
                                <th data-col="amount" class="amount"><?= h(LOC('table.line_amount')) ?></th>
                                <th data-col="customer"><?= h(LOC('table.customer')) ?></th>
                                <th data-col="document_no"><?= h(LOC('table.document_no')) ?></th>
                                <th data-col="work_order"><?= h(LOC('table.work_order')) ?></th>
                                <th data-col="jobcard_status"><?= h(LOC('table.jobcard_status')) ?></th>
                                <?php if ($showCompanyColumn): ?>
                                    <th data-col="company"><?= h(LOC('table.company')) ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="overdue-rows">
                            <?php foreach ($pendingInvoiceLines as $line): ?>
                                <?= renderInvoiceTableRow($line, true, $showCompanyColumn, $canInspectRows) ?>
                            <?php endforeach; ?>
                            <tr class="stream-loading-row" id="stream-loading-row-overdue" style="display:none;">
                                <td colspan="<?= $showCompanyColumn ? '15' : '14' ?>">
                                    <span class="stream-spinner"></span>
                                    <span id="stream-loading-text-overdue"><?= h(LOC('msg.stream_table_loading')) ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($upcomingInvoiceLines)): ?>
                <div class="summary">
                    <strong><?= h($upcomingSectionTitle) ?>:</strong>
                    <?= h(LOC('summary.total')) ?> <strong
                        id="upcoming-summary-count"><?= count($upcomingInvoiceLines) ?></strong>
                    <span
                        id="upcoming-summary-rule"><?= count($upcomingInvoiceLines) === 1 ? h(LOC('summary.rule_singular')) : h(LOC('summary.rule_plural')) ?></span>
                    -
                    <?= h(LOC('summary.amount')) ?>:
                    <strong id="upcoming-summary-amount">
                        <?php
                        $upcomingTotal = 0.0;
                        foreach ($upcomingInvoiceLines as $line) {
                            $upcomingTotal += (float) ($line['Line_Amount'] ?? 0);
                        }
                        echo h(formatCurrency($upcomingTotal));
                        ?>
                    </strong>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th data-col="job"><?= h(LOC('table.job')) ?></th>
                                <th data-col="status"><?= h(LOC('table.status')) ?></th>
                                <th data-col="accountmanager"><?= h(LOC('table.accountmanager')) ?></th>
                                <th data-col="project_manager"><?= h(LOC('table.project_manager')) ?></th>
                                <th data-col="cost_center_code"><?= h(LOC('table.cost_center_code')) ?></th>
                                <th data-col="description"><?= h(LOC('table.description')) ?></th>
                                <th data-col="planning_date"><?= h(LOC('table.planning_date')) ?></th>
                                <th data-col="days"><?= h(LOC('table.days_until_due')) ?></th>
                                <th data-col="qty" class="amount"><?= h(LOC('table.quantity_to_invoice')) ?></th>
                                <th data-col="amount" class="amount"><?= h(LOC('table.line_amount')) ?></th>
                                <th data-col="customer"><?= h(LOC('table.customer')) ?></th>
                                <th data-col="document_no"><?= h(LOC('table.document_no')) ?></th>
                                <th data-col="work_order"><?= h(LOC('table.work_order')) ?></th>
                                <th data-col="jobcard_status"><?= h(LOC('table.jobcard_status')) ?></th>
                                <?php if ($showCompanyColumn): ?>
                                    <th data-col="company"><?= h(LOC('table.company')) ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="upcoming-rows">
                            <?php foreach ($upcomingInvoiceLines as $line): ?>
                                <?= renderInvoiceTableRow($line, false, $showCompanyColumn, $canInspectRows) ?>
                            <?php endforeach; ?>
                            <tr class="stream-loading-row" id="stream-loading-row-upcoming" style="display:none;">
                                <td colspan="<?= $showCompanyColumn ? '15' : '14' ?>">
                                    <span class="stream-spinner"></span>
                                    <span id="stream-loading-text-upcoming"><?= h(LOC('msg.stream_table_loading')) ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php
            $queryBase = [];
            if ($selectedCompany !== '') {
                $queryBase['company'] = $selectedCompany;
            }
            if (!empty($showOdataErrorDetails)) {
                $queryBase['debug_odata'] = '1';
            }
            if (!empty($debugFetchAllRules)) {
                $queryBase['debug_all_rules'] = '1';
            }
            if (empty($hideSapImports)) {
                $queryBase['hide_sap_imports'] = '0';
            }
            $prevQuery = $queryBase;
            $prevQuery['page'] = max(1, (int) $currentPage - 1);
            $nextQuery = $queryBase;
            $nextQuery['page'] = (int) $currentPage + 1;
            ?>
            <div class="pager" id="manual-pager">
                <span><?= h(LOC('pager.page', (int) $currentPage)) ?></span>
                <?php if ((int) $currentPage > 1): ?>
                    <a href="?<?= h(http_build_query($prevQuery)) ?>"><?= h(LOC('pager.prev')) ?></a>
                <?php endif; ?>
                <a href="?<?= h(http_build_query($nextQuery)) ?>"><?= h(LOC('pager.next')) ?></a>
            </div>

        <?php endif; ?>

    </main>

    <?php if (!empty($requiresCompanySelection)): ?>
        <div class="company-required-modal open" id="company-required-modal" aria-hidden="false">
            <div class="company-required-modal-content" role="dialog" aria-modal="true"
                aria-labelledby="company-required-title">
                <h2 id="company-required-title"><?= h(LOC('filter.company_required_title')) ?></h2>
                <p><?= h(LOC('filter.company_required_body')) ?></p>
                <?php if (empty($availableCompanies)): ?>
                    <div class="alert alert-danger"><?= h(LOC('filter.company_required_empty')) ?></div>
                <?php else: ?>
                    <form method="get" class="company-required-form" id="company-required-form">
                        <label for="company-required-select"><?= h(LOC('filter.company')) ?></label>
                        <select id="company-required-select" name="company" required>
                            <option value=""><?= h(LOC('filter.company_required_placeholder')) ?></option>
                            <?php foreach ($availableCompanies as $companyName): ?>
                                <option value="<?= h($companyName) ?>"><?= h($companyName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($showOdataErrorDetails)): ?>
                            <input type="hidden" name="debug_odata" value="1">
                        <?php endif; ?>
                        <?php if (!empty($debugFetchAllRules)): ?>
                            <input type="hidden" name="debug_all_rules" value="1">
                        <?php endif; ?>
                        <?php if (!empty($hideSapImports)): ?>
                            <input type="hidden" name="hide_sap_imports" value="1">
                        <?php endif; ?>
                        <button type="submit" id="company-required-submit" disabled>
                            <?= h(LOC('filter.company_required_confirm')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canInspectRows): ?>
        <div class="json-modal" id="json-inspector-modal" aria-hidden="true">
            <div class="json-modal-content" role="dialog" aria-modal="true" aria-label="JSON inspectie">
                <div class="json-modal-header">
                    <strong>JSON inspectie</strong>
                    <button type="button" class="json-modal-close" id="json-inspector-close">Sluiten</button>
                </div>
                <pre id="json-inspector-content"></pre>
            </div>
        </div>
    <?php endif; ?>

    <script>
        (function ()
        {
            const languageSwitchEl = document.getElementById('language-switch');
            const languageButtonEl = document.getElementById('language-switch-button');
            const languageMenuEl = document.getElementById('language-menu');
            const manualPagerEl = document.getElementById('manual-pager');
            const overdueRowsEl = document.getElementById('overdue-rows');
            const upcomingRowsEl = document.getElementById('upcoming-rows');
            const overdueLoadingRowEl = document.getElementById('stream-loading-row-overdue');
            const upcomingLoadingRowEl = document.getElementById('stream-loading-row-upcoming');
            const overdueLoadingTextEl = document.getElementById('stream-loading-text-overdue');
            const upcomingLoadingTextEl = document.getElementById('stream-loading-text-upcoming');
            const overdueSummaryCountEl = document.getElementById('overdue-summary-count');
            const overdueSummaryRuleEl = document.getElementById('overdue-summary-rule');
            const overdueSummaryAmountEl = document.getElementById('overdue-summary-amount');
            const upcomingSummaryCountEl = document.getElementById('upcoming-summary-count');
            const upcomingSummaryRuleEl = document.getElementById('upcoming-summary-rule');
            const upcomingSummaryAmountEl = document.getElementById('upcoming-summary-amount');
            const jsonModalEl = document.getElementById('json-inspector-modal');
            const jsonModalCloseEl = document.getElementById('json-inspector-close');
            const jsonInspectorContentEl = document.getElementById('json-inspector-content');
            const statusFilterButtonsEl = document.getElementById('status-filter-buttons');
            const statusFilterListEl = document.getElementById('status-filter-list');
            const projectManagerFilterEl = document.getElementById('project-manager-filter');
            const costCenterCodeFilterEl = document.getElementById('cost-center-code-filter');
            const createdByFilterEl = document.getElementById('created-by-filter');
            const searchInputEl = document.getElementById('table-search');
            const seenOverdueRowKeys = new Set();
            const seenUpcomingRowKeys = new Set();
            const DYNAMIC_COLS = ['accountmanager', 'customer', 'document_no', 'work_order', 'company'];

            const config = {
                hasError: <?= $odataError !== null ? 'true' : 'false' ?>,
                requiresCompanySelection: <?= !empty($requiresCompanySelection) ? 'true' : 'false' ?>,
                endpoint: 'project_billing_stream.php',
                inspectorEndpoint: 'project_billing_row_inspect.php',
                company: <?= json_encode($selectedCompany, JSON_UNESCAPED_UNICODE) ?>,
                debugOdata: <?= !empty($showOdataErrorDetails) ? 'true' : 'false' ?>,
                debugAllRules: <?= !empty($debugFetchAllRules) ? 'true' : 'false' ?>,
                hideSapImports: <?= !empty($hideSapImports) ? 'true' : 'false' ?>,
                startPage: <?= (int) $currentPage + 1 ?>,
                isPartial: <?= !empty($isPartialResult) ? 'true' : 'false' ?>,
                maxPagesToLoad: 250,
                failedText: <?= json_encode(LOC('msg.stream_table_failed'), JSON_UNESCAPED_UNICODE) ?>,
                doneText: <?= json_encode(LOC('msg.stream_table_done'), JSON_UNESCAPED_UNICODE) ?>,
                noMoreText: <?= json_encode(LOC('msg.stream_no_more'), JSON_UNESCAPED_UNICODE) ?>,
                ruleSingular: <?= json_encode(LOC('summary.rule_singular'), JSON_UNESCAPED_UNICODE) ?>,
                rulePlural: <?= json_encode(LOC('summary.rule_plural'), JSON_UNESCAPED_UNICODE) ?>,
                statusOpenLabel: <?= json_encode(LOC('status.open'), JSON_UNESCAPED_UNICODE) ?>,
                statusCheckedLabel: <?= json_encode(LOC('status.checked'), JSON_UNESCAPED_UNICODE) ?>,
                statusPlannedLabel: <?= json_encode(LOC('status.planned'), JSON_UNESCAPED_UNICODE) ?>,
                projectManagerAllLabel: <?= json_encode(LOC('filter.project_manager_all'), JSON_UNESCAPED_UNICODE) ?>,
                costCenterCodeAllLabel: <?= json_encode(LOC('filter.cost_center_code_all'), JSON_UNESCAPED_UNICODE) ?>,
                createdByAllLabel: <?= json_encode(LOC('filter.created_by_all'), JSON_UNESCAPED_UNICODE) ?>,
                canInspectRows: <?= $canInspectRows ? 'true' : 'false' ?>,
                ownProjectManager: <?= json_encode($projectManagerDefault, JSON_UNESCAPED_UNICODE) ?>,
                projectManagerDefaultFilter: <?= json_encode($projectManagerFilterDefault, JSON_UNESCAPED_UNICODE) ?>,
                ownProjectManagerLabel: <?= json_encode($projectManagerOwnLabel, JSON_UNESCAPED_UNICODE) ?>,
                allowedProjectManagers: <?= json_encode($allowedProjectManagerList, JSON_UNESCAPED_UNICODE) ?>,
                allProjectManagers: <?= json_encode($allProjectManagerList, JSON_UNESCAPED_UNICODE) ?>,
                pmDisplayMap: <?= json_encode($projectManagerDisplayLookup, JSON_UNESCAPED_UNICODE) ?>,
                liveUpdateEndpoint: 'billing_live_update.php',
                snapshotVersion: <?= (int) ($billingSnapshotVersion ?? 0) ?>
            };

            const moneyFormatter = new Intl.NumberFormat(document.documentElement.lang || 'nl', {
                style: 'currency',
                currency: 'EUR'
            });

            if (languageSwitchEl && languageButtonEl && languageMenuEl)
            {
                languageButtonEl.addEventListener('click', function ()
                {
                    languageMenuEl.classList.toggle('open');
                });

                document.addEventListener('click', function (event)
                {
                    if (!languageSwitchEl.contains(event.target))
                    {
                        languageMenuEl.classList.remove('open');
                    }
                });
            }

            if (config.hasError || config.requiresCompanySelection)
            {
                const companyRequiredSelectEl = document.getElementById('company-required-select');
                const companyRequiredSubmitEl = document.getElementById('company-required-submit');
                if (companyRequiredSelectEl && companyRequiredSubmitEl)
                {
                    const syncCompanyRequiredSubmit = function ()
                    {
                        companyRequiredSubmitEl.disabled = companyRequiredSelectEl.value === '';
                    };
                    companyRequiredSelectEl.addEventListener('change', syncCompanyRequiredSubmit);
                    syncCompanyRequiredSubmit();
                    companyRequiredSelectEl.focus();
                }
                return;
            }

            //console.log('[Talos auth debug]', <?= json_encode($authDebug, JSON_UNESCAPED_UNICODE) ?>);

            if (manualPagerEl)
            {
                manualPagerEl.style.display = 'none';
            }

            function setLoadingRowVisibility (visible)
            {
                if (overdueLoadingRowEl)
                {
                    overdueLoadingRowEl.style.display = visible ? '' : 'none';
                }
                if (upcomingLoadingRowEl)
                {
                    upcomingLoadingRowEl.style.display = visible ? '' : 'none';
                }
            }

            function setLoadingText (text)
            {
                if (overdueLoadingTextEl)
                {
                    overdueLoadingTextEl.textContent = text;
                }
                if (upcomingLoadingTextEl)
                {
                    upcomingLoadingTextEl.textContent = text;
                }
            }

            function appendHtmlRowsBeforeLoader (targetBody, loaderRow, htmlRows)
            {
                if (!targetBody || !Array.isArray(htmlRows) || htmlRows.length === 0)
                {
                    return 0;
                }

                let appendedCount = 0;
                htmlRows.forEach(function (rowHtml)
                {
                    const temp = document.createElement('tbody');
                    temp.innerHTML = String(rowHtml || '').trim();
                    const rowNode = temp.firstElementChild;
                    if (!rowNode)
                    {
                        return;
                    }

                    const rowKey = String(rowNode.getAttribute('data-row-key') || '');
                    const keySet = targetBody === overdueRowsEl ? seenOverdueRowKeys : seenUpcomingRowKeys;
                    if (rowKey !== '' && keySet.has(rowKey))
                    {
                        return;
                    }
                    if (rowKey !== '')
                    {
                        keySet.add(rowKey);
                    }

                    if (loaderRow)
                    {
                        targetBody.insertBefore(rowNode, loaderRow);
                    } else
                    {
                        targetBody.appendChild(rowNode);
                    }
                    appendedCount++;
                });

                return appendedCount;
            }

            function initSeenKeys (targetBody, keySet)
            {
                if (!targetBody)
                {
                    return;
                }

                const rows = targetBody.querySelectorAll('tr[data-row-key]');
                rows.forEach(function (row)
                {
                    const rowKey = String(row.getAttribute('data-row-key') || '');
                    if (rowKey !== '')
                    {
                        keySet.add(rowKey);
                    }
                });
            }

            function sortOverdueRowsByDaysDesc ()
            {
                if (!overdueRowsEl)
                {
                    return;
                }

                const rows = Array.from(overdueRowsEl.querySelectorAll('tr[data-sort-days]'));
                rows.sort(function (left, right)
                {
                    const leftValue = Number(left.getAttribute('data-sort-days') || '0');
                    const rightValue = Number(right.getAttribute('data-sort-days') || '0');
                    return rightValue - leftValue;
                });

                rows.forEach(function (row)
                {
                    if (overdueLoadingRowEl)
                    {
                        overdueRowsEl.insertBefore(row, overdueLoadingRowEl);
                    } else
                    {
                        overdueRowsEl.appendChild(row);
                    }
                });
            }

            function updateSummaryFromRows (rowsEl, countEl, ruleEl, amountEl)
            {
                if (!rowsEl || !countEl || !ruleEl || !amountEl)
                {
                    return;
                }

                const rows = Array.from(rowsEl.querySelectorAll('tr[data-line-amount]')).filter(function (row)
                {
                    return row.style.display !== 'none';
                });
                const count = rows.length;
                let total = 0;

                rows.forEach(function (row)
                {
                    const amountRaw = String(row.getAttribute('data-line-amount') || '0').replace(',', '.');
                    const amount = Number(amountRaw);
                    if (Number.isFinite(amount))
                    {
                        total += amount;
                    }
                });

                countEl.textContent = String(count);
                ruleEl.textContent = count === 1 ? config.ruleSingular : config.rulePlural;
                amountEl.textContent = moneyFormatter.format(total);
            }

            function updateAllSummaries ()
            {
                updateSummaryFromRows(overdueRowsEl, overdueSummaryCountEl, overdueSummaryRuleEl, overdueSummaryAmountEl);
                updateSummaryFromRows(upcomingRowsEl, upcomingSummaryCountEl, upcomingSummaryRuleEl, upcomingSummaryAmountEl);
            }

            const activeStatusFilters = new Set();
            let activeSearchQuery = '';
            let activeProjectManager = String(config.projectManagerDefaultFilter || '');
            let activeCostCenterCode = '';
            let activeCreatedBy = '';
            let knownStatuses = [];

            function normalizeText (value)
            {
                return String(value || '').trim().toLowerCase();
            }

            function getProjectManagerLabel (managerCode)
            {
                const code = String(managerCode || '');
                if (code === '')
                {
                    return '';
                }

                const displayMap = config.pmDisplayMap || {};
                if (Object.prototype.hasOwnProperty.call(displayMap, code))
                {
                    const direct = String(displayMap[code] || '');
                    if (direct !== '')
                    {
                        return direct;
                    }
                }

                const normalized = normalizeText(code);
                const keys = Object.keys(displayMap);
                for (let i = 0; i < keys.length; i++)
                {
                    const key = String(keys[i]);
                    if (normalizeText(key) === normalized)
                    {
                        const value = String(displayMap[key] || '');
                        return value !== '' ? value : code;
                    }
                }

                return code;
            }

            function escapeHtml (value)
            {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function getStatusLabel (status)
            {
                if (status === 'Open')
                {
                    return config.statusOpenLabel;
                }
                if (status === 'Checked')
                {
                    return config.statusCheckedLabel;
                }
                if (status === 'Planned')
                {
                    return config.statusPlannedLabel;
                }

                return status;
            }

            function statusSortValue (status)
            {
                const preferredOrder = ['Open', 'Planned', 'Checked'];
                const index = preferredOrder.indexOf(status);
                return index === -1 ? 999 : index;
            }

            function collectStatusesFromRows ()
            {
                const statuses = new Set();

                [overdueRowsEl, upcomingRowsEl].forEach(function (tbodyEl)
                {
                    if (!tbodyEl)
                    {
                        return;
                    }

                    const cells = tbodyEl.querySelectorAll('tr[data-row-key] .badge-status[data-status]');
                    cells.forEach(function (cell)
                    {
                        const status = String(cell.getAttribute('data-status') || '').trim();
                        if (status !== '')
                        {
                            statuses.add(status);
                        }
                    });
                });

                return Array.from(statuses).sort(function (left, right)
                {
                    const leftSort = statusSortValue(left);
                    const rightSort = statusSortValue(right);
                    if (leftSort !== rightSort)
                    {
                        return leftSort - rightSort;
                    }

                    return left.localeCompare(right);
                });
            }

            function renderStatusFilterButtons ()
            {
                if (!statusFilterListEl || !statusFilterButtonsEl)
                {
                    return;
                }

                if (knownStatuses.length === 0)
                {
                    statusFilterListEl.innerHTML = '';
                    return;
                }

                const allLabel = String(statusFilterButtonsEl.getAttribute('data-all-label') || 'All');
                const allActive = knownStatuses.every(function (status)
                {
                    return activeStatusFilters.has(status);
                });

                let html = '<button class="status-filter-btn' + (allActive ? ' active' : '') + '" data-status="all" type="button">' + escapeHtml(allLabel) + '</button>';
                knownStatuses.forEach(function (status)
                {
                    const isActive = activeStatusFilters.has(status);
                    html += '<button class="status-filter-btn' + (isActive ? ' active' : '') + '" data-status="' + escapeHtml(status) + '" type="button">' + escapeHtml(getStatusLabel(status)) + '</button>';
                });

                statusFilterListEl.innerHTML = html;
            }

            function syncStatusFiltersFromRows ()
            {
                const discoveredStatuses = collectStatusesFromRows();
                const discoveredSet = new Set(discoveredStatuses);

                if (knownStatuses.length === 0 && activeStatusFilters.size === 0)
                {
                    discoveredStatuses.forEach(function (status)
                    {
                        activeStatusFilters.add(status);
                    });
                } else
                {
                    discoveredStatuses.forEach(function (status)
                    {
                        if (!activeStatusFilters.has(status))
                        {
                            activeStatusFilters.add(status);
                        }
                    });

                    Array.from(activeStatusFilters).forEach(function (status)
                    {
                        if (!discoveredSet.has(status))
                        {
                            activeStatusFilters.delete(status);
                        }
                    });
                }

                knownStatuses = discoveredStatuses;
                renderStatusFilterButtons();
            }

            function syntaxHighlightJson (jsonText)
            {
                const escaped = String(jsonText)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');

                return escaped.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\\"])*"\s*:?)|(\btrue\b|\bfalse\b)|(\bnull\b)|(-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match)
                {
                    if (/^".*":$/.test(match))
                    {
                        return '<span class="json-key">' + match + '</span>';
                    }
                    if (/^"/.test(match))
                    {
                        return '<span class="json-string">' + match + '</span>';
                    }
                    if (/true|false/.test(match))
                    {
                        return '<span class="json-boolean">' + match + '</span>';
                    }
                    if (/null/.test(match))
                    {
                        return '<span class="json-null">' + match + '</span>';
                    }
                    return '<span class="json-number">' + match + '</span>';
                });
            }

            function closeInspectorModal ()
            {
                if (!jsonModalEl)
                {
                    return;
                }

                jsonModalEl.classList.remove('open');
                jsonModalEl.setAttribute('aria-hidden', 'true');
            }

            function openInspectorModalRaw (jsonObject)
            {
                if (!jsonModalEl || !jsonInspectorContentEl)
                {
                    return;
                }

                const pretty = JSON.stringify(jsonObject, null, 2);
                jsonInspectorContentEl.innerHTML = syntaxHighlightJson(pretty);
                jsonModalEl.classList.add('open');
                jsonModalEl.setAttribute('aria-hidden', 'false');
            }

            function decodeBase64Json (payload)
            {
                const jsonText = atob(String(payload || ''));
                return JSON.parse(jsonText);
            }

            async function fetchFullRowForInspection (meta)
            {
                const params = new URLSearchParams();
                params.set('company', String(meta.company || ''));
                params.set('job_no', String(meta.job_no || ''));
                params.set('line_no', String(meta.line_no || ''));
                params.set('planning_date', String(meta.planning_date || ''));
                params.set('document_no', String(meta.document_no || ''));

                const response = await fetch(config.inspectorEndpoint + '?' + params.toString(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                if (!response.ok)
                {
                    throw new Error('HTTP ' + response.status);
                }

                const payload = await response.json();
                if (!payload || payload.ok !== true || typeof payload.row !== 'object' || payload.row === null)
                {
                    throw new Error(String(payload && payload.error ? payload.error : 'Failed to load full row'));
                }

                return payload.row;
            }

            function bindRowInspector (tbodyEl)
            {
                if (!tbodyEl || !config.canInspectRows)
                {
                    return;
                }

                tbodyEl.addEventListener('click', async function (event)
                {
                    const row = event.target.closest('tr.row-inspectable');
                    if (!row || !tbodyEl.contains(row))
                    {
                        return;
                    }

                    let fallbackJson = null;
                    const rowJsonBase64 = String(row.getAttribute('data-row-json') || '');
                    if (rowJsonBase64 !== '')
                    {
                        try
                        {
                            fallbackJson = decodeBase64Json(rowJsonBase64);
                        } catch (error)
                        {
                            fallbackJson = null;
                        }
                    }

                    const metaBase64 = String(row.getAttribute('data-inspect-meta') || '');
                    if (metaBase64 === '')
                    {
                        openInspectorModalRaw(fallbackJson || { error: 'Geen inspectie metadata beschikbaar' });
                        return;
                    }

                    try
                    {
                        const meta = decodeBase64Json(metaBase64);
                        const fullRow = await fetchFullRowForInspection(meta);
                        openInspectorModalRaw(fullRow);
                    } catch (error)
                    {
                        openInspectorModalRaw({
                            error: String(error && error.message ? error.message : error),
                            fallback_row: fallbackJson,
                        });
                    }
                });
            }

            function appendBatchRows (payload)
            {
                const pendingRowHtml = Array.isArray(payload.pending_row_html) ? payload.pending_row_html : [];
                const upcomingRowHtml = Array.isArray(payload.upcoming_row_html) ? payload.upcoming_row_html : [];

                if (pendingRowHtml.length === 0 && upcomingRowHtml.length === 0)
                {
                    return false;
                }

                const addedOverdue = appendHtmlRowsBeforeLoader(overdueRowsEl, overdueLoadingRowEl, pendingRowHtml);
                const addedUpcoming = appendHtmlRowsBeforeLoader(upcomingRowsEl, upcomingLoadingRowEl, upcomingRowHtml);

                if (addedOverdue > 0)
                {
                    sortOverdueRowsByDaysDesc();
                }

                if ((addedOverdue + addedUpcoming) > 0)
                {
                    syncStatusFiltersFromRows();
                    syncLiveFiltersFromRows();
                    updateRowVisibilityBasedOnStatus();
                    updateColumnVisibility();
                    bindRowInspector(overdueRowsEl);
                    bindRowInspector(upcomingRowsEl);
                }

                return (addedOverdue + addedUpcoming) > 0;
            }

            async function fetchPage (page)
            {
                const params = new URLSearchParams();
                params.set('company', config.company);
                params.set('page', String(page));
                params.set('stream', '1');
                if (config.debugOdata)
                {
                    params.set('debug_odata', '1');
                }
                if (config.debugAllRules)
                {
                    params.set('debug_all_rules', '1');
                }
                if (!config.hideSapImports)
                {
                    params.set('hide_sap_imports', '0');
                }

                const response = await fetch(config.endpoint + '?' + params.toString(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                if (!response.ok)
                {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            }

            async function runStream ()
            {
                let page = config.startPage;
                let loadedAny = false;

                initSeenKeys(overdueRowsEl, seenOverdueRowKeys);
                initSeenKeys(upcomingRowsEl, seenUpcomingRowKeys);
                sortOverdueRowsByDaysDesc();
                syncStatusFiltersFromRows();
                syncLiveFiltersFromRows();
                updateRowVisibilityBasedOnStatus();
                updateColumnVisibility();

                if (!config.isPartial)
                {
                    return;
                }

                setLoadingRowVisibility(true);

                for (let i = 0; i < config.maxPagesToLoad; i++)
                {
                    let payload;
                    try
                    {
                        payload = await fetchPage(page);
                    } catch (error)
                    {
                        setLoadingText(config.failedText);
                        return;
                    }

                    if (!payload || payload.ok !== true)
                    {
                        setLoadingText(config.failedText);
                        return;
                    }

                    const hasRows = appendBatchRows(payload);
                    if (!hasRows)
                    {
                        setLoadingText(loadedAny ? config.doneText : config.noMoreText);
                        setTimeout(function ()
                        {
                            setLoadingRowVisibility(false);
                        }, 1000);
                        return;
                    }

                    loadedAny = true;
                    page++;
                }

                setLoadingText(config.doneText);
                setTimeout(function ()
                {
                    setLoadingRowVisibility(false);
                }, 1000);
            }

            if (jsonModalCloseEl)
            {
                jsonModalCloseEl.addEventListener('click', closeInspectorModal);
            }
            if (jsonModalEl)
            {
                jsonModalEl.addEventListener('click', function (event)
                {
                    if (event.target === jsonModalEl)
                    {
                        closeInspectorModal();
                    }
                });
            }
            document.addEventListener('keydown', function (event)
            {
                if (event.key === 'Escape')
                {
                    closeInspectorModal();
                }
            });

            function rowMatchesSearch (row)
            {
                if (activeSearchQuery === '')
                {
                    return true;
                }

                return row.textContent.toLowerCase().indexOf(activeSearchQuery) !== -1;
            }

            function collectUniqueRowAttributeValues (attributeName)
            {
                const values = new Set();
                [overdueRowsEl, upcomingRowsEl].forEach(function (tbodyEl)
                {
                    if (!tbodyEl)
                    {
                        return;
                    }

                    const rows = tbodyEl.querySelectorAll('tr[data-row-key]');
                    rows.forEach(function (row)
                    {
                        const value = String(row.getAttribute(attributeName) || '').trim();
                        if (value !== '')
                        {
                            values.add(value);
                        }
                    });
                });

                return Array.from(values).sort(function (left, right)
                {
                    return left.localeCompare(right);
                });
            }

            function collectProjectManagerLabelMapFromRows ()
            {
                const map = {};

                [overdueRowsEl, upcomingRowsEl].forEach(function (tbodyEl)
                {
                    if (!tbodyEl)
                    {
                        return;
                    }

                    const rows = tbodyEl.querySelectorAll('tr[data-row-key]');
                    rows.forEach(function (row)
                    {
                        const managerCode = String(row.getAttribute('data-project-manager') || '').trim();
                        if (managerCode === '')
                        {
                            return;
                        }

                        if (Object.prototype.hasOwnProperty.call(map, managerCode))
                        {
                            return;
                        }

                        const managerCell = row.querySelector('td[data-col="project_manager"]');
                        const managerLabel = String(managerCell ? managerCell.textContent : '').trim();
                        map[managerCode] = managerLabel !== '' ? managerLabel : getProjectManagerLabel(managerCode);
                    });
                });

                return map;
            }

            function renderLiveFilterSelectOptions (selectEl, allLabel, values, forcedValue, labelResolver)
            {
                if (!selectEl)
                {
                    return;
                }

                const previousValue = String(selectEl.value || '');
                let html = '<option value="">' + escapeHtml(allLabel) + '</option>';
                values.forEach(function (value)
                {
                    const optionValue = String(value || '');
                    const optionLabel = typeof labelResolver === 'function' ? String(labelResolver(optionValue)) : optionValue;
                    html += '<option value="' + escapeHtml(optionValue) + '">' + escapeHtml(optionLabel) + '</option>';
                });
                selectEl.innerHTML = html;

                const force = String(forcedValue || '');
                if (force !== '' && values.indexOf(force) !== -1)
                {
                    selectEl.value = force;
                    return;
                }

                const canRestore = previousValue !== '' && values.indexOf(previousValue) !== -1;
                selectEl.value = canRestore ? previousValue : '';
            }

            function syncLiveFiltersFromRows ()
            {
                const projectManagersFromRows = collectUniqueRowAttributeValues('data-project-manager');
                const allowedSet = new Set((Array.isArray(config.allowedProjectManagers) ? config.allowedProjectManagers : []).map(normalizeText));
                const ownManagerCode = String(config.ownProjectManager || '').trim();
                const rowLabelMap = collectProjectManagerLabelMapFromRows();

                const projectManagers = projectManagersFromRows.filter(function (value)
                {
                    return allowedSet.size === 0 || allowedSet.has(normalizeText(value));
                });

                if (ownManagerCode !== '' && projectManagers.indexOf(ownManagerCode) === -1)
                {
                    projectManagers.push(ownManagerCode);
                }

                projectManagers.sort(function (left, right)
                {
                    return left.localeCompare(right);
                });

                const costCenterCodes = collectUniqueRowAttributeValues('data-cost-center-code');
                const createdByValues = collectUniqueRowAttributeValues('data-created-by');

                renderLiveFilterSelectOptions(
                    projectManagerFilterEl,
                    config.projectManagerAllLabel,
                    projectManagers,
                    activeProjectManager,
                    function (managerCode)
                    {
                        const ownLabel = String(config.ownProjectManagerLabel || '').trim();
                        if (ownLabel !== '' && normalizeText(managerCode) === normalizeText(config.ownProjectManager))
                        {
                            return ownLabel;
                        }

                        if (Object.prototype.hasOwnProperty.call(rowLabelMap, managerCode))
                        {
                            return String(rowLabelMap[managerCode] || managerCode);
                        }

                        return getProjectManagerLabel(managerCode);
                    }
                );
                renderLiveFilterSelectOptions(costCenterCodeFilterEl, config.costCenterCodeAllLabel, costCenterCodes);
                renderLiveFilterSelectOptions(createdByFilterEl, config.createdByAllLabel, createdByValues);

                activeProjectManager = projectManagerFilterEl ? String(projectManagerFilterEl.value || '') : '';
                activeCostCenterCode = costCenterCodeFilterEl ? String(costCenterCodeFilterEl.value || '') : '';
                activeCreatedBy = createdByFilterEl ? String(createdByFilterEl.value || '') : '';
            }

            function persistProjectManagerFilterPreference (managerCode)
            {
                const params = new URLSearchParams();
                params.set('action', 'save_project_manager_filter');
                if (String(managerCode || '').trim() !== '')
                {
                    params.set('project_manager', String(managerCode));
                }

                fetch(config.endpoint + '?' + params.toString(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                }).catch(function ()
                {
                    // Best effort persistence; UI filtering remains local.
                });
            }

            function updateRowVisibilityBasedOnStatus ()
            {
                if (!overdueRowsEl && !upcomingRowsEl)
                {
                    return;
                }

                [overdueRowsEl, upcomingRowsEl].forEach(function (tbodyEl)
                {
                    if (!tbodyEl)
                    {
                        return;
                    }

                    const rows = tbodyEl.querySelectorAll('tr[data-row-key]');
                    rows.forEach(function (row)
                    {
                        const statusCell = row.querySelector('[data-status]');
                        if (!statusCell)
                        {
                            row.style.display = rowMatchesSearch(row) ? '' : 'none';
                            return;
                        }

                        const status = String(statusCell.getAttribute('data-status') || '');
                        const passesStatus = activeStatusFilters.has(status);
                        const passesSearch = rowMatchesSearch(row);
                        const projectManager = String(row.getAttribute('data-project-manager') || '');
                        const costCenterCode = String(row.getAttribute('data-cost-center-code') || '');
                        const createdBy = String(row.getAttribute('data-created-by') || '');
                        const passesProjectManager = activeProjectManager === '' || normalizeText(projectManager) === normalizeText(activeProjectManager);
                        const passesCostCenterCode = activeCostCenterCode === '' || costCenterCode === activeCostCenterCode;
                        const passesCreatedBy = activeCreatedBy === '' || createdBy === activeCreatedBy;
                        row.style.display = (passesStatus && passesSearch && passesProjectManager && passesCostCenterCode && passesCreatedBy) ? '' : 'none';
                    });
                });

                updateAllSummaries();
            }

            function updateColumnVisibility ()
            {
                const allTbodies = [overdueRowsEl, upcomingRowsEl].filter(Boolean);
                DYNAMIC_COLS.forEach(function (col)
                {
                    let hasData = false;
                    allTbodies.forEach(function (tbody)
                    {
                        if (hasData)
                        {
                            return;
                        }

                        const cells = tbody.querySelectorAll('tr[data-row-key] td[data-col="' + col + '"]');
                        cells.forEach(function (cell)
                        {
                            if (cell.textContent.trim() !== '')
                            {
                                hasData = true;
                            }
                        });
                    });

                    document.querySelectorAll('[data-col="' + col + '"]').forEach(function (el)
                    {
                        el.style.display = hasData ? '' : 'none';
                    });
                });
            }

            function bindStatusFilterButtons ()
            {
                if (!statusFilterListEl)
                {
                    return;
                }

                statusFilterListEl.addEventListener('click', function (event)
                {
                    const btn = event.target.closest('.status-filter-btn');
                    if (!btn || !statusFilterListEl.contains(btn))
                    {
                        return;
                    }

                    const status = String(btn.getAttribute('data-status') || '');
                    if (status === '')
                    {
                        return;
                    }

                    if (status === 'all')
                    {
                        const allAreActive = knownStatuses.length > 0 && knownStatuses.every(function (knownStatus)
                        {
                            return activeStatusFilters.has(knownStatus);
                        });

                        if (allAreActive)
                        {
                            activeStatusFilters.clear();
                        } else
                        {
                            knownStatuses.forEach(function (knownStatus)
                            {
                                activeStatusFilters.add(knownStatus);
                            });
                        }

                        renderStatusFilterButtons();
                        updateRowVisibilityBasedOnStatus();
                        return;
                    }

                    if (activeStatusFilters.has(status))
                    {
                        activeStatusFilters.delete(status);
                    } else
                    {
                        activeStatusFilters.add(status);
                    }

                    renderStatusFilterButtons();
                    updateRowVisibilityBasedOnStatus();
                });
            }

            function ensureSectionTable (bucket)
            {
                const isOverdue = bucket === 'overdue';
                let tbody = isOverdue ? overdueRowsEl : upcomingRowsEl;
                if (tbody)
                {
                    return tbody;
                }

                // Table may be absent when the initial page had zero rows for that bucket.
                return null;
            }

            function applySilentLivePatches (payload)
            {
                const upserted = Array.isArray(payload.upserted) ? payload.upserted : [];
                const removed = Array.isArray(payload.removed) ? payload.removed : [];
                let changed = 0;

                removed.forEach(function (rowKey)
                {
                    const key = String(rowKey || '');
                    if (key === '')
                    {
                        return;
                    }

                    [overdueRowsEl, upcomingRowsEl].forEach(function (tbody)
                    {
                        if (!tbody)
                        {
                            return;
                        }
                        const existing = tbody.querySelector('tr[data-row-key="' + CSS.escape(key) + '"]');
                        if (existing)
                        {
                            existing.remove();
                            seenOverdueRowKeys.delete(key);
                            seenUpcomingRowKeys.delete(key);
                            changed++;
                        }
                    });
                });

                upserted.forEach(function (item)
                {
                    if (!item || typeof item !== 'object')
                    {
                        return;
                    }

                    const key = String(item.key || '');
                    const html = String(item.html || '');
                    const bucket = String(item.bucket || 'upcoming');
                    if (key === '' || html === '')
                    {
                        return;
                    }

                    const tbody = ensureSectionTable(bucket);
                    if (!tbody)
                    {
                        console.debug('[talos-live] skip upsert; missing tbody for', bucket, key);
                        return;
                    }

                    const loadingRow = tbody.querySelector('.stream-loading-row');
                    let existing = null;
                    [overdueRowsEl, upcomingRowsEl].forEach(function (candidate)
                    {
                        if (!candidate || existing)
                        {
                            return;
                        }
                        existing = candidate.querySelector('tr[data-row-key="' + CSS.escape(key) + '"]');
                        if (existing && candidate !== tbody)
                        {
                            existing.remove();
                            existing = null;
                        }
                    });

                    const template = document.createElement('tbody');
                    template.innerHTML = html.trim();
                    const newRow = template.querySelector('tr');
                    if (!newRow)
                    {
                        return;
                    }

                    if (existing)
                    {
                        existing.replaceWith(newRow);
                    } else if (loadingRow)
                    {
                        tbody.insertBefore(newRow, loadingRow);
                    } else
                    {
                        tbody.appendChild(newRow);
                    }

                    if (bucket === 'overdue')
                    {
                        seenOverdueRowKeys.add(key);
                        seenUpcomingRowKeys.delete(key);
                    } else
                    {
                        seenUpcomingRowKeys.add(key);
                        seenOverdueRowKeys.delete(key);
                    }
                    changed++;
                });

                if (changed > 0)
                {
                    sortOverdueRowsByDaysDesc();
                    syncStatusFiltersFromRows();
                    syncLiveFiltersFromRows();
                    updateRowVisibilityBasedOnStatus();
                    updateColumnVisibility();
                    console.debug('[talos-live] applied patches', {
                        upserted: upserted.length,
                        removed: removed.length,
                        changed: changed,
                        version: payload.version
                    });
                }
            }

            function startSilentLiveUpdates ()
            {
                if (config.hasError || config.requiresCompanySelection || !config.company)
                {
                    return;
                }

                let snapshotVersion = Number(config.snapshotVersion || 0);
                let timerId = null;
                let inFlight = false;
                let delayMs = 75000;

                function scheduleNext (ms)
                {
                    if (timerId)
                    {
                        clearTimeout(timerId);
                    }
                    timerId = setTimeout(runPoll, ms);
                }

                async function runPoll ()
                {
                    if (document.visibilityState === 'hidden')
                    {
                        scheduleNext(delayMs);
                        return;
                    }
                    if (inFlight)
                    {
                        scheduleNext(Math.max(15000, Math.floor(delayMs / 2)));
                        return;
                    }

                    inFlight = true;
                    try
                    {
                        const params = new URLSearchParams();
                        params.set('company', config.company);
                        params.set('since_version', String(snapshotVersion));
                        if (config.debugOdata)
                        {
                            params.set('debug_odata', '1');
                        }
                        if (!config.hideSapImports)
                        {
                            params.set('hide_sap_imports', '0');
                        }

                        console.debug('[talos-live] poll', {
                            company: config.company,
                            since_version: snapshotVersion
                        });

                        const response = await fetch(config.liveUpdateEndpoint + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        });
                        if (!response.ok)
                        {
                            throw new Error('HTTP ' + response.status);
                        }

                        const payload = await response.json();
                        console.debug('[talos-live] response', {
                            pending: !!payload.pending,
                            coalesced: !!payload.coalesced,
                            fetched: !!payload.fetched,
                            strategy: payload.strategy || null,
                            version: payload.version,
                            upserted: Array.isArray(payload.upserted) ? payload.upserted.length : 0,
                            removed: Array.isArray(payload.removed) ? payload.removed.length : 0
                        });

                        if (payload && payload.ok)
                        {
                            if (typeof payload.version === 'number' && payload.version > snapshotVersion)
                            {
                                applySilentLivePatches(payload);
                                snapshotVersion = payload.version;
                                config.snapshotVersion = snapshotVersion;
                            }

                            delayMs = payload.pending ? 20000 : 75000;
                        } else
                        {
                            delayMs = Math.min(180000, delayMs + 15000);
                        }
                    } catch (error)
                    {
                        console.debug('[talos-live] poll failed', error);
                        delayMs = Math.min(180000, delayMs + 15000);
                    } finally
                    {
                        inFlight = false;
                        scheduleNext(delayMs);
                    }
                }

                document.addEventListener('visibilitychange', function ()
                {
                    if (document.visibilityState === 'visible')
                    {
                        scheduleNext(5000);
                    }
                });

                const start = function ()
                {
                    scheduleNext(45000);
                };

                if (typeof window.requestIdleCallback === 'function')
                {
                    window.requestIdleCallback(start, { timeout: 60000 });
                } else
                {
                    setTimeout(start, 45000);
                }
            }

            bindStatusFilterButtons();
            bindRowInspector(overdueRowsEl);
            bindRowInspector(upcomingRowsEl);

            if (projectManagerFilterEl)
            {
                projectManagerFilterEl.addEventListener('change', function ()
                {
                    activeProjectManager = String(projectManagerFilterEl.value || '');
                    persistProjectManagerFilterPreference(activeProjectManager);
                    updateRowVisibilityBasedOnStatus();
                });
            }

            if (costCenterCodeFilterEl)
            {
                costCenterCodeFilterEl.addEventListener('change', function ()
                {
                    activeCostCenterCode = String(costCenterCodeFilterEl.value || '');
                    updateRowVisibilityBasedOnStatus();
                });
            }

            if (createdByFilterEl)
            {
                createdByFilterEl.addEventListener('change', function ()
                {
                    activeCreatedBy = String(createdByFilterEl.value || '');
                    updateRowVisibilityBasedOnStatus();
                });
            }

            let searchDebounceTimer = null;
            if (searchInputEl)
            {
                searchInputEl.addEventListener('input', function ()
                {
                    clearTimeout(searchDebounceTimer);
                    searchDebounceTimer = setTimeout(function ()
                    {
                        activeSearchQuery = searchInputEl.value.toLowerCase().trim();
                        updateRowVisibilityBasedOnStatus();
                    }, 200);
                });
            }

            runStream();
            startSilentLiveUpdates();
        })();
    </script>

    <?php if (!empty($isAdminUser)): ?>
        <script>
            (function ()
            {
                const gearEl = document.getElementById('dept-access-gear');
                const panelEl = document.getElementById('dept-access-panel');
                const panelCloseEl = document.getElementById('dept-access-panel-close');
                const emailInputEl = document.getElementById('dept-access-email');
                const addButtonEl = document.getElementById('dept-access-add');
                const suggestionsEl = document.getElementById('dept-access-suggestions');
                const userListEl = document.getElementById('dept-access-user-list');
                const modalEl = document.getElementById('dept-access-modal');
                const modalTitleEl = document.getElementById('dept-access-modal-title');
                const checksEl = document.getElementById('dept-access-checks');
                const modalSaveEl = document.getElementById('dept-access-modal-save');
                const modalCancelEl = document.getElementById('dept-access-modal-cancel');

                if (!gearEl || !panelEl || !emailInputEl || !userListEl || !modalEl)
                {
                    return;
                }

                const labels = {
                    emptyUsers: <?= json_encode(LOC('dept_access.empty_users'), JSON_UNESCAPED_UNICODE) ?>,
                    modalTitle: <?= json_encode(LOC('dept_access.modal_title'), JSON_UNESCAPED_UNICODE) ?>,
                    saveFailed: <?= json_encode(LOC('dept_access.save_failed'), JSON_UNESCAPED_UNICODE) ?>,
                    loadFailed: <?= json_encode(LOC('dept_access.load_failed'), JSON_UNESCAPED_UNICODE) ?>,
                    noDepartments: <?= json_encode(LOC('dept_access.no_departments'), JSON_UNESCAPED_UNICODE) ?>
                };

                const state = {
                    directoryUsers: [],
                    departments: [],
                    users: [],
                    groups: [],
                    activeEmail: '',
                    loaded: false,
                    suggestionIndex: -1
                };

                function escapeHtml (value)
                {
                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;');
                }

                function normalizeEmail (value)
                {
                    return String(value || '').trim().toLowerCase();
                }

                function normalizeDepartments (values)
                {
                    const unique = {};
                    (Array.isArray(values) ? values : []).forEach(function (value)
                    {
                        let code = String(value || '').trim();
                        if (code === '')
                        {
                            return;
                        }
                        if (/^\d+$/.test(code))
                        {
                            code = String(parseInt(code, 10));
                        }
                        unique[code] = code;
                    });

                    return Object.keys(unique).sort(function (left, right)
                    {
                        if (/^\d+$/.test(left) && /^\d+$/.test(right))
                        {
                            return parseInt(left, 10) - parseInt(right, 10);
                        }
                        return left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
                    });
                }

                function departmentSetKey (values)
                {
                    return normalizeDepartments(values).join('|');
                }

                function buildGroups (users)
                {
                    const groupsMap = {};
                    (Array.isArray(users) ? users : []).forEach(function (user)
                    {
                        const email = normalizeEmail(user.email);
                        const departments = normalizeDepartments(user.departments || []);
                        if (email === '' || departments.length === 0)
                        {
                            return;
                        }

                        const key = departmentSetKey(departments);
                        if (!groupsMap[key])
                        {
                            groupsMap[key] = {
                                key: key,
                                departments: departments,
                                users: []
                            };
                        }
                        groupsMap[key].users.push({
                            email: email,
                            departments: departments
                        });
                    });

                    const groups = Object.keys(groupsMap).map(function (key)
                    {
                        const group = groupsMap[key];
                        group.users.sort(function (left, right)
                        {
                            return left.email.localeCompare(right.email);
                        });
                        return group;
                    });

                    groups.sort(function (left, right)
                    {
                        if (left.departments.length !== right.departments.length)
                        {
                            return right.departments.length - left.departments.length;
                        }

                        const leftSum = left.departments.reduce(function (sum, code)
                        {
                            return sum + (/^\d+$/.test(code) ? parseInt(code, 10) : 0);
                        }, 0);
                        const rightSum = right.departments.reduce(function (sum, code)
                        {
                            return sum + (/^\d+$/.test(code) ? parseInt(code, 10) : 0);
                        }, 0);

                        if (leftSum !== rightSum)
                        {
                            return rightSum - leftSum;
                        }

                        return left.key.localeCompare(right.key);
                    });

                    return groups;
                }

                function setPanelOpen (open)
                {
                    panelEl.classList.toggle('open', open);
                    panelEl.setAttribute('aria-hidden', open ? 'false' : 'true');
                    if (open && !state.loaded)
                    {
                        loadAccessData();
                    }
                }

                function setModalOpen (open)
                {
                    modalEl.classList.toggle('open', open);
                    modalEl.setAttribute('aria-hidden', open ? 'false' : 'true');
                }

                function renderUserList ()
                {
                    const groups = Array.isArray(state.groups) && state.groups.length
                        ? state.groups
                        : buildGroups(state.users);

                    if (!groups.length)
                    {
                        userListEl.innerHTML = '<div class="alert alert-info">' + escapeHtml(labels.emptyUsers) + '</div>';
                        return;
                    }

                    let html = '';
                    groups.forEach(function (group)
                    {
                        html += '<div class="dept-access-group">';
                        html += '<div class="dept-access-group-separator">' + escapeHtml((group.departments || []).join(', ')) + '</div>';
                        (group.users || []).forEach(function (user)
                        {
                            html += '<button type="button" class="dept-access-user-btn" data-email="' + escapeHtml(user.email) + '">' + escapeHtml(user.email) + '</button>';
                        });
                        html += '</div>';
                    });
                    userListEl.innerHTML = html;
                }

                function renderSuggestions (query)
                {
                    const needle = String(query || '').trim().toLowerCase();
                    if (needle.length < 2)
                    {
                        suggestionsEl.classList.remove('open');
                        suggestionsEl.innerHTML = '';
                        state.suggestionIndex = -1;
                        return;
                    }

                    const matches = state.directoryUsers.filter(function (user)
                    {
                        const email = normalizeEmail(user.Email || user.email || '');
                        const name = String(user.Naam || user.name || '').toLowerCase();
                        return email.indexOf(needle) !== -1 || name.indexOf(needle) !== -1;
                    }).slice(0, 8);

                    if (!matches.length)
                    {
                        suggestionsEl.classList.remove('open');
                        suggestionsEl.innerHTML = '';
                        state.suggestionIndex = -1;
                        return;
                    }

                    suggestionsEl.innerHTML = matches.map(function (user, index)
                    {
                        const email = normalizeEmail(user.Email || user.email || '');
                        const name = String(user.Naam || user.name || '');
                        return '<button type="button" class="dept-access-suggestion' + (index === 0 ? ' active' : '') + '" data-email="' + escapeHtml(email) + '" data-index="' + index + '">'
                            + escapeHtml(name || email)
                            + (name ? '<small>' + escapeHtml(email) + '</small>' : '')
                            + '</button>';
                    }).join('');
                    suggestionsEl.classList.add('open');
                    state.suggestionIndex = 0;
                }

                function openUserModal (email)
                {
                    const normalized = normalizeEmail(email);
                    if (normalized === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized))
                    {
                        return;
                    }

                    state.activeEmail = normalized;
                    modalTitleEl.textContent = labels.modalTitle.replace('%s', normalized);

                    const existing = state.users.find(function (user)
                    {
                        return normalizeEmail(user.email) === normalized;
                    });
                    const selected = {};
                    normalizeDepartments(existing ? existing.departments : []).forEach(function (code)
                    {
                        selected[code] = true;
                    });

                    if (!state.departments.length)
                    {
                        checksEl.innerHTML = '<div class="alert alert-info">' + escapeHtml(labels.noDepartments) + '</div>';
                    } else
                    {
                        checksEl.innerHTML = state.departments.map(function (department)
                        {
                            const code = String(department.code || '');
                            const label = String(department.label || code);
                            const checked = selected[code] ? ' checked' : '';
                            return '<label class="dept-access-check-line">'
                                + '<input type="checkbox" value="' + escapeHtml(code) + '"' + checked + '>'
                                + '<span>' + escapeHtml(label) + '</span>'
                                + '</label>';
                        }).join('');
                    }

                    setModalOpen(true);
                }

                async function loadAccessData ()
                {
                    try
                    {
                        const company = <?= json_encode($selectedCompany ?? '', JSON_UNESCAPED_UNICODE) ?>;
                        const params = new URLSearchParams();
                        params.set('action', 'list');
                        if (company)
                        {
                            params.set('company', company);
                        }

                        const [accessResponse, usersResponse] = await Promise.all([
                            fetch('department_access_api.php?' + params.toString(), { credentials: 'same-origin' }),
                            fetch('getusers.php', { credentials: 'same-origin' })
                        ]);

                        const accessPayload = await accessResponse.json();
                        const usersPayload = await usersResponse.json();

                        if (!accessPayload || accessPayload.ok !== true)
                        {
                            throw new Error(labels.loadFailed);
                        }

                        state.departments = Array.isArray(accessPayload.departments) ? accessPayload.departments : [];
                        state.users = Array.isArray(accessPayload.users) ? accessPayload.users : [];
                        state.groups = Array.isArray(accessPayload.groups) ? accessPayload.groups : buildGroups(state.users);
                        state.directoryUsers = Array.isArray(usersPayload) ? usersPayload : [];
                        state.loaded = true;
                        renderUserList();
                    } catch (error)
                    {
                        userListEl.innerHTML = '<div class="alert alert-danger">' + escapeHtml(labels.loadFailed) + '</div>';
                    }
                }

                async function saveUserDepartments (email, departments)
                {
                    const response = await fetch('department_access_api.php?action=save', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            email: email,
                            departments: departments
                        })
                    });
                    const payload = await response.json();
                    if (!payload || payload.ok !== true)
                    {
                        throw new Error((payload && payload.error) ? payload.error : labels.saveFailed);
                    }

                    state.users = Array.isArray(payload.users) ? payload.users : state.users;
                    state.groups = Array.isArray(payload.groups) ? payload.groups : buildGroups(state.users);
                    renderUserList();
                    return payload;
                }

                gearEl.addEventListener('click', function ()
                {
                    setPanelOpen(!panelEl.classList.contains('open'));
                });

                if (panelCloseEl)
                {
                    panelCloseEl.addEventListener('click', function ()
                    {
                        setPanelOpen(false);
                    });
                }

                emailInputEl.addEventListener('input', function ()
                {
                    renderSuggestions(emailInputEl.value);
                });

                emailInputEl.addEventListener('keydown', function (event)
                {
                    const buttons = Array.from(suggestionsEl.querySelectorAll('.dept-access-suggestion'));
                    if (!buttons.length || !suggestionsEl.classList.contains('open'))
                    {
                        if (event.key === 'Enter')
                        {
                            event.preventDefault();
                            openUserModal(emailInputEl.value);
                        }
                        return;
                    }

                    if (event.key === 'ArrowDown')
                    {
                        event.preventDefault();
                        state.suggestionIndex = Math.min(buttons.length - 1, state.suggestionIndex + 1);
                    } else if (event.key === 'ArrowUp')
                    {
                        event.preventDefault();
                        state.suggestionIndex = Math.max(0, state.suggestionIndex - 1);
                    } else if (event.key === 'Enter')
                    {
                        event.preventDefault();
                        const active = buttons[state.suggestionIndex] || buttons[0];
                        if (active)
                        {
                            emailInputEl.value = active.getAttribute('data-email') || '';
                            suggestionsEl.classList.remove('open');
                            openUserModal(emailInputEl.value);
                        }
                        return;
                    } else
                    {
                        return;
                    }

                    buttons.forEach(function (button, index)
                    {
                        button.classList.toggle('active', index === state.suggestionIndex);
                    });
                });

                suggestionsEl.addEventListener('click', function (event)
                {
                    const button = event.target.closest('.dept-access-suggestion');
                    if (!button)
                    {
                        return;
                    }
                    emailInputEl.value = button.getAttribute('data-email') || '';
                    suggestionsEl.classList.remove('open');
                    openUserModal(emailInputEl.value);
                });

                if (addButtonEl)
                {
                    addButtonEl.addEventListener('click', function ()
                    {
                        openUserModal(emailInputEl.value);
                    });
                }

                userListEl.addEventListener('click', function (event)
                {
                    const button = event.target.closest('.dept-access-user-btn');
                    if (!button)
                    {
                        return;
                    }
                    openUserModal(button.getAttribute('data-email') || '');
                });

                if (modalCancelEl)
                {
                    modalCancelEl.addEventListener('click', function ()
                    {
                        setModalOpen(false);
                    });
                }

                modalEl.addEventListener('click', function (event)
                {
                    if (event.target === modalEl)
                    {
                        setModalOpen(false);
                    }
                });

                if (modalSaveEl)
                {
                    modalSaveEl.addEventListener('click', async function ()
                    {
                        const selected = Array.from(checksEl.querySelectorAll('input[type="checkbox"]:checked')).map(function (input)
                        {
                            return input.value;
                        });

                        try
                        {
                            await saveUserDepartments(state.activeEmail, selected);
                            setModalOpen(false);
                            emailInputEl.value = '';
                            suggestionsEl.classList.remove('open');
                        } catch (error)
                        {
                            window.alert(error && error.message ? error.message : labels.saveFailed);
                        }
                    });
                }

                document.addEventListener('click', function (event)
                {
                    if (!panelEl.classList.contains('open'))
                    {
                        return;
                    }
                    if (panelEl.contains(event.target) || gearEl.contains(event.target) || modalEl.contains(event.target))
                    {
                        return;
                    }
                    setPanelOpen(false);
                });
            })();
        </script>
    <?php endif; ?>

</body>

</html>
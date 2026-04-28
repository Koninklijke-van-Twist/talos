<?php

// Minimale sessie-stub zodat LOC() geen fout gooit op getCurrentLanguage()
$_SESSION = [];

require_once __DIR__ . '/../web/content/localization.php';
require_once __DIR__ . '/../web/content/helpers.php';

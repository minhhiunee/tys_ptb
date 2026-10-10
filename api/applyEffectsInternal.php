<?php
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

$_POST['file'] = $argv[1];
$_POST['style'] = 'photo';
$_POST['skip_lightroom'] = true;

// Bypass CSRF for internal CLI execution
function checkCsrfOrFail($source) {}

// Fake $_SERVER variables that applyEffects.php might need
$_SERVER['PHP_SELF'] = 'api/applyEffects.php';

require __DIR__ . '/applyEffects.php';

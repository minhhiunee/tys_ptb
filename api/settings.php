<?php

require_once '../lib/boot.php';

use Photobooth\Utility\ClientSettingsUtility;

header('Content-Type: application/javascript');

echo ClientSettingsUtility::renderJavaScript();

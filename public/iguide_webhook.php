<?php

http_response_code(200);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
$_SERVER['PHP_SELF'] = '/index.php/iguide_webhook.php';
$_SERVER['PATH_INFO'] = '/iguide_webhook.php';
$query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
$_SERVER['REQUEST_URI'] = '/iguide_webhook.php'.($query !== '' ? '?'.$query : '');

require __DIR__.'/index.php';

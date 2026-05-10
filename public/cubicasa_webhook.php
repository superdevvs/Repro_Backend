<?php

http_response_code(200);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
$_SERVER['PHP_SELF'] = '/index.php/cubicasa_webhook.php';
$_SERVER['PATH_INFO'] = '/cubicasa_webhook.php';
$_SERVER['REQUEST_URI'] = '/cubicasa_webhook.php';

require __DIR__.'/index.php';

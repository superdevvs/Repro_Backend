<?php

$pdo = new PDO('sqlite:/var/www/backend/database/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$deleted = $pdo->exec("delete from failed_jobs where exception like '%ClamAvUnavailable%'");
$left = (int) $pdo->query('select count(*) from failed_jobs')->fetchColumn();
echo "deleted_clamav={$deleted} remaining={$left}\n";

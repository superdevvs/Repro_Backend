<?php
echo implode(',', \Illuminate\Support\Facades\Schema::getColumnListing('ai_reel_jobs')) . PHP_EOL;
$m = new \App\Models\AiReelJob();
echo 'shoot->' . get_class($m->shoot()->getRelated()) . PHP_EOL;
echo 'user->' . get_class($m->user()->getRelated()) . PHP_EOL;
echo 'casts: ' . implode(',', array_keys($m->getCasts())) . PHP_EOL;
echo 'active: ' . var_export((new \App\Models\AiReelJob(['status' => 'processing']))->isActive(), true) . PHP_EOL;

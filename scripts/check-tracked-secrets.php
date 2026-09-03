<?php

$spec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open(['git', 'ls-files', '-z'], $spec, $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "git ls-files failed to start\n");
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
if ($code !== 0) {
    fwrite(STDERR, "git ls-files failed: {$stderr}\n");
    exit(1);
}

$blocked = [];
foreach (explode("\0", $stdout) as $file) {
    if ($file === '') {
        continue;
    }
    $leaf = basename($file);
    if ($leaf === '.env.example') {
        continue;
    }
    if ($leaf === '.env' || str_starts_with($leaf, '.env.')) {
        $blocked[] = $file;
        continue;
    }
    if (preg_match('/\.(pem|key|p12|pfx|crt|cer|jks)$/i', $leaf) === 1) {
        $blocked[] = $file;
    }
}

if ($blocked !== []) {
    fwrite(STDERR, "Tracked env/key/pem files are not allowed:\n");
    foreach ($blocked as $file) {
        fwrite(STDERR, "  {$file}\n");
    }
    exit(1);
}

echo "No tracked env/key/pem files (.env.example allowed).\n";

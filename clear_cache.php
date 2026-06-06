<?php
// One-time cache clearer — DELETE THIS FILE AFTER USE
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared. ";
}
// Clear Twig cache dir
$dir = __DIR__.'/uploads/cache/templates';
if (is_dir($dir)) {
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file) : unlink($file);
        $count++;
    }
    echo "Twig cache cleared ($count items). ";
}
echo "Done. <strong>Please delete this file now.</strong>";

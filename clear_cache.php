<?php
// Cache clearer — DELETE THIS FILE AFTER USE
if (function_exists('opcache_reset')) { opcache_reset(); echo "OPcache cleared. "; } else { echo "OPcache not available. "; }
$dir = __DIR__.'/uploads/cache/templates';
if (is_dir($dir)) { $count=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){$f->isDir()?rmdir($f):unlink($f);$count++;} echo "Twig cache cleared ($count items). "; }
echo "Done. Please delete this file.";

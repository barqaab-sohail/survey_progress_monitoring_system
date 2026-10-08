<?php

$c = require __DIR__.'/../bootstrap/cache/config.php';
echo 'Cached database driver: '.$c['database']['default'].PHP_EOL;
echo 'Cached SQLite database: '.$c['database']['connections']['sqlite']['database'].PHP_EOL;

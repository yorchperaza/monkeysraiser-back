<?php
require __DIR__ . '/vendor/autoload.php';

echo "CWD: " . getcwd() . "\n";
echo "base_path: " . base_path() . "\n";
echo "autoload_psr4 base: " . dirname(__DIR__) . "\n";

$loader = require __DIR__ . '/vendor/autoload.php';
$prefixes = $loader->getPrefixesPsr4(); 
echo "App prefix: " . print_r($prefixes['App\\'] ?? 'NOT FOUND', true) . "\n";



define('ML_BASE_PATH', __DIR__);

require __DIR__ . '/vendor/autoload.php';

use MonkeysLegion\Mlc\MlcParser;
use MonkeysLegion\Mlc\MlcLoader;
use MonkeysLegion\Http\SimpleFileCache;

echo "--- Config Debug ---\n";
$parser = new MlcParser();
$loader = new MlcLoader($parser, base_path('config'), base_path(), new SimpleFileCache(base_path('var/cache/mlc')));

$config = $loader->load(['auth']);
$model = $config['auth']['users']['model'] ?? 'NOT SET';
echo "Configured User Model: " . var_export($model, true) . "\n";
echo "Length: " . strlen($model) . "\n";
foreach (str_split($model) as $char) {
    echo $char . " (" . ord($char) . ") ";
}
echo "\n";



<?php
define('ML_BASE_PATH', __DIR__);
require __DIR__ . '/vendor/autoload.php';

use MonkeysLegion\Mlc\MlcParser;
use MonkeysLegion\Mlc\MlcLoader;
use MonkeysLegion\Http\SimpleFileCache;

echo "--- Config Debug ---\n";
try {
    $parser = new MlcParser();
    $loader = new MlcLoader($parser, base_path('config'), base_path(), new SimpleFileCache(base_path('var/cache/mlc')));

    $config = $loader->load(['auth']);
    $model = $config['auth']['users']['model'] ?? 'NOT SET';
    
    echo "Configured User Model: " . var_export($model, true) . "\n";
    echo "Length: " . strlen($model) . "\n";
    echo "Char codes: ";
    foreach (str_split($model) as $char) {
        echo ord($char) . " ";
    }
    echo "\n";

    if (strpos($model, '\\\\') !== false) {
        echo "WARNING: Double backslashes detected!\n";
    } else {
        echo "No double backslashes detected.\n";
    }

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

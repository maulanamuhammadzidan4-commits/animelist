<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== APACHE PHP DIAGNOSTIC ===\n\n";

echo "Current Directory: " . getcwd() . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
echo "Index Directory: " . __DIR__ . "\n";
echo "Open Basedir: " . (ini_get('open_basedir') ?: 'None') . "\n\n";

$paths = [
    'Relative anime' => 'anime/',
    'Absolute anime' => __DIR__ . '/anime',
    'End folder' => __DIR__ . '/anime/end',
    'Ongoing folder' => __DIR__ . '/anime/ongoing'
];

foreach ($paths as $name => $path) {
    echo "[$name]\n";
    echo "Path: $path\n";
    echo "Exists: " . (is_dir($path) ? 'YES' : 'NO') . "\n";
    echo "Readable: " . (is_readable($path) ? 'YES' : 'NO') . "\n";

    if (is_dir($path)) {
        $items = scandir($path);
        echo "Items: " . (count($items) - 2) . "\n";
    }

    echo "\n";
}
?>

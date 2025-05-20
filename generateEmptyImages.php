<?php
declare(strict_types=1);

namespace ProcessWire;

use ProcessWire\ProcessWire as PW;

if (!defined('PROCESSWIRECLI')) {
    fwrite(STDERR, "This script must be run via `wire` CLI\n");
    exit(1);
}

$config   = wire('config');
$modules  = wire('modules');
$pages    = wire('pages');
$fields   = wire('fields');

$blurMod  = $modules->get('ImageBlurhash');

$totalFields = 0;
$totalPages  = 0;
$totalImages = 0;
$totalHashes = 0;

// 1) Alle Image-Felder mit Blurhash aktiv
$fieldSelector = 'type=FieldtypeImage,createBlurhash=1';
foreach ($fields->find($fieldSelector) as $field) {
    $totalFields++;
    $fieldName = $field->name;
    echo "=== Field: {$fieldName} ===\n";

    $pageSelector = "{$fieldName.count>0},check_access=0";
    foreach ($pages->find($pageSelector) as $page) {
        $totalPages++;
        $images = $page->getUnformatted($fieldName);
        echo "Page #{$page->id} - found {$images->count()} images\n";

        foreach ($page->get($fieldName) as $image) {
            $totalImages++;
            if ($image->blurhash !== null) {
                continue;
            }

            echo "- Processing image '{$image->name}' ... ";
            $hash = $blurMod->createBlurhash($image);
            if ($hash === null) {
                echo "FAILED (generate)\n";
                continue;
            }

            $blurMod->insertBlurhash($hash, $page, $field, $image);
            echo "OK\n";
            $totalHashes++;
        }
    }
}

echo "\nFinished.\n";
echo "Fields checked : {$totalFields}\n";
echo "Pages scanned  : {$totalPages}\n";
echo "Images checked : {$totalImages}\n";
echo "Hashes created : {$totalHashes}\n";

exit(0);

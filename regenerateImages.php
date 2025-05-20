<?php

declare(strict_types=1);

namespace ProcessWire;

set_time_limit(0);
ini_set('max_execution_time', 0);

include_once(__DIR__ . "/../../../index.php");

// if not executed over cli
if(!$config->cli) exit();

$config  = wire('config');
$modules = wire('modules');
$pages   = wire('pages');
$fields  = wire('fields');

$blurMod = $modules->get('ImageBlurhash');

$totalFields = 0;
$totalPages  = 0;
$totalImages = 0;
$totalHashes = 0;

foreach ($fields->find('type=FieldtypeImage,createBlurhash=1') as $field) {
    $totalFields++;
    $fieldName = $field->name;
    echo "\n=== Feld: {$fieldName} ===\n";

    foreach ($pages->find("{$fieldName}.count>0,check_access=0") as $page) {
        $totalPages++;
        $images = $page->get($fieldName);
        echo "Page #{$page->id}: {$images->count()} images\n";

        foreach ($images as $image) {
            $totalImages++;
            echo "- '{$image->name}': ";

            $files    = $image->getFiles();
            $basename = $image->name;
            $file     = $files[$basename] ?? reset($files);

            // optional loading image over HTTP for some special internal cases
            // when image does not exist in the filepath
            if (empty($file) || !is_file($file)) {
                $url  = $image->url;
                $data = @file_get_contents($url);
                if (empty($data)) {
                    echo "FAILED (HTTP download)\n";
                    continue;
                }
                $file = 'data://application/octet-stream;base64,' . base64_encode($data);
            }

            $hash = $blurMod->createBlurhash($file);
            if ($hash === null) {
                echo "ERROR (hash generation failed)\n";
                continue;
            }

            $blurMod->insertBlurhash($hash, $page, $field, $image);
            echo "OK\n";
            $totalHashes++;
        }
    }
}

echo "\nFertig.\n";
echo "Checked fields : {$totalFields}\n";
echo "Checked pages: {$totalPages}\n";
echo "Checked images  : {$totalImages}\n";
echo "Generated hashes : {$totalHashes}\n";

exit(0);

<?php
declare(strict_types=1);

namespace ProcessWire;

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
        echo "Seite #{$page->id}: {$images->count()} Bilder\n";

        foreach ($images as $image) {
            $totalImages++;
            echo "- '{$image->name}': ";

            $files    = $image->getFiles();
            $basename = $image->name;
            $path     = $files[$basename] ?? reset($files);

            // optional loading image over HTTP for some special internal cases
            // when image does not exist in the filepath
            if (empty($path) || !is_file($path)) {
                $url  = $image->url;
                $data = @file_get_contents($url);
                if (empty($data)) {
                    echo "Error (HTTP-Download failed)\n";
                    continue;
                }
                $tmp = 'data://text/plain;base64,' . base64_encode($data);
                $path = $tmp;
            }

            $hash = $blurMod->createBlurhash($image);
            if ($hash === null) {
                echo "FEHLER (Generierung fehlgeschlagen)\n";
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

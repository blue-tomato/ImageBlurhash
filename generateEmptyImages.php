<?php

namespace ProcessWire;

set_time_limit(0);
ini_set('max_execution_time', 0);

// include processwire api
include_once(__DIR__ . "/../../../index.php");

// if not executed over cli
if(!$config->cli) exit();

$ImageBlurhash = $modules->get("ImageBlurhash");

foreach ($fields->find("type=FieldtypeImage") as $field) {
    if (isset($field->createBlurhash) && $field->createBlurhash) {
        foreach ($pages->find("$field.count>0, check_access=0") as $page) {
            foreach ($page->getUnformatted($field->name) as $image) {

                if (!$image->blurhash) {
                    $file = null;
                    if (file_exists($image->filename)) {
                        if(!exif_imagetype($image->filename)) continue;
                        $file = $image->filename;
                    } else {
                        // optional loading image over HTTP for some special internal cases at Blue Tomato when image does not exist in the filepath
                        $file = $image->url;
                    }

                    echo "Try {$image->name} in {$field->name} \n";

                    $blurhash = $ImageBlurhash->createBlurhash($file);

                    if ($blurhash) {
                        $success = $ImageBlurhash->insertBlurhash($blurhash, $page, $field, $image);
                        if ($success) {
                            echo "Blurhash saved for {$image->name} in {$field->name} \n";
                        } else {
                            echo "Error: Something went wrong for {$image->name} in {$field->name} \n";
                        }
                    } else {
                        echo "Error: Blurhash generation failed {$image->name} in {$field->name} \n";
                    }
                }
            }
        }
    }
}

echo "All done";
exit();

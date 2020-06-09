<?php

namespace ProcessWire;

use kornrunner\Blurhash\Blurhash;

class ImageBlurhash extends InputfieldImage implements Module
{

    static public function getModuleInfo()
    {
        return array(
            'title' => 'ImageBlurhash',
            'class' => 'ImageBlurhash',
            'author' => 'Blue Tomato',
            'version' => 100,
            'summary' => 'Create Blurhash Strings during image upload',
            'singular' => true,
            'autoload' => true,
            'requires' => array('PHP>=7.0', 'FieldtypeImage')
        );
    }

    public function init()
    {
        $this->addHookAfter('FieldtypeImage::getConfigInputfields', $this, 'hookGetConfigInputFields');
        $this->addHookBefore('FieldtypeImage::savePageField', $this, 'hookSavePageField');

        $this->addHookProperty('Pageimage::blurhash', function (HookEvent $event) {
            $image = $event->object;
            $field = $image->pagefiles->getField();
            $event->return = $this->getRawBlurhash($field->name, $image->name);
        });

        $this->addHookMethod('Pageimage::getBlurhashDataUri', function (HookEvent $event) {
            $image = $event->object;
            $field = $image->pagefiles->getField();
            $width = $event->arguments(0);
            $height = $event->arguments(1);
            $event->return = $this->getDecodedBlurhash($field->name, $image->name, $width, $height);
        });
    }

    protected function hookGetConfigInputFields(HookEvent $event)
    {
        $field = $event->arguments(0);
        $inputfields = $event->return;
        $children = $inputfields->get('children'); // Due there is no first() in InputfieldWrapper

        $f = $this->wire('modules')->get('InputfieldRadios');
        $f->label = $this->_('Generate Blurhash Strings');
        $f->description = $this->_('Should this field generate Blurhash Values?');
        $f->attr('name', 'createBlurhash');
        $f->addOption(1, $this->_('Yes'));
        $f->addOption(0, $this->_('No'));
        $f->attr('value', (int) $field->createBlurhash);
        $inputfields->insertAfter($f, $children->first());

        if ($field->createBlurhash) {
            $inputfields->remove('defaultValue');
        }
    }

    protected function hookSavePageField(HookEvent $event)
    {
        $page = $event->arguments(0);
        $field = $event->arguments(1);

        $images = $page->get($field->name);
        if ($field->createBlurhash && $images->count() > 0 && !$page->hasStatus(Page::statusDeleted)) {
            foreach ($images as $name => $image) {
                $blurhash = $this->createBlurhash($image->filename);
                if ($blurhash && $columnExists = $this->checkBlurhashTableColumn($field->name)) {
                    $this->insertBlurhash($blurhash, $field->name, $name);
                }
            }
        }
    }

    public function getRawBlurhash(string $fieldName, string $imageName)
    {
        $db = $this->wire('database');

        $col = "blurhash";
        $table = "field_{$fieldName}";

        $sql = "SELECT $col FROM $table WHERE data='$imageName' LIMIT 1";
        try {
            $result = $db->query($sql)->fetch();
            if ($result && isset($result[0]) && !empty($result[0])) {
                return $result[0];
            }
        } catch (\Exception $e) {
            $this->errors($e->getMessage(), Notice::log);
        }

        return false;
    }

    public function getDecodedBlurhash(string $fieldName, string $imageName, float $width = 0, float $height = 0)
    {
        $blankFallbackGif = "data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==";
        $rawBlurhash = $this->getRawBlurhash($fieldName, $imageName);
        if ($rawBlurhash && $width > 0 && $height > 0) {

            $ratio =  $width / $height;
            $calcWidth = 200;
            $calcWidth = ($width > $calcWidth) ? $calcWidth : $width;
            $calcHeight = $calcWidth / $ratio;

            try {
                $pixels = Blurhash::decode($rawBlurhash, $calcWidth, $calcHeight);
            } catch (\Exception $e) {
                $this->errors("Error while decoding Blurhash", Notice::log);
            }

            if ($pixels) {
                $image = imagecreatetruecolor($calcWidth, $calcHeight);

                for ($y = 0; $y < $height; ++$y) {
                    for ($x = 0; $x < $width; ++$x) {
                        [$r, $g, $b] = $pixels[$y][$x];
                        if ($r > 255) {
                            $r = 255;
                        }
                        if ($g > 255) {
                            $g = 255;
                        }
                        if ($b > 255) {
                            $b = 255;
                        }
                        if ($r < 0) {
                            $r = 0;
                        }
                        if ($g < 0) {
                            $g = 0;
                        }
                        if ($b < 0) {
                            $b = 0;
                        }
                        $allocate = imagecolorallocate($image, $r, $g, $b);
                        imagesetpixel($image, $x, $y, $allocate);
                    }
                }

                $image = imagescale($image, $width, -1);

                ob_start();
                imagepng($image);
                $contents = ob_get_contents();
                ob_end_clean();

                $dataUri = "data:image/png;base64," . base64_encode($contents);
                imagedestroy($image);

                return $dataUri;
            }
        }

        return $blankFallbackGif;
    }

    public function insertBlurhash(string $blurhash, string $fieldName, string $imageName)
    {
        $db = $this->wire('database');

        $col = "blurhash";
        $table = "field_{$fieldName}";

        try {
            $query = $db->prepare("UPDATE `$table` SET $col=:blurhash WHERE data='$imageName'");
            $query->bindValue(":blurhash", $blurhash);
            $query->execute();
            return true;
        } catch (\Exception $e) {
            $this->errors($e->getMessage(), Notice::log);
            return false;
        }
    }

    public function checkBlurhashTableColumn(string $fieldName)
    {
        $db = $this->wire('database');

        $col = "blurhash";
        $table = "field_{$fieldName}";

        $sql = "SHOW COLUMNS FROM `$table` LIKE '$col'";
        try {
            $query = $db->prepare($sql);
            $query->execute();
            $numRows = (int) $query->rowCount();
            $query->closeCursor();
        } catch (\Exception $e) {
            $this->errors($e->getMessage(), Notice::log);
            return false;
        }

        if (empty($numRows)) {
            $addColumn = "ALTER TABLE `{$table}` ADD `{$col}` VARCHAR(200) DEFAULT ''";
            try {
                $db->exec($addColumn);
                $this->message("Added column '{$col}' for '{$table}'", Notice::log);
            } catch (\Exception $e) {
                $this->errors($e->getMessage(), Notice::log);
                return false;
            }

            try {
                $date = date('Y-m-d H:i:s');
                $query = $db->prepare("UPDATE `$table` SET created=:created, modified=:modified");
                $query->bindValue(":created", $date);
                $query->bindValue(":modified", $date);
                $query->execute();
                $this->message("Updated created/modified for '{$table}'", Notice::log);
            } catch (\Exception $e) {
                $this->errors($e->getMessage(), Notice::log);
            }
        }

        return true;
    }

    public function createBlurhash(string $file, float $compX = 4, float $compY = 3)
    {
        $blurhash = null;

        if ((file_exists($file) && exif_imagetype($file)) || strpos($file, "http") === 0) {

            // optional loading image over HTTP for some special internal cases at Blue Tomato
            // optional loading over curl with http-proxy for some special internal cases at Blue Tomato
            // use of native curl when available, WireHttp (also with "use" => "curl") does not work well with our proxy
            if (strpos($file, "http") === 0) {
                $config = Wire::getFuel("config");
                if (function_exists("curl_version")) {

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_HTTPGET, true);
                    curl_setopt($ch, CURLOPT_URL, $file);
                    if ($config->httpProxy) {
                        curl_setopt($ch, CURLOPT_PROXY, $config->httpProxy);
                    }
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $imageFile = curl_exec($ch);
                    curl_close($ch);
                } else {
                    $http = new WireHttp();
                    $imageFile = $http->get($file, [
                        "proxy" => ($config->httpProxy ?? null)
                    ]);
                }
            } else {
                $imageFile = file_get_contents($file);
            }

            if ($imageFile && !empty($imageFile)) {
                $image = imagecreatefromstring($imageFile);

                // resize image because bigger images break the most php memory limit setting
                $originalWidth = imagesx($image);
                $calcWidth = 200;
                $calcWidth = ($originalWidth > $calcWidth) ? $calcWidth : $originalWidth;
                $image = imagescale($image, $calcWidth, -1);

                $width = imagesx($image);
                $height = imagesy($image);

                $pixels = [];
                for ($y = 0; $y < $height; ++$y) {
                    $row = [];
                    for ($x = 0; $x < $width; ++$x) {
                        $index = imagecolorat($image, $x, $y);
                        $colors = imagecolorsforindex($image, $index);
                        $r = $colors['red'];
                        $g = $colors['green'];
                        $b = $colors['blue'];

                        if ($r > 255) {
                            $r = 255;
                        }
                        if ($g > 255) {
                            $g = 255;
                        }
                        if ($b > 255) {
                            $b = 255;
                        }
                        if ($r < 0) {
                            $r = 0;
                        }
                        if ($g < 0) {
                            $g = 0;
                        }
                        if ($b < 0) {
                            $b = 0;
                        }

                        $row[] = [$r, $g, $b];
                    }
                    $pixels[] = $row;
                }
                try {
                    $blurhash = Blurhash::encode($pixels, $compX, $compY);
                } catch (\Exception $e) {
                    $this->errors("Error while Encoding Blurhash", Notice::log);
                }
            } else {
                $this->errors("Error, loaded Image is empty", Notice::log);
            }
        } else {
            $this->errors("Image File does not exist in the Filepath", Notice::log);
        }

        return $blurhash;
    }
}

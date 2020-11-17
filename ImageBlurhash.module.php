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
            'version' => 204,
            'summary' => 'Create Blurhash Strings during image upload',
            'singular' => true,
            'autoload' => true,
            'requires' => array('PHP>=7.0', 'ProcessWire>=3.0.155', 'FieldtypeImage')
        );
    }

    public function init()
    {
        $this->addHookAfter('FieldtypeImage::getConfigInputfields', $this, 'hookGetConfigInputFields');
        $this->addHookAfter('FieldtypeImage::savePageField', $this, 'hookSavePageField');

        $this->addHookProperty('Pageimage::blurhash', function (HookEvent $event) {
            $image = $event->object;
            $event->return = $this->getRawBlurhash($image);
        });

        $this->addHookMethod('Pageimage::getBlurhashDataUri', function (HookEvent $event) {
            $image = $event->object;
            $width = $event->arguments(0);
            $height = $event->arguments(1);
            $event->return = $this->getDecodedBlurhash($image, $width, $height);
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
            $image = $images->last(); // get the last added images (should be the currently uploaded images)
            if (!$this->getRawBlurhash($image)) {
                if ($blurhash = $this->createBlurhash($image->filename)) {
                    $this->insertBlurhash($blurhash, $page, $field, $image);
                }
            }
        }
    }

    public function getRawBlurhash(Pageimage $image)
    {
        $blurhash = $image->filedata("blurhash");
        if ($blurhash && !empty($blurhash)) {
            return $blurhash;
        }

        return false;
    }

    public function getDecodedBlurhash(Pageimage $image, float $width = 0, float $height = 0)
    {
        $blankFallbackGif = "data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==";
        $rawBlurhash = $this->getRawBlurhash($image);
        if ($rawBlurhash && $width > 0 && $height > 0) {

            $ratio =  $width / $height;
            $calcWidth = 200;
            $calcWidth = ($width > $calcWidth) ? $calcWidth : $width;
            $calcHeight = $calcWidth / $ratio;
            
            $calcWidth = floor($calcWidth);
            $calcHeight = floor($calcHeight);

            try {
                $pixels = Blurhash::decode($rawBlurhash, $calcWidth, $calcHeight);
            } catch (\Exception $e) {
                $this->errors("Error while decoding Blurhash", Notice::log);
            }

            if ($pixels) {
                $image = imagecreatetruecolor($calcWidth, $calcHeight);

                for ($y = 0; $y < $calcHeight; ++$y) {
                    for ($x = 0; $x < $calcWidth; ++$x) {
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

    public function insertBlurhash(string $blurhash, Page $page, Field $field, Pageimage $image)
    {
        $image->filedata("blurhash", $blurhash);
        $page->save($field->name, ["quiet" => true, "noHooks" => true]);
        return true;
    }

    public function createBlurhash(string $file, float $compX = 4, float $compY = 3)
    {
        $blurhash = null;

        if (((file_exists($file) && !is_dir($file)) && exif_imagetype($file)) || strpos($file, "http") === 0) {

            // optional loading image over HTTP for some special internal cases at Blue Tomato
            // optional loading over curl with http-proxy for some special internal cases at Blue Tomato
            // use of native curl when available, WireHttp (also with "use" => "curl") does not work well with our proxy
            if (strpos($file, "http") === 0) {
                $config = $this->wire("config");
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
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpcode !== 200) {
                        $imageFile = false;
                        $this->errors("Error, url to image reponded with http status: $httpcode", Notice::log);
                    }
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

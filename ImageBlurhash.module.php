<?php

namespace ProcessWire;

use kornrunner\Blurhash\Blurhash;

final class ImageBlurhash extends InputfieldImage implements Module
{
    public static function getModuleInfo(): array
    {
        return [
            'title'       => 'ImageBlurhash',
            'version'     => 208,
            'author'      => 'Blue Tomato',
            'summary'     => 'Generate Blurhash strings on image upload (optimized for PHP 8.3 & PW 3.0.248)',
            'autoload'    => true,
            'requires'    => ['PHP>=8.3', 'ProcessWire>=3.0.248', 'FieldtypeImage'],
        ];
    }

    public function init(): void
    {
        $this->addHookAfter('FieldtypeImage::getConfigInputfields', $this, 'hookGetConfigInputFields');
        $this->addHookAfter('FieldtypeImage::savePageField', $this, 'hookSavePageField');

        $this->addHookProperty(
            'Pageimage::blurhash',
            fn(HookEvent $event): ?string => $this->getRawBlurhash($event->object)
        );

        $this->addHookMethod(
            'Pageimage::getBlurhashDataUri',
            fn(HookEvent $event): string => $this->getDecodedBlurhash(
                $event->object,
                (float) ($event->arguments(0) ?? 0.0),
                (float) ($event->arguments(1) ?? 0.0)
            )
        );
    }

    protected function hookGetConfigInputFields(HookEvent $event): void
    {
        $field   = $event->arguments(0);
        $wrapper = $event->return;

        $radios = $this->wire('modules')->get('InputfieldRadios');
        $radios->label = $this->_('Generate Blurhash strings');
        $radios->attr('name', 'createBlurhash');
        $radios->addOption(1, $this->_('Yes'));
        $radios->addOption(0, $this->_('No'));
        $radios->attr('value', (int) $field->createBlurhash);

        $first = $wrapper->get('children')->first();
        $wrapper->insertAfter($radios, $first);

        if ((bool) $field->createBlurhash) {
            $wrapper->remove('defaultValue');
        }
    }

    protected function hookSavePageField(HookEvent $event): void
    {
        $page  = $event->arguments(0);
        $field = $event->arguments(1);

        if (empty($field->createBlurhash)) {
            return;
        }

        $images = $page->get($field->name);
        if ($page->hasStatus(Page::statusDeleted) || $images->count() === 0) {
            return;
        }

        $lastImage = $images->last();
        if ($this->getRawBlurhash($lastImage) === null) {
            $hash = $this->createBlurhash($lastImage);
            if ($hash !== null) {
                $this->insertBlurhash($hash, $page, $field, $lastImage);
            }
        }
    }

    public function getRawBlurhash(Pageimage $image): ?string
    {
        $hash = $image->filedata('blurhash');
        return $hash !== '' ? $hash : null;
    }

    public function getDecodedBlurhash(Pageimage $image, float $width = 0.0, float $height = 0.0): string
    {
        $blankGif = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
        if ($width <= 0 || $height <= 0) {
            return $blankGif;
        }

        $raw = $this->getRawBlurhash($image);
        if ($raw === null) {
            return $blankGif;
        }

        $ratio     = $width / $height;
        $maxSample = 200;
        $w         = min((int) $width, $maxSample);
        $h         = (int) floor($w / $ratio);

        try {
            $pixels = Blurhash::decode($raw, $w, $h);
        } catch (\Throwable $e) {
            $this->errors("Blurhash decode error: {$e->getMessage()}", Notice::log);
            return $blankGif;
        }

        $im = imagecreatetruecolor($w, $h);
        foreach ($pixels as $y => $row) {
            foreach ($row as $x => list($r, $g, $b)) {
                $col = imagecolorallocate(
                    $im,
                    max(0, min(255, $r)),
                    max(0, min(255, $g)),
                    max(0, min(255, $b))
                );
                imagesetpixel($im, $x, $y, $col);
            }
        }

        $scaled = imagescale($im, (int) $width, -1);
        ob_start();
        imagepng($scaled);
        imagedestroy($scaled);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    protected function insertBlurhash(string $hash, Page $page, Field $field, Pageimage $image): void
    {
        $image->filedata('blurhash', $hash);
        $page->save($field->name, ['quiet' => true, 'noHooks' => true]);
    }

    protected function createBlurhash(Pageimage $image, float $compX = 4.0, float $compY = 3.0): ?string
    {
        $files = $image->getFiles();

        $basename = $image->name;
        $path     = $files[$basename] ?? reset($files);

        if (empty($path) || !is_file($path)) {
            $this->errors("Cannot load image file for Blurhash: {$basename}", Notice::log);
            return null;
        }

        $data = @file_get_contents($path);
        if (empty($data) || !($img = @imagecreatefromstring($data))) {
            $this->errors("Invalid image data for {$basename}", Notice::log);
            return null;
        }

        $wOrig = imagesx($img);
        $w     = min(200, $wOrig);
        $img   = imagescale($img, $w, -1);
        $h     = imagesy($img);

        $pixels = [];
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                $pixels[$y][] = [$c['red'], $c['green'], $c['blue']];
            }
        }
        imagedestroy($img);

        try {
            return Blurhash::encode($pixels, $compX, $compY);
        } catch (\Throwable $e) {
            $this->errors("Blurhash encode error: {$e->getMessage()}", Notice::log);
            return null;
        }
    }
}

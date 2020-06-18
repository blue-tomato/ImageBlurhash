# ImageBlurhash

ImageBlurhash is a module for ProcessWire CMS which automatically generates Blurhash strings for uploaded images.

## What is Blurhash?

> BlurHash is a compact representation of a placeholder for an image. E.g. used for lazy loading placeholders.

More about Blurhash itself:

* [https://blurha.sh/](https://blurha.sh/)
* https://github.com/woltapp/blurhash

## Usage

### Installation

1. Execute the following command in the root directory of your ProcessWire installation:

```bash
composer require blue-tomato/image-blurhash
```

2. ProcessWire will detect the module and list it in the backend's `Modules` > `Site` > `ImageBlurhash` section. Navigate there and install it.

### Configuration

Activate Blurhash in the field setting of the image:  `Setup` > `Fields` > `image_field` > `Details` > `Generate Blurhash Strings`

### API

```php
$page->image_field->blurhash
```

Return's the encoded Blurhash as string or false if not existing

---

```php
$page->image_field->getBlurhashDataUri(float $width, float $height)
```

E.g.
```php
<img src="$page->image_field->getBlurhashDataUri(500, 300)" alt="" data-lazy="$page->image_field->size(500, 300)" />
```

Returns the decoded Blurhash as base64 PNG datauri for usage in an image. If not existing transparent GIF image will be returned.

Hint: If your image is 500x300 pixels, you can use 50x30 for the Blurhash Data-URI and and scale up the image with CSS. This makes Blurhash decoding faster, the data-uri smaller but the quality is still good.

### Migration of existing images

For migration of existing fields there are two CLI script in the module directory

#### regenerateImages.php

E.g. 
```bash 
php regenerateImages.php
```

Generates for all image fields who have the createBlurhash option new Blurhashs.

#### generateEmptyImages.php

E.g.
```bash
php generateEmptyImages.php
```

Generates for all image fields who have the createBlurhash option and have no Blurhash in the database a new Blurhash.


## Roadmap

Currently encoding component quality default to 4x3. In the future this value will be configurable over the field settings for each field.

## Support

Please [open an issue](https://github.com/blue-tomato/ImageBlurhash/issues/new) for support.

## Contributing

Create a branch on your fork, add commits to your fork, and open a pull request from your fork to this repository.

To get better insights and onboard you on module implementation details just open a support issue. We'll get back to you asap.

## License

Find all information about this module's license in the LICENCE.txt file.


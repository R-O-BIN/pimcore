<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Image\Adapter;

use Jcupitt\Vips;
use Pimcore\Image\Adapter;
use function rename;
use function strtolower;

class Libvips extends Adapter
{
    protected string $path;

    /**
     * @var null|Vips\Image
     */
    protected mixed $resource = null;

    public function load(string $imagePath, array $options = []): static|false
    {
        $this->path = $imagePath;
        $this->resource = Vips\Image::newFromFile($this->path);

        // set dimensions
        $this->setWidth($this->resource->width);
        $this->setHeight($this->resource->height);

        if (!$this->sourceImageFormat) {
            $this->sourceImageFormat = pathinfo($imagePath, PATHINFO_EXTENSION);
        }

        if (in_array(pathinfo($imagePath, PATHINFO_EXTENSION), ['png', 'gif'])) {
            // in GD only gif and PNG can have an alphachannel
            $this->setIsAlphaPossible(true);
        }

        $this->setModified(false);

        return $this;
    }

    public function getContentOptimizedFormat(): string
    {
        $format = 'pjpeg';
        if ($this->hasAlphaChannel()) {
            $format = 'png';
        }

        return $format;
    }

    public function save(string $path, string $format = null, int $quality = null): static
    {
        $format = strtolower($format);

        if (!$format || $format == 'png32') {
            $format = 'png';
        }

        if ($format == 'original') {
            $format = $this->sourceImageFormat;
        }

        $savePath = $path . "." . $format;

        $this->resource->writeToFile($savePath, [
            'Q' => $quality,
        ]);

        rename($savePath, $path);

        return $this;
    }

    private function hasAlphaChannel(): bool
    {
        if ($this->isAlphaPossible) {
            return $this->resource->hasAlpha();
        }

        return false;
    }

    protected function destroy(): void
    {

    }

    public function resize(int $width, int $height): static
    {
        $this->preModify();

        $scaleWidth = $width / $this->resource->width;
        $scaleHeight = $height / $this->resource->height;

        // Resize the image with separate scales for width and height
        $resizedImage = $this->resource->resize($scaleWidth, [
            'vscale' => $scaleHeight
        ]);

        $this->resource = $resizedImage;

        $this->postModify();

        return $this;
    }

    public function crop(int $x, int $y, int $width, int $height): static
    {
        $this->preModify();

        $x = min($this->getWidth(), max(0, $x));
        $y = min($this->getHeight(), max(0, $y));
        $width = min($width, $this->getWidth() - $x);
        $height = min($height, $this->getHeight() - $y);

        $this->resource = $this->resource->crop($x, $y, $width, $height);

        $this->setWidth($width);
        $this->setHeight($height);

        $this->postModify();

        return $this;
    }

    private function ensureBands(Vips\Image $image, int $bands): Vips\Image
    {
        if ($image->bands === $bands) {
            return $image;
        } elseif ($image->bands < $bands) {
            while ($image->bands < $bands) {
                $image = $image->bandjoin(255);
            }

            return $image;
        } else {
            return $image->extract_band(0, ['n' => 4]);
        }
    }

    public function frame(int $width, int $height, bool $forceResize = false): static
    {
        $this->preModify();

        $this->contain($width, $height, $forceResize);

        $this->resource = $this->ensureBands($this->resource, 4);

        $x = (int)(($width - $this->getWidth()) / 2);
        $y = (int)(($height - $this->getHeight()) / 2);

        $canvas = Vips\Image::black($width, $height)->copy(['interpretation' => 'srgb']);
        $canvas = $this->ensureBands($canvas, 4);

        // Composite the resized image onto the transparent canvas
        $finalImage = $canvas->insert($this->resource, $x, $y);
        $this->resource = $finalImage;

        $this->setWidth($width);
        $this->setHeight($height);

        $this->postModify();

        $this->setIsAlphaPossible(true);

        return $this;
    }

    public function setBackgroundColor(string $color): static
    {
        $this->preModify();

        [$r, $g, $b] = $this->colorhex2colorarray($color);

        // just imagefill() on the existing image doesn't work, so we have to create a new image, fill it and then merge
        // the source image with the background-image together
        $newImg = imagecreatetruecolor($this->getWidth(), $this->getHeight());
        $color = imagecolorallocate($newImg, $r, $g, $b);
        imagefill($newImg, 0, 0, $color);

        imagecopy($newImg, $this->resource, 0, 0, 0, 0, $this->getWidth(), $this->getHeight());
        $this->resource = $newImg;

        $this->postModify();

        $this->setIsAlphaPossible(false);

        return $this;
    }

    public function setBackgroundImage(string $image, string $mode = null): static
    {
        $this->preModify();

        $image = ltrim($image, '/');
        $image = PIMCORE_WEB_ROOT . '/' . $image;

        if (is_file($image)) {
            $backgroundImage = imagecreatefromstring(file_get_contents($image));
            [$backgroundImageWidth, $backgroundImageHeight] = getimagesize($image);

            $newImg = $this->createImage($this->getWidth(), $this->getHeight());

            if ($mode == 'cropTopLeft') {
                imagecopyresampled($newImg, $backgroundImage, 0, 0, 0, 0, $this->getWidth(), $this->getHeight(), $this->getWidth(), $this->getHeight());
            } elseif ($mode == 'asTexture') {
                imagesettile($newImg, $backgroundImage);
                imagefilledrectangle($newImg, 0, 0, $this->getWidth(), $this->getHeight(), IMG_COLOR_TILED);
            } else {
                // default behavior (fit)
                imagecopyresampled($newImg, $backgroundImage, 0, 0, 0, 0, $this->getWidth(), $this->getHeight(), $backgroundImageWidth, $backgroundImageHeight);
            }

            imagealphablending($newImg, true);
            imagecopyresampled($newImg, $this->resource, 0, 0, 0, 0, $this->getWidth(), $this->getHeight(), $this->getWidth(), $this->getHeight());

            $this->resource = $newImg;
        }

        $this->postModify();

        return $this;
    }

    public function grayscale(): static
    {
        $this->preModify();

        imagefilter($this->resource, IMG_FILTER_GRAYSCALE);

        $this->postModify();

        return $this;
    }

    public function sepia(): static
    {
        $this->preModify();

        imagefilter($this->resource, IMG_FILTER_GRAYSCALE);
        imagefilter($this->resource, IMG_FILTER_COLORIZE, 100, 50, 0);

        $this->postModify();

        return $this;
    }

    public function addOverlay(mixed $image, int $x = 0, int $y = 0, int $alpha = 100, string $composite = 'COMPOSITE_DEFAULT', string $origin = 'top-left'): static
    {
        $this->preModify();

        $image = ltrim($image, '/');
        $image = PIMCORE_PROJECT_ROOT . '/' . $image;

        if (is_file($image)) {
            [$oWidth, $oHeight] = getimagesize($image);

            if ($origin === 'top-right') {
                $x = $this->getWidth() - $oWidth - $x;
            } elseif ($origin === 'bottom-left') {
                $y = $this->getHeight() - $oHeight - $y;
            } elseif ($origin === 'bottom-right') {
                $x = $this->getWidth() - $oWidth - $x;
                $y = $this->getHeight() - $oHeight - $y;
            } elseif ($origin === 'center') {
                $x = round($this->getWidth() / 2) - round($oWidth / 2) + $x;
                $y = round($this->getHeight() / 2) - round($oHeight / 2) + $y;
            }

            $overlay = imagecreatefromstring(file_get_contents($image));
            imagealphablending($this->resource, true);
            imagecopyresampled($this->resource, $overlay, $x, $y, 0, 0, $oWidth, $oHeight, $oWidth, $oHeight);
        }

        $this->postModify();

        return $this;
    }

    public function mirror(string $mode): static
    {
        $this->preModify();

        if ($mode == 'vertical') {
            imageflip($this->resource, IMG_FLIP_VERTICAL);
        } elseif ($mode == 'horizontal') {
            imageflip($this->resource, IMG_FLIP_HORIZONTAL);
        }

        $this->postModify();

        return $this;
    }

    public function rotate(int $angle): static
    {
        $this->preModify();
        $angle = 360 - $angle;
        $this->resource = imagerotate($this->resource, $angle, imagecolorallocatealpha($this->resource, 0, 0, 0, 127));

        $this->setWidth(imagesx($this->resource));
        $this->setHeight(imagesy($this->resource));

        $this->postModify();

        $this->setIsAlphaPossible(true);

        return $this;
    }

    /**
     * @var array<string, bool>
     */
    protected static array $supportedFormatsCache = [];

    public function supportsFormat(string $format, bool $force = false): bool
    {
        if (!isset(self::$supportedFormatsCache[$format]) || $force) {
            $info = gd_info();
            $mappings = [
                'jpg' => 'JPEG Support',
                'jpeg' => 'JPEG Support',
                'pjpeg' => 'JPEG Support',
                'webp' => 'WebP Support',
                'gif' => 'GIF Create Support',
                'png' => 'PNG Support',
            ];

            if (isset($mappings[$format]) && isset($info[$mappings[$format]]) && $info[$mappings[$format]]) {
                self::$supportedFormatsCache[$format] = true;
            } else {
                self::$supportedFormatsCache[$format] = false;
            }
        }

        return self::$supportedFormatsCache[$format];
    }
}

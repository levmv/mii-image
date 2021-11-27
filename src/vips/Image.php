<?php /** @noinspection SpellCheckingInspection */
declare(strict_types=1);

namespace mii\image\vips;

use Jcupitt\Vips\Exception;
use Jcupitt\Vips\Interpretation;
use Jcupitt\Vips\Size;
use RuntimeException;

class Image extends \mii\image\Image
{
    // Temporary image resource
    protected ?\Jcupitt\Vips\Image $image = null;

    /**
     * Loads an image into vips.
     *
     * @return  void
     */
    protected function loadImage(): void
    {
        if ($this->image instanceof \Jcupitt\Vips\Image) {
            return;
        }

        $options = [];

        switch ($this->type) {
            case IMAGETYPE_JPEG:
                $create = 'jpegload';
                $options = ['autorotate' => true];
                break;
            case IMAGETYPE_WEBP:
                $create = 'webpload';
                break;
            case IMAGETYPE_GIF:
                $create = 'gifload';
                break;
            case IMAGETYPE_PNG:
                $create = 'pngload';
                break;
            default:
                $create = 'newFromFile';
        }

        if (!isset($create)) {
            throw new RuntimeException('Installed vips does not support ' . image_type_to_extension($this->type, false) . ' images');
        }

        // Open the temporary image
        $this->image = \Jcupitt\Vips\Image::$create($this->file, $options);
        $this->width = $this->image->width;
        $this->height = $this->image->height;

        try {
            $this->image = $this->image->icc_import(['embedded' => true]);

            if ($this->image->interpretation !== Interpretation::B_W && $this->image->interpretation !== Interpretation::GREY16) {
                $this->image->colourspace(Interpretation::SRGB);
            }
        } catch (\Throwable) {}
    }

    /**
     * Execute a resize.
     *
     * @param integer $width new width
     * @param integer $height new height
     * @return  void
     * @throws \Jcupitt\Vips\Exception
     */
    protected function doResize(int $width, int $height): void
    {
        $this->loadImage();

        $this->image = $this->image->thumbnail_image($width, ['height' => $height, 'size' => Size::DOWN]);

        $this->width = $this->image->width;
        $this->height = $this->image->height;
    }


    /**
     * Execute a render.
     *
     * @param int $type image type: png, jpg, gif, etc
     * @return  string
     * @throws Exception
     */
    protected function doRender(int $type): string
    {
        $this->loadImage();

        // Get the save function and IMAGETYPE
        list($save, $options) = $this->saveFunction($type);

        $this->type = $type;

        $save .= '_buffer'; // i.e. 'jpegsave_buffer'

        return $this->image->$save($options);
    }

    /**
     * Get the vips saving function, image type and options for this extension.
     *
     * @param int $type
     * @return array save function, IMAGETYPE_* constant, options
     */
    protected function saveFunction(int $type): array
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                $save = 'jpegsave';
                $options = $this->needStrip
                    ? ['Q' => $this->quality, 'strip' => true, 'optimize_coding' => true]
                    : ['Q' => $this->quality];
                break;
            case IMAGETYPE_WEBP:
                $save = 'webpsave';
                $options = $this->needStrip
                    ? ['Q' => $this->quality, 'strip' => true, 'optimize_coding' => true]
                    : ['Q' => $this->quality];
                break;
            case IMAGETYPE_PNG:
                $save = 'pngsave';
                // Use a compression level of 9 (does not affect quality!)
                $options = ['compression' => 9];
                break;
            default:
                throw new RuntimeException("Installed vips does not support saving $type images");
        }

        return [$save, $options];
    }

    protected function doCrop(int $width, int $height, int $offsetX, int $offsetY): void
    {

    }

    /**
     * @throws Exception
     */
    protected function doRotate(int $degrees): void
    {
        $this->image = match ($degrees) {
            45 => $this->image->rot45(),
            90 => $this->image->rot90(),
            180 => $this->image->rot180(),
            270 => $this->image->rot270(),
            default => $this->image->rotate($degrees),
        };
    }

    protected function doFlip(int $direction): void
    {

    }

    protected function doSharpen(int $amount): void
    {

    }

    protected function doBlur(int $sigma): void
    {

    }

    protected function doReflection(int $height, int $opacity, bool $fadeIn): void
    {

    }

    protected function doWatermark(\mii\image\Image $image, int $offsetX, int $offsetY, int $opacity): void
    {

    }

    protected function doBackground(int $r, int $g, int $b, int $opacity)
    {

    }

    protected function doSave(string $file, int $type): bool
    {
        $this->loadImage();

        list($save, $options) = $this->saveFunction($type);

        $this->type = $type;

        return $this->image->$save($file, $options);
    }

    protected function doBlank(int $width, int $height, array $background): void
    {

    }

    public function doCopy()
    {

    }
}

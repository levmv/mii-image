<?php /** @noinspection SpellCheckingInspection */
/** @noinspection PhpComposerExtensionStubsInspection */
declare(strict_types=1);

namespace mii\image\gd;


use GdImage;
use mii\image\Image as BaseImage;
use RuntimeException;

class Image extends BaseImage
{
    // Temporary image resource
    protected ?GdImage $_image = null;

    // Function name to open Image
    protected string $_create_function;


    /**
     * Loads an image into GD.
     *
     * @return  void
     */
    protected function loadImage(): void
    {
        if ($this->_image instanceof GdImage) {
            return;
        }

        // Set the image creation function name
        $create = match ($this->type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
        };

        if (!function_exists($create)) {
            throw new RuntimeException('Installed GD does not support "' . image_type_to_extension($this->type, false) . '" images');
        }

        // Open the temporary image
        $this->_image = $create($this->file);

        // Preserve transparency when saving
        imagesavealpha($this->_image, true);
    }

    /**
     * Execute a resize.
     *
     * @param integer $width new width
     * @param integer $height new height
     * @return  void
     */
    protected function doResize(int $width, int $height): void
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Create the temporary image to copy to
        $image = $this->_create($width, $height);

        // Execute the resize
        if (imagecopyresampled($image, $this->_image, 0, 0, 0, 0, $width, $height, $this->width, $this->height)) {
            // Swap the new image for the old one
            $this->_image = $image;

            // Reset the width and height
            $this->width = imagesx($image);
            $this->height = imagesy($image);
        }
    }

    /**
     * Execute a crop.
     *
     * @param integer $width new width
     * @param integer $height new height
     * @param integer $offsetX offset from the left
     * @param integer $offsetY offset from the top
     * @return  void
     */
    protected function doCrop(int $width, int $height, int $offsetX, int $offsetY): void
    {
        // Create the temporary image to copy to
        $image = $this->_create($width, $height);

        // Loads image if not yet loaded
        $this->loadImage();

        // Execute the crop
        if (imagecopyresampled($image, $this->_image, 0, 0, $offsetX, $offsetY, $width, $height, $width, $height)) {
            // Swap the new image for the old one
            $this->_image = $image;

            // Reset the width and height
            $this->width = imagesx($image);
            $this->height = imagesy($image);
        }
    }

    /**
     * Execute a rotation.
     *
     * @param integer $degrees degrees to rotate
     * @return  void
     */
    protected function doRotate(int $degrees): void
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Transparent black will be used as the background for the uncovered region
        $transparent = imagecolorallocatealpha($this->_image, 0, 0, 0, 127);

        // Rotate, setting the transparent color
        $image = imagerotate($this->_image, 360 - $degrees, $transparent, true);

        // Save the alpha of the rotated image
        imagesavealpha($image, true);

        // Get the width and height of the rotated image
        $width = imagesx($image);
        $height = imagesy($image);

        if (imagecopymerge($this->_image, $image, 0, 0, 0, 0, $width, $height, 100)) {
            // Swap the new image for the old one
            $this->_image = $image;

            // Reset the width and height
            $this->width = $width;
            $this->height = $height;
        }
    }

    /**
     * Execute a flip.
     *
     * @param integer $direction direction to flip
     * @return  void
     */
    protected function doFlip(int $direction): void
    {
        // Create the flipped image
        $flipped = $this->_create($this->width, $this->height);

        // Loads image if not yet loaded
        $this->loadImage();

        if ($direction === BaseImage::HORIZONTAL) {
            for ($x = 0; $x < $this->width; $x++) {
                // Flip each row from top to bottom
                imagecopy($flipped, $this->_image, $x, 0, $this->width - $x - 1, 0, 1, $this->height);
            }
        } else {
            for ($y = 0; $y < $this->height; $y++) {
                // Flip each column from left to right
                imagecopy($flipped, $this->_image, 0, $y, 0, $this->height - $y - 1, $this->width, 1);
            }
        }

        // Swap the new image for the old one
        $this->_image = $flipped;

        // Reset the width and height
        $this->width = imagesx($flipped);
        $this->height = imagesy($flipped);
    }

    /**
     * Execute a sharpen.
     *
     * @param integer $amount amount to sharpen
     * @return  void
     */
    protected function doSharpen(int $amount): void
    {
        $this->loadImage();

        // Amount should be in the range of 18-10
        $amount = round(abs(-18 + ($amount * 0.08)), 2);

        // Gaussian blur matrix
        $matrix = [
            [-1, -1, -1],
            [-1, $amount, -1],
            [-1, -1, -1]
        ];

        if (imageconvolution($this->_image, $matrix, $amount - 8, 0)) {
            // Reset the width and height
            $this->width = imagesx($this->_image);
            $this->height = imagesy($this->_image);
        }
    }

    /**
     * Execute a blur.
     * TODO: sigma
     * @param integer $sigma
     * @return  void
     */
    protected function doBlur(int $sigma): void
    {
        $this->loadImage();

        // Gaussian blur matrix
        $matrix = [
            [1, 2, 1],
            [2, 4, 2],
            [1, 2, 1]
        ];

        if (imageconvolution($this->_image, $matrix, 16, 0)) {
            // Reset the width and height
            $this->width = imagesx($this->_image);
            $this->height = imagesy($this->_image);
        }
    }

    /**
     * Execute a reflection.
     *
     * @param integer $height reflection height
     * @param integer $opacity reflection opacity
     * @param boolean $fadeIn true to fade out, false to fade in
     * @return  void
     */
    protected function doReflection(int $height, int $opacity, bool $fadeIn): void
    {
        $this->loadImage();

        // Convert an opacity range of 0-100 to 127-0
        $opacity = round(abs(($opacity * 127 / 100) - 127));

        if ($opacity < 127) {
            // Calculate the opacity stepping
            $stepping = (127 - $opacity) / $height;
        } else {
            // Avoid a "divide by zero" error
            $stepping = 127 / $height;
        }

        // Create the reflection image
        $reflection = $this->_create($this->width, $this->height + $height);

        // Copy the image to the reflection
        imagecopy($reflection, $this->_image, 0, 0, 0, 0, $this->width, $this->height);

        for ($offset = 0; $height >= $offset; $offset++) {
            // Read the next line down
            $src_y = $this->height - $offset - 1;

            // Place the line at the bottom of the reflection
            $dst_y = $this->height + $offset;

            if ($fadeIn === true) {
                // Start with the most transparent line first
                $dst_opacity = round($opacity + ($stepping * ($height - $offset)));
            } else {
                // Start with the most opaque line first
                $dst_opacity = round($opacity + ($stepping * $offset));
            }

            // Create a single line of the image
            $line = $this->_create($this->width, 1);

            // Copy a single line from the current image into the line
            imagecopy($line, $this->_image, 0, 0, 0, $src_y, $this->width, 1);

            // Colorize the line to add the correct alpha level
            imagefilter($line, IMG_FILTER_COLORIZE, 0, 0, 0, $dst_opacity);

            // Copy a line into the reflection
            imagecopy($reflection, $line, 0, $dst_y, 0, 0, $this->width, 1);
        }

        // Swap the new image for the old one
        imagedestroy($this->_image);
        $this->_image = $reflection;

        // Reset the width and height
        $this->width = imagesx($reflection);
        $this->height = imagesy($reflection);
    }

    /**
     * Execute a watermarking.
     *
     * @param BaseImage $image watermarking Image
     * @param integer $offsetX offset from the left
     * @param integer $offsetY offset from the top
     * @param integer $opacity opacity of watermark
     * @return  void
     */
    protected function doWatermark(BaseImage $image, int $offsetX, int $offsetY, int $opacity): void
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Create the watermark image resource
        $overlay = imagecreatefromstring($image->render());

        imagesavealpha($overlay, true);

        // Get the width and height of the watermark
        $width = imagesx($overlay);
        $height = imagesy($overlay);

        if ($opacity < 100) {
            // Convert an opacity range of 0-100 to 127-0
            $opacity = (int) round(abs(($opacity * 127 / 100) - 127));

            // Allocate transparent gray
            $color = imagecolorallocatealpha($overlay, 127, 127, 127, $opacity);

            // The transparent image will overlay the watermark
            imagelayereffect($overlay, IMG_EFFECT_OVERLAY);

            // Fill the background with the transparent color
            imagefilledrectangle($overlay, 0, 0, $width, $height, $color);
        }

        // Alpha blending must be enabled on the background!
        imagealphablending($this->_image, true);

        if (imagecopy($this->_image, $overlay, $offsetX, $offsetY, 0, 0, $width, $height)) {
            // Destroy the overlay image
            imagedestroy($overlay);
        }
    }

    protected function doBlank(int $width, int $height, array $background): void
    {
        $this->_image = imagecreatetruecolor($width, $height);

        // Set background
        $white = imagecolorallocate($this->_image, $background[0], $background[1], $background[2]);
        imagefill($this->_image, 0, 0, $white);

        $this->width = imagesx($this->_image);
        $this->height = imagesy($this->_image);
    }


    protected function doCopy(): GdImage
    {
        // Loads image if not yet loaded
        $this->loadImage();

        $copy = imagecreatetruecolor($this->width, $this->height);
        imagecopy($copy, $this->_image, 0, 0, 0, 0, $this->width, $this->height);

        return $copy;
    }

    /**
     * Execute a background.
     *
     * @param integer $r red
     * @param integer $g green
     * @param integer $b blue
     * @param integer $opacity opacity
     * @return void
     */
    protected function doBackground(int $r, int $g, int $b, int $opacity)
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Convert an opacity range of 0-100 to 127-0
        $opacity = (int) round(abs(($opacity * 127 / 100) - 127));

        // Create a new background
        $background = $this->_create($this->width, $this->height);

        // Allocate the color
        $color = imagecolorallocatealpha($background, $r, $g, $b, $opacity);

        // Fill the image with white
        imagefilledrectangle($background, 0, 0, $this->width, $this->height, $color);

        // Alpha blending must be enabled on the background!
        imagealphablending($background, true);

        // Copy the image onto a white background to remove all transparency
        if (imagecopy($background, $this->_image, 0, 0, 0, 0, $this->width, $this->height)) {
            // Swap the new image for the old one
            imagedestroy($this->_image);
            $this->_image = $background;
        }
    }

    /**
     * Execute a save.
     *
     * @param string $file new image filename
     * @param int $type
     * @return  boolean
     */
    protected function doSave(string $file, int $type): bool
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Get the save function and IMAGETYPE
        list($save, $type) = $this->saveFunction($type);

        // Save the image to a file
        $status = isset($quality) ? $save($this->_image, $file, $quality) : $save($this->_image, $file);

        if ($status === true and $type !== $this->type) {
            // Reset the image type
            $this->type = $type;
        }

        return $status;
    }

    /**
     * Execute a render.
     *
     * @param int $type image type: png, jpg, gif, etc
     * @return  string
     */
    protected function doRender(int $type): string
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Get the save function and IMAGETYPE
        list($save, $quality) = $this->saveFunction($type);

        // Capture the output
        ob_start();

        // Render the image
        $status = $save($this->_image, null, $quality);

        if ($status === true and $type !== $this->type) {
            // Reset the image type
            $this->type = $type;
        }

        return ob_get_clean();
    }

    /**
     * Get the GD saving function and image type for this extension.
     * Also normalizes the quality setting
     *
     * @param int $type
     * @return  array    save function, IMAGETYPE_* constant
     */
    protected function saveFunction(int $type): array
    {
        $quality = $this->quality;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $save = 'imagejpeg';
                break;
            case IMAGETYPE_WEBP:
                $save = 'imagewebp';
                break;
            case IMAGETYPE_GIF:
                $save = 'imagegif';

                // GIFs do not a quality setting
                $quality = null;
                break;
            case IMAGETYPE_PNG:
                $save = 'imagepng';

                // Use a compression level of 9 (does not affect quality!)
                $quality = 9;
                break;
            default:
                throw new RuntimeException("Installed GD does not support ".image_type_to_extension($this->type, false)." images");
        }

        return [$save, $quality];
    }

    /**
     * Create an empty image with the given width and height.
     *
     * @param integer $width image width
     * @param integer $height image height
     */
    protected function _create(int $width, int $height): GdImage
    {
        // Create an empty image
        $image = imagecreatetruecolor($width, $height);

        // Do not apply alpha blending
        imagealphablending($image, false);

        // Save alpha levels
        imagesavealpha($image, true);

        return $image;
    }
}

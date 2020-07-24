<?php declare(strict_types=1);

namespace mii\image\gd;

use mii\image\ImageException;

class Image extends \mii\image\Image
{
    // Temporary image resource
    protected $image;

    // Function name to open Image
    protected string $create_function;

    /**
     * Loads the image.
     *
     * @param string $file image file path or buffer
     * @param bool   $is_buffer
     * @throws ImageException
     * @throws \Exception
     */
    public function __construct(string $file, bool $is_buffer = false)
    {
        if ($is_buffer) {

            $this->image = imagecreatefromstring($file);
            $info = getimagesizefromstring($file);

            $this->width = $info[0];
            $this->height = $info[1];
            $this->type = $info[2];
            $this->mime = image_type_to_mime_type($this->type);

            return;
        }

        parent::__construct($file);

        // Set the image creation function name
        switch ($this->type) {
            case \IMAGETYPE_JPEG:
                $create = 'imagecreatefromjpeg';
                break;
            case \IMAGETYPE_GIF:
                $create = 'imagecreatefromgif';
                break;
            case \IMAGETYPE_PNG:
                $create = 'imagecreatefrompng';
                break;
        }

        if (!isset($create) or !function_exists($create)) {
            throw new ImageException('Installed GD does not support "' . image_type_to_extension($this->type, false) . '" images');
        }

        // Save function for future use
        $this->create_function = $create;

        $this->loadImage();
    }

    /**
     * Destroys the loaded image to free up resources.
     *
     * @return  void
     */
    public function __destruct()
    {
        if (is_resource($this->image)) {
            // Free all resources
            imagedestroy($this->image);
        }
    }

    /**
     * Loads an image into GD.
     *
     * @return  void
     */
    protected function loadImage(): void
    {
        if (!is_resource($this->image)) {
            // Gets create function
            $create = $this->create_function;

            // Open the temporary image
            $this->image = $create($this->file);

            // Preserve transparency when saving
            imagesavealpha($this->image, true);
        }
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
        // Presize width and height
        $pre_width = $this->width;
        $pre_height = $this->height;

        // Loads image if not yet loaded
        $this->loadImage();

        // Test if we can do a resize without resampling to speed up the final resize
        if ($width > ($this->width / 2) and $height > ($this->height / 2)) {
            // The maximum reduction is 10% greater than the final size
            $reduction_width = round($width * 1.1);
            $reduction_height = round($height * 1.1);

            while ($pre_width / 2 > $reduction_width and $pre_height / 2 > $reduction_height) {
                // Reduce the size using an O(2n) algorithm, until it reaches the maximum reduction
                $pre_width /= 2;
                $pre_height /= 2;
            }

            // Create the temporary image to copy to
            $image = $this->create($pre_width, $pre_height);

            if (imagecopyresized($image, $this->image, 0, 0, 0, 0, $pre_width, $pre_height, $this->width, $this->height)) {
                // Swap the new image for the old one
                imagedestroy($this->image);
                $this->image = $image;
            }
        }

        // Create the temporary image to copy to
        $image = $this->create($width, $height);

        // Execute the resize
        if (imagecopyresampled($image, $this->image, 0, 0, 0, 0, $width, $height, $pre_width, $pre_height)) {
            // Swap the new image for the old one
            imagedestroy($this->image);
            $this->image = $image;

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
     * @param integer $offset_x offset from the left
     * @param integer $offset_y offset from the top
     * @return  void
     */
    protected function doCrop(int $width, int $height, int $offset_x, int $offset_y): void
    {
        // Create the temporary image to copy to
        $image = $this->create($width, $height);

        // Loads image if not yet loaded
        $this->loadImage();

        // Execute the crop
        if (imagecopyresampled($image, $this->image, 0, 0, $offset_x, $offset_y, $width, $height, $width, $height)) {
            // Swap the new image for the old one
            imagedestroy($this->image);
            $this->image = $image;

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
        $transparent = imagecolorallocatealpha($this->image, 0, 0, 0, 127);

        // Rotate, setting the transparent color
        $image = imagerotate($this->image, 360 - $degrees, $transparent, 1);

        // Save the alpha of the rotated image
        imagesavealpha($image, true);

        // Get the width and height of the rotated image
        $width = imagesx($image);
        $height = imagesy($image);

        if (imagecopymerge($this->image, $image, 0, 0, 0, 0, $width, $height, 100)) {
            // Swap the new image for the old one
            imagedestroy($this->image);
            $this->image = $image;

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
        $flipped = $this->create($this->width, $this->height);

        // Loads image if not yet loaded
        $this->loadImage();

        if ($direction === Image::HORIZONTAL) {
            for ($x = 0; $x < $this->width; $x++) {
                // Flip each row from top to bottom
                imagecopy($flipped, $this->image, $x, 0, $this->width - $x - 1, 0, 1, $this->height);
            }
        } else {
            for ($y = 0; $y < $this->height; $y++) {
                // Flip each column from left to right
                imagecopy($flipped, $this->image, 0, $y, 0, $this->height - $y - 1, $this->width, 1);
            }
        }

        // Swap the new image for the old one
        imagedestroy($this->image);
        $this->image = $flipped;

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
        // Loads image if not yet loaded
        $this->loadImage();

        // Amount should be in the range of 18-10
        $amount = round(abs(-18 + ($amount * 0.08)), 2);

        // Gaussian blur matrix
        $matrix = [
            [-1, -1, -1],
            [-1, $amount, -1],
            [-1, -1, -1],
        ];

        // Perform the sharpen
        if (imageconvolution($this->image, $matrix, $amount - 8, 0)) {
            // Reset the width and height
            $this->width = imagesx($this->image);
            $this->height = imagesy($this->image);
        }
    }

    /**
     * Execute a blur.
     *
     * @param integer $sigma
     * @return  void
     */
    protected function doBlur(int $sigma): void
    {
        // TODO: sigma
        // Loads image if not yet loaded
        $this->loadImage();

        // Gaussian blur matrix
        $matrix = [
            [1, 2, 1],
            [2, 4, 2],
            [1, 2, 1],
        ];

        // Perform the sharpen
        if (imageconvolution($this->image, $matrix, 16, 0)) {
            // Reset the width and height
            $this->width = imagesx($this->image);
            $this->height = imagesy($this->image);
        }
    }

    /**
     * Execute a reflection.
     *
     * @param integer $height reflection height
     * @param integer $opacity reflection opacity
     * @param boolean $fade_in true to fade out, false to fade in
     * @return  void
     */
    protected function doReflection(int $height, int $opacity, bool $fade_in): void
    {
        // Loads image if not yet loaded
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
        $reflection = $this->create($this->width, $this->height + $height);

        // Copy the image to the reflection
        imagecopy($reflection, $this->image, 0, 0, 0, 0, $this->width, $this->height);

        for ($offset = 0; $height >= $offset; $offset++) {
            // Read the next line down
            $src_y = $this->height - $offset - 1;

            // Place the line at the bottom of the reflection
            $dst_y = $this->height + $offset;

            if ($fade_in === true) {
                // Start with the most transparent line first
                $dst_opacity = round($opacity + ($stepping * ($height - $offset)));
            } else {
                // Start with the most opaque line first
                $dst_opacity = round($opacity + ($stepping * $offset));
            }

            // Create a single line of the image
            $line = $this->create($this->width, 1);

            // Copy a single line from the current image into the line
            imagecopy($line, $this->image, 0, 0, 0, $src_y, $this->width, 1);

            // Colorize the line to add the correct alpha level
            imagefilter($line, \IMG_FILTER_COLORIZE, 0, 0, 0, $dst_opacity);

            // Copy a the line into the reflection
            imagecopy($reflection, $line, 0, $dst_y, 0, 0, $this->width, 1);
        }

        // Swap the new image for the old one
        imagedestroy($this->image);
        $this->image = $reflection;

        // Reset the width and height
        $this->width = imagesx($reflection);
        $this->height = imagesy($reflection);
    }

    /**
     * Execute a watermarking.
     *
     * @param \mii\image\Image $watermark watermarking Image
     * @param integer $offset_x offset from the left
     * @param integer $offset_y offset from the top
     * @param integer $opacity opacity of watermark
     * @return  void
     */
    protected function doWatermark(\mii\image\Image $watermark, int $offset_x, int $offset_y, int $opacity): void
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Create the watermark image resource
        $overlay = imagecreatefromstring($watermark->render());

        imagesavealpha($overlay, true);

        // Get the width and height of the watermark
        $width = imagesx($overlay);
        $height = imagesy($overlay);

        if ($opacity < 100) {
            // Convert an opacity range of 0-100 to 127-0
            $opacity = round(abs(($opacity * 127 / 100) - 127));

            // Allocate transparent gray
            $color = imagecolorallocatealpha($overlay, 127, 127, 127, $opacity);

            // The transparent image will overlay the watermark
            imagelayereffect($overlay, \IMG_EFFECT_OVERLAY);

            // Fill the background with the transparent color
            imagefilledrectangle($overlay, 0, 0, $width, $height, $color);
        }

        // Alpha blending must be enabled on the background!
        imagealphablending($this->image, true);

        if (imagecopy($this->image, $overlay, $offset_x, $offset_y, 0, 0, $width, $height)) {
            // Destroy the overlay image
            imagedestroy($overlay);
        }
    }

    protected function doBlank(int $width, int $height, array $background): void
    {
        $this->image = imagecreatetruecolor($width, $height);

        // Set background
        $white = imagecolorallocate($this->image, $background[0], $background[1], $background[2]);
        imagefill($this->image, 0, 0, $white);

        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);
    }

    protected function doStrip(): \mii\image\Image
    {
        return $this;
    }

    protected function doCopy()
    {
        // Loads image if not yet loaded
        $this->loadImage();

        $copy = imagecreatetruecolor($this->width, $this->height);
        imagecopy($copy, $this->image, 0, 0, 0, 0, $this->width, $this->height);

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
    protected function doBackground(int $r, int $g, int $b, int $opacity): void
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Convert an opacity range of 0-100 to 127-0
        $opacity = round(abs(($opacity * 127 / 100) - 127));

        // Create a new background
        $background = $this->create($this->width, $this->height);

        // Allocate the color
        $color = imagecolorallocatealpha($background, $r, $g, $b, $opacity);

        // Fill the image with white
        imagefilledrectangle($background, 0, 0, $this->width, $this->height, $color);

        // Alpha blending must be enabled on the background!
        imagealphablending($background, true);

        // Copy the image onto a white background to remove all transparency
        if (imagecopy($background, $this->image, 0, 0, 0, 0, $this->width, $this->height)) {
            // Swap the new image for the old one
            imagedestroy($this->image);
            $this->image = $background;
        }
    }

    /**
     * Execute a save.
     *
     * @param string $file new image filename
     * @param integer $quality quality
     * @return  boolean
     * @throws ImageException
     */
    protected function doSave(string $file, int $quality): bool
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Get the extension of the file
        $extension = pathinfo($file, \PATHINFO_EXTENSION);

        // Get the save function and IMAGETYPE
        [$save, $type] = $this->saveFunction($extension, $quality);

        // Save the image to a file
        $status = isset($quality) ? $save($this->image, $file, $quality) : $save($this->image, $file);

        if ($status === true and $type !== $this->type) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);
        }

        return true;
    }

    /**
     * Execute a render.
     *
     * @param string $type image type: png, jpg, gif, etc
     * @param integer $quality quality
     * @return  string
     * @throws ImageException
     */
    protected function doRender(string $type, int $quality): string
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Get the save function and IMAGETYPE
        [$save, $type] = $this->saveFunction($type, $quality);

        // Capture the output
        ob_start();

        // Render the image
        $status = isset($quality) ? $save($this->image, null, $quality) : $save($this->image, null);

        if ($status === true and $type !== $this->type) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);
        }

        return ob_get_clean();
    }

    /**
     * Get the GD saving function and image type for this extension.
     * Also normalizes the quality setting
     *
     * @param string $extension image type: png, jpg, etc
     * @param integer $quality image quality
     * @return  array    save function, IMAGETYPE_* constant
     * @throws  ImageException
     */
    protected function saveFunction(string $extension, int &$quality): array
    {
        if (!$extension) {
            // Use the current image type
            $extension = image_type_to_extension($this->type, false);
        }

        switch (strtolower($extension)) {
            case 'jpg':
            case 'jpe':
            case 'jpeg':
                // Save a JPG file
                $save = 'imagejpeg';
                $type = \IMAGETYPE_JPEG;
                break;
            case 'gif':
                // Save a GIF file
                $save = 'imagegif';
                $type = \IMAGETYPE_GIF;

                // GIFs do not a quality setting
                $quality = null;
                break;
            case 'png':
                // Save a PNG file
                $save = 'imagepng';
                $type = \IMAGETYPE_PNG;

                // Use a compression level of 9 (does not affect quality!)
                $quality = 9;
                break;
            default:
                throw new ImageException("Installed GD does not support image_type_to_extension($this->type, false) images");
                break;
        }

        return [$save, $type];
    }

    /**
     * Create an empty image with the given width and height.
     *
     * @param integer $width image width
     * @param integer $height image height
     * @return  resource
     */
    protected function create(int $width, int $height)
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

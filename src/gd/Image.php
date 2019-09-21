<?php

namespace levmorozov\image\gd;

use levmorozov\image\ImageException;

class Image extends \levmorozov\image\Image
{
    // Temporary image resource
    protected $_image;

    // Function name to open Image
    protected $_create_function;

    /**
     * Loads the image.
     *
     * @param   string $file image file path
     * @throws ImageException
     * @throws \Exception
     */
    public function __construct($file)
    {
        parent::__construct($file);

        // Set the image creation function name
        switch ($this->type) {
            case IMAGETYPE_JPEG:
                $create = 'imagecreatefromjpeg';
                break;
            case IMAGETYPE_GIF:
                $create = 'imagecreatefromgif';
                break;
            case IMAGETYPE_PNG:
                $create = 'imagecreatefrompng';
                break;
        }

        if (!isset($create) OR !function_exists($create)) {
            throw new ImageException('Installed GD does not support ".image_type_to_extension($this->type, false)." images');
        }

        // Save function for future use
        $this->_create_function = $create;

        $this->_load_image();
    }

    /**
     * Destroys the loaded image to free up resources.
     *
     * @return  void
     */
    public function __destruct()
    {
        if (is_resource($this->_image)) {
            // Free all resources
            imagedestroy($this->_image);
        }
    }

    /**
     * Loads an image into GD.
     *
     * @return  void
     */
    protected function _load_image()
    {
        if (!is_resource($this->_image)) {
            // Gets create function
            $create = $this->_create_function;

            // Open the temporary image
            $this->_image = $create($this->file);

            // Preserve transparency when saving
            imagesavealpha($this->_image, true);
        }
    }

    /**
     * Execute a resize.
     *
     * @param   integer $width new width
     * @param   integer $height new height
     * @return  void
     */
    protected function _do_resize($width, $height)
    {
        // Presize width and height
        $pre_width = $this->width;
        $pre_height = $this->height;

        // Loads image if not yet loaded
        $this->_load_image();

        // Test if we can do a resize without resampling to speed up the final resize
        if ($width > ($this->width / 2) AND $height > ($this->height / 2)) {
            // The maximum reduction is 10% greater than the final size
            $reduction_width = round($width * 1.1);
            $reduction_height = round($height * 1.1);

            while ($pre_width / 2 > $reduction_width AND $pre_height / 2 > $reduction_height) {
                // Reduce the size using an O(2n) algorithm, until it reaches the maximum reduction
                $pre_width /= 2;
                $pre_height /= 2;
            }

            // Create the temporary image to copy to
            $image = $this->_create($pre_width, $pre_height);

            if (imagecopyresized($image, $this->_image, 0, 0, 0, 0, $pre_width, $pre_height, $this->width, $this->height)) {
                // Swap the new image for the old one
                imagedestroy($this->_image);
                $this->_image = $image;
            }
        }

        // Create the temporary image to copy to
        $image = $this->_create($width, $height);

        // Execute the resize
        if (imagecopyresampled($image, $this->_image, 0, 0, 0, 0, $width, $height, $pre_width, $pre_height)) {
            // Swap the new image for the old one
            imagedestroy($this->_image);
            $this->_image = $image;

            // Reset the width and height
            $this->width = imagesx($image);
            $this->height = imagesy($image);
        }
    }

    /**
     * Execute a crop.
     *
     * @param   integer $width new width
     * @param   integer $height new height
     * @param   integer $offset_x offset from the left
     * @param   integer $offset_y offset from the top
     * @return  void
     */
    protected function _do_crop($width, $height, $offset_x, $offset_y)
    {
        // Create the temporary image to copy to
        $image = $this->_create($width, $height);

        // Loads image if not yet loaded
        $this->_load_image();

        // Execute the crop
        if (imagecopyresampled($image, $this->_image, 0, 0, $offset_x, $offset_y, $width, $height, $width, $height)) {
            // Swap the new image for the old one
            imagedestroy($this->_image);
            $this->_image = $image;

            // Reset the width and height
            $this->width = imagesx($image);
            $this->height = imagesy($image);
        }
    }

    /**
     * Execute a rotation.
     *
     * @param   integer $degrees degrees to rotate
     * @return  void
     */
    protected function _do_rotate($degrees)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Transparent black will be used as the background for the uncovered region
        $transparent = imagecolorallocatealpha($this->_image, 0, 0, 0, 127);

        // Rotate, setting the transparent color
        $image = imagerotate($this->_image, 360 - $degrees, $transparent, 1);

        // Save the alpha of the rotated image
        imagesavealpha($image, true);

        // Get the width and height of the rotated image
        $width = imagesx($image);
        $height = imagesy($image);

        if (imagecopymerge($this->_image, $image, 0, 0, 0, 0, $width, $height, 100)) {
            // Swap the new image for the old one
            imagedestroy($this->_image);
            $this->_image = $image;

            // Reset the width and height
            $this->width = $width;
            $this->height = $height;
        }
    }

    /**
     * Execute a flip.
     *
     * @param   integer $direction direction to flip
     * @return  void
     */
    protected function _do_flip($direction)
    {
        // Create the flipped image
        $flipped = $this->_create($this->width, $this->height);

        // Loads image if not yet loaded
        $this->_load_image();

        if ($direction === Image::HORIZONTAL) {
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
        imagedestroy($this->_image);
        $this->_image = $flipped;

        // Reset the width and height
        $this->width = imagesx($flipped);
        $this->height = imagesy($flipped);
    }

    /**
     * Execute a sharpen.
     *
     * @param   integer $amount amount to sharpen
     * @return  void
     */
    protected function _do_sharpen($amount)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Amount should be in the range of 18-10
        $amount = round(abs(-18 + ($amount * 0.08)), 2);

        // Gaussian blur matrix
        $matrix = [
            [-1,    -1,   -1],
            [-1, $amount, -1],
            [-1,    -1,   -1]
        ];

        // Perform the sharpen
        if (imageconvolution($this->_image, $matrix, $amount - 8, 0)) {
            // Reset the width and height
            $this->width = imagesx($this->_image);
            $this->height = imagesy($this->_image);
        }
    }

    /**
     * Execute a blur.
     *
     * @param   integer $sigma
     * @return  void
     */
    protected function _do_blur($sigma)
    {
        // TODO: sigma
        // Loads image if not yet loaded
        $this->_load_image();

        // Gaussian blur matrix
        $matrix = [
            [1, 2, 1],
            [2, 4, 2],
            [1, 2, 1]
        ];

        // Perform the sharpen
        if (imageconvolution($this->_image, $matrix, 16, 0)) {
            // Reset the width and height
            $this->width = imagesx($this->_image);
            $this->height = imagesy($this->_image);
        }
    }

    /**
     * Execute a reflection.
     *
     * @param   integer $height reflection height
     * @param   integer $opacity reflection opacity
     * @param   boolean $fade_in true to fade out, false to fade in
     * @return  void
     */
    protected function _do_reflection($height, $opacity, $fade_in)
    {
        // Loads image if not yet loaded
        $this->_load_image();

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

            if ($fade_in === true) {
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

            // Copy a the line into the reflection
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
     * @param   \levmorozov\image\Image $watermark watermarking Image
     * @param   integer $offset_x offset from the left
     * @param   integer $offset_y offset from the top
     * @param   integer $opacity opacity of watermark
     * @return  void
     */
    protected function _do_watermark(\levmorozov\image\Image $watermark, $offset_x, $offset_y, $opacity)
    {
        // Loads image if not yet loaded
        $this->_load_image();

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
            imagelayereffect($overlay, IMG_EFFECT_OVERLAY);

            // Fill the background with the transparent color
            imagefilledrectangle($overlay, 0, 0, $width, $height, $color);
        }

        // Alpha blending must be enabled on the background!
        imagealphablending($this->_image, true);

        if (imagecopy($this->_image, $overlay, $offset_x, $offset_y, 0, 0, $width, $height)) {
            // Destroy the overlay image
            imagedestroy($overlay);
        }
    }

    protected function _do_blank($width, $height, $background)
    {
        $this->_image = imagecreatetruecolor($width, $height);

        // Set background
        $white = imagecolorallocate($this->_image, $background[0], $background[1], $background[2]);
        imagefill($this->_image, 0, 0, $white);

        $this->width = imagesx($this->_image);
        $this->height = imagesy($this->_image);
    }

    protected function _do_strip()
    {
        return $this;
    }

    protected function _do_copy()
    {
        // Loads image if not yet loaded
        $this->_load_image();

        $copy = imagecreatetruecolor($this->width, $this->height);
        imagecopy($copy, $this->_image, 0, 0, 0, 0, $this->width, $this->height);

        return $copy;
    }

    /**
     * Execute a background.
     *
     * @param   integer $r red
     * @param   integer $g green
     * @param   integer $b blue
     * @param   integer $opacity opacity
     * @return void
     */
    protected function _do_background($r, $g, $b, $opacity)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Convert an opacity range of 0-100 to 127-0
        $opacity = round(abs(($opacity * 127 / 100) - 127));

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
     * @param   string $file new image filename
     * @param   integer $quality quality
     * @return  boolean
     * @throws ImageException
     */
    protected function _do_save($file, $quality)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Get the extension of the file
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        // Get the save function and IMAGETYPE
        list($save, $type) = $this->_save_function($extension, $quality);

        // Save the image to a file
        $status = isset($quality) ? $save($this->_image, $file, $quality) : $save($this->_image, $file);

        if ($status === true AND $type !== $this->type) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);
        }

        return true;
    }

    /**
     * Execute a render.
     *
     * @param   string $type image type: png, jpg, gif, etc
     * @param   integer $quality quality
     * @return  string
     * @throws ImageException
     */
    protected function _do_render($type, $quality)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Get the save function and IMAGETYPE
        list($save, $type) = $this->_save_function($type, $quality);

        // Capture the output
        ob_start();

        // Render the image
        $status = isset($quality) ? $save($this->_image, null, $quality) : $save($this->_image, null);

        if ($status === true AND $type !== $this->type) {
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
     * @param   string $extension image type: png, jpg, etc
     * @param   integer $quality image quality
     * @return  array    save function, IMAGETYPE_* constant
     * @throws  ImageException
     */
    protected function _save_function($extension, & $quality)
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
                $type = IMAGETYPE_JPEG;
                break;
            case 'gif':
                // Save a GIF file
                $save = 'imagegif';
                $type = IMAGETYPE_GIF;

                // GIFs do not a quality setting
                $quality = null;
                break;
            case 'png':
                // Save a PNG file
                $save = 'imagepng';
                $type = IMAGETYPE_PNG;

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
     * @param   integer $width image width
     * @param   integer $height image height
     * @return  resource
     */
    protected function _create($width, $height)
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
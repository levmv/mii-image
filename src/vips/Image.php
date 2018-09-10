<?php

namespace levmorozov\image\vips;

use Jcupitt\Vips\Size;
use levmorozov\image\ImageException;

class Image extends \levmorozov\image\Image
{
    // Temporary image resource
    /**
     * @var \Jcupitt\Vips\Image $image
     */
    protected $image;

    // Function name to open Image
    protected $_create_function;

    // Options for create function (autorotate for jpeg)
    protected $options = [];

    // Flag for strip metadata when save image
    protected $need_strip;

    /**
     * Checks if vips is enabled.
     *
     * @return  boolean
     * @throws ImageException
     */
    public static function check()
    {
        // TODO
        /*
        if (!function_exists('')) {
            throw new ImageException('vips is either not installed or not enabled, check your configuration');
        }
        */

        return Image::$_checked = true;
    }

    /**
     * Loads the image.
     *
     * @param   string $file image file path
     * @throws ImageException
     * @throws \Exception
     */
    public function __construct($file)
    {
        if (!Image::$_checked) {
            // Run the install check
            Image::check();
        }

        parent::__construct($file);

        $options = [];

        // Set the image creation function name
        switch ($this->type) {
            case IMAGETYPE_JPEG:
                $create = 'jpegload';
                $options = ['autorotate' => true];
                break;
            case IMAGETYPE_GIF:
                $create = 'gifload';
                break;
            case IMAGETYPE_PNG:
                $create = 'pngload';
                break;
        }

        if (!isset($create)) { //  OR !function_exists($create)
            throw new ImageException('Installed vips does not support :type images',
                [':type' => image_type_to_extension($this->type, false)]);
        }

        // Save function and options for future use
        $this->_create_function = $create;
        $this->options = $options;

        $this->_load_image();
    }

    /**
     * Destroys the loaded image to free up resources.
     *
     * @return  void
     */
    public function __destruct()
    {
        // TODO: ?
        if (is_resource($this->image)) {
            // Free all resources
        }
    }

    /**
     * Loads an image into vips.
     *
     * @return  void
     * @throws \Jcupitt\Vips\Exception
     */
    protected function _load_image()
    {
        if (!is_resource($this->image)) {
            // Gets create function
            $create = $this->_create_function;
            // Open the temporary image
            $this->image = \Jcupitt\Vips\Image::$create($this->file, $this->options);
        }
    }

    /**
     * Execute a resize.
     *
     * @param   integer $width new width
     * @param   integer $height new height
     * @return  void
     * @throws \Jcupitt\Vips\Exception
     */
    protected function _do_resize($width, $height)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        $this->image = $this->image->thumbnail_image($width, ['height' => $height, 'size' => Size::DOWN]);

        $this->width = $this->image->width;
        $this->height = $this->image->height;
    }

    protected function _do_strip()
    {
        $this->need_strip = true;
        return $this;
    }

    /**
     * Execute a render.
     *
     * @param   string $type image type: png, jpg, gif, etc
     * @param   integer $quality quality
     * @return  string
     * @throws ImageException
     * @throws \Jcupitt\Vips\Exception
     */
    protected function _do_render($type, $quality)
    {
        // Loads image if not yet loaded
        $this->_load_image();

        // Get the save function and IMAGETYPE
        list($save, $type, $options) = $this->_save_function($type, $quality);

        if ($type !== $this->type) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);
        }

        return $this->image->$save($options);
    }

    /**
     * Get the vips saving function, image type and options for this extension.
     *
     * @param string $extension image type: png, jpg, etc
     * @param int $quality image quality
     * @return array save function, IMAGETYPE_* constant, options
     * @throws ImageException
     */
    protected function _save_function($extension, $quality)
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
                $save = 'jpegsave_buffer';
                $type = IMAGETYPE_JPEG;
                $options = $this->need_strip
                    ? ['Q' => $quality, 'strip' => true, 'optimize_coding' => true]
                    : ['Q' => $quality];
                break;
            case 'png':
                // Save a PNG file
                $save = 'pngsave_buffer';
                $type = IMAGETYPE_PNG;
                // Use a compression level of 9 (does not affect quality!)
                $options = ['compression' => 9];
                $quality = 9;
                break;
            default:
                throw new ImageException('Installed vips does not support saving :type images',
                    [':type' => $extension]);
                break;
        }

        return [$save, $type, $options];
    }

    protected function _do_crop($width, $height, $offset_x, $offset_y)
    {

    }

    protected function _do_rotate($degrees)
    {

    }

    protected function _do_flip($direction)
    {

    }

    protected function _do_sharpen($amount)
    {

    }

    protected function _do_blur($sigma)
    {

    }

    protected function _do_reflection($height, $opacity, $fade_in)
    {

    }

    protected function _do_watermark(\levmorozov\image\Image $image, $offset_x, $offset_y, $opacity)
    {

    }

    protected function _do_background($r, $g, $b, $opacity)
    {

    }

    protected function _do_save($file, $quality)
    {

    }

    protected function _do_blank($width, $height, $background)
    {

    }

    public function _do_copy()
    {

    }
}
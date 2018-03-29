<?php

namespace levmorozov\image\gmagick;

use levmorozov\image\ImageException;

class Image extends \levmorozov\image\Image
{
    /**
     * @var  \Gmagick  image magick object
     */
    protected $im;

    /**
     * Checks if ImageMagick is enabled.
     *
     * @return  boolean
     */
    public static function check()
    {
        return Image::$_checked = true;
    }

    /**
     * Runs Image::check and loads the image.
     *
     * @param $file
     * @throws \Exception
     * @throws \GmagickException
     */
    public function __construct($file)
    {
        if (!Image::$_checked) {
            // Run the install check
            Image::check();
        }

        if ($file === null) {
            $this->im = new \Gmagick;
        } elseif (is_object($file)) {
            $this->im = $file;
            $this->width = $this->im->getimagewidth();
            $this->height = $this->im->getimageheight();

        } else {
            parent::__construct($file);
            $this->im = new \Gmagick($file);
        }
    }

    /**
     * Destroys the loaded image to free up resources.
     *
     * @return  void
     * @throws \GmagickException
     */
    public function __destruct()
    {
        $this->im->clear();
        $this->im->destroy();
    }

    public function get_raw_image()
    {
        return $this->im;
    }

    protected function _do_resize($width, $height)
    {
        if ($this->im->resizeimage($width, $height, \Gmagick::FILTER_LANCZOS, 1.01)) {
            // Reset the width and height
            $this->width = $this->im->getimagewidth();
            $this->height = $this->im->getimageheight();

            return true;
        }

        return false;
    }

    protected function _do_crop($width, $height, $offset_x, $offset_y)
    {
        if ($this->im->cropimage($width, $height, $offset_x, $offset_y)) {
            // Reset the width and height
            $this->width = $this->im->getimagewidth();
            $this->height = $this->im->getimageheight();

            // Trim off hidden areas
            //$this->im->cropimage($this->width, $this->height, 0, 0);

            return true;
        }

        return false;
    }

    protected function _do_rotate($degrees)
    {
        if ($this->im->rotateimage(new \GmagickPixel('transparent'), $degrees)) {
            // Reset the width and height
            $this->width = $this->im->getimagewidth();
            $this->height = $this->im->getimageheight();

            // Trim off hidden areas
            //$this->im->setImagePage($this->width, $this->height, 0, 0);

            return true;
        }

        return false;
    }

    protected function _do_flip($direction)
    {
        if ($direction === Image::HORIZONTAL) {
            return $this->im->flopImage();
        } else {
            return $this->im->flipImage();
        }
    }

    protected function _do_sharpen($amount)
    {
        // IM not support $amount under 5 (0.15)
        $amount = ($amount < 5) ? 5 : $amount;

        // Amount should be in the range of 0.0 to 3.0
        $amount = ($amount * 3.0) / 100;

        return $this->im->sharpenImage(0, $amount);
    }

    protected function _do_blur($sigma)
    {
        return $this->im->blurimage(1, $sigma);
    }

    protected function _do_reflection($height, $opacity, $fade_in)
    {
        // Clone the current image and flip it for reflection
        $reflection = $this->im->clone();
        $reflection->flipImage();

        // Crop the reflection to the selected height
        $reflection->cropImage($this->width, $height, 0, 0);
        $reflection->setImagePage($this->width, $height, 0, 0);

        // Select the fade direction
        $direction = array('transparent', 'black');

        if ($fade_in) {
            // Change the direction of the fade
            $direction = array_reverse($direction);
        }

        // Create a gradient for fading
        $fade = new Imagick;
        $fade->newPseudoImage($reflection->getImageWidth(), $reflection->getImageHeight(), vsprintf('gradient:%s-%s', $direction));

        // Apply the fade alpha channel to the reflection
        $reflection->compositeImage($fade, Imagick::COMPOSITE_DSTOUT, 0, 0);

        // NOTE: Using setImageOpacity will destroy alpha channels!
        $reflection->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity / 100, Imagick::CHANNEL_ALPHA);

        // Create a new container to hold the image and reflection
        $image = new Imagick;
        $image->newImage($this->width, $this->height + $height, new ImagickPixel);

        // Force the image to have an alpha channel
        $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

        // Force the background color to be transparent
        // $image->setImageBackgroundColor(new ImagickPixel('transparent'));

        // Match the colorspace between the two images before compositing
        $image->setColorspace($this->im->getColorspace());

        // Place the image and reflection into the container
        if ($image->compositeImage($this->im, Imagick::COMPOSITE_SRC, 0, 0)
            AND $image->compositeImage($reflection, Imagick::COMPOSITE_OVER, 0, $this->height)) {
            // Replace the current image with the reflected image
            $this->im = $image;

            // Reset the width and height
            $this->width = $this->im->getImageWidth();
            $this->height = $this->im->getImageHeight();

            return true;
        }

        return false;
    }

    protected function _do_watermark(\levmorozov\image\Image $image, $offset_x, $offset_y, $opacity)
    {
        $image->get_raw_image()->setImageBackgroundColor(new \GmagickPixel('transparent'));
        $image->get_raw_image()->setimagecolorspace(\Gmagick::COLORSPACE_TRANSPARENT);

        return $this->im->compositeimage(
            $image->get_raw_image(),
            \Gmagick::COMPOSITE_DEFAULT,
            $offset_x, $offset_y
        );
    }

    protected function _do_background($r, $g, $b, $opacity)
    {
        // Create a RGB color for the background
        $color = sprintf('rgb(%d, %d, %d)', $r, $g, $b);

        // Create a new image for the background
        $background = new \Gmagick;
        $background->newImage($this->width, $this->height, (new \GmagickPixel($color))->getcolor(false));

        // Match the colorspace between the two images before compositing
        $background->setimagecolorspace($this->im->getimagecolorspace());

        if ($background->compositeimage($this->im, \Gmagick::COMPOSITE_DISSOLVE, 0, 0)) {
            // Replace the current image with the new image
            $this->im = $background;
            return true;
        }

        return false;
    }

    protected function _do_strip()
    {
        $this->im->stripimage();
        return $this;
    }

    protected function _do_save($file, $quality)
    {
        // Get the image format and type
        list($format, $type) = $this->_get_imagetype(pathinfo($file, PATHINFO_EXTENSION));

        $from_format = strtolower($this->im->getimageformat());

        if ($from_format !== $format && $format === 'jpeg') {

            $background = new \Gmagick;
            $background->newImage($this->width, $this->height, "#FFFFFF");

            if ($background->compositeimage($this->im, \Gmagick::COMPOSITE_OVER, 0, 0)) {
                // Replace the current image with the new image
                $this->im = $background;
            }
        }

        // Set the output image type
        $this->im->setimageformat($format);

        // Set the output quality
        $this->im->setCompressionQuality($quality);

        if ($this->im->writeimage($file)) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);

            return true;
        }

        return false;
    }

    protected function _do_blank($width, $height, $background)
    {
        $background = 'rgb(' . implode(',', $background) . ')';

        $this->im = $this->im->newimage($width, $height, $background);
        $this->width = $this->im->getimagewidth();
        $this->height = $this->im->getimageheight();
    }

    public function _do_copy()
    {
        return new self(clone $this->im);
    }

    protected function _do_render($type, $quality)
    {
        // Get the image format and type
        list($format, $type) = $this->_get_imagetype($type);

        // Set the output image type
        $this->im->setimageformat($format);

        // Set the output quality
        $this->im->setCompressionQuality($quality);

        // Reset the image type and mime type
        $this->type = $type;
        $this->mime = image_type_to_mime_type($type);

        return (string)$this->im;
    }

    /**
     * Get the image type and format for an extension.
     *
     * @param   string $extension image extension: png, jpg, etc
     * @return  array
     * @throws ImageException
     */
    protected function _get_imagetype($extension)
    {
        // Normalize the extension to a format
        $format = strtolower($extension);
        if ($format === 'jpg') $format = 'jpeg';

        switch ($format) {
            case 'jpeg':
                $type = IMAGETYPE_JPEG;
                break;
            case 'gif':
                $type = IMAGETYPE_GIF;
                break;
            case 'png':
                $type = IMAGETYPE_PNG;
                break;
            default:
                throw new ImageException('Installed Gmagick does not support :type images',
                    array(':type' => $extension));
                break;
        }

        return [$format, $type];
    }
}
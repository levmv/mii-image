<?php declare(strict_types=1);

namespace mii\image\gmagick;

use mii\image\ImageException;

class Image extends \mii\image\Image
{
    /**
     * @var  \Gmagick  image magick object
     */
    protected $image;

    /**
     * Runs Image::check and loads the image.
     *
     * @param      $file
     * @param bool $is_buffer
     * @throws \GmagickException
     * @throws \Exception
     */
    public function __construct($file, bool $is_buffer = false)
    {
        if ($is_buffer) {

            $this->image = (new \Gmagick())->readimageblob($file);

            $this->width = $this->image->getimagewidth();
            $this->height = $this->image->getimageheight();
            $this->type = $this->image->getimagetype();
            $this->mime = image_type_to_mime_type($this->type);

            return;
        }

        if ($file === null) {
            $this->image = new \Gmagick;
        } elseif (is_object($file)) {
            $this->image = $file;
            $this->width = $this->image->getimagewidth();
            $this->height = $this->image->getimageheight();
        } else {
            parent::__construct($file);
            $this->image = new \Gmagick($file);
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
        $this->image->clear();
        $this->image->destroy();
    }

    public function get_raw_image()
    {
        return $this->image;
    }

    protected function doResize(int $width, int $height): void
    {
        if ($this->image->resizeimage($width, $height, \Gmagick::FILTER_LANCZOS, 1.01)) {
            // Reset the width and height
            $this->width = $this->image->getimagewidth();
            $this->height = $this->image->getimageheight();
        }
    }

    protected function doCrop(int $width, int $height, int $offset_x, int $offset_y): void
    {
        if ($this->image->cropimage($width, $height, $offset_x, $offset_y)) {
            // Reset the width and height
            $this->width = $this->image->getimagewidth();
            $this->height = $this->image->getimageheight();

            // Trim off hidden areas
            //$this->im->cropimage($this->width, $this->height, 0, 0);
        }
    }

    protected function doRotate(int $degrees): void
    {
        if ($this->image->rotateimage(new \GmagickPixel('transparent'), $degrees)) {
            // Reset the width and height
            $this->width = $this->image->getimagewidth();
            $this->height = $this->image->getimageheight();

            // Trim off hidden areas
            //$this->im->setImagePage($this->width, $this->height, 0, 0);
        }
    }

    protected function doFlip(int $direction): void
    {
        if ($direction === Image::HORIZONTAL) {
            $this->image->flopImage();
        } else {
            $this->image->flipImage();
        }
    }

    protected function doSharpen(int $amount): void
    {
        // IM not support $amount under 5 (0.15)
        $amount = ($amount < 5) ? 5 : $amount;

        // Amount should be in the range of 0.0 to 3.0
        $amount = ($amount * 3.0) / 100;

        $this->image->sharpenImage(0, $amount);
    }

    protected function doBlur(int $sigma): void
    {
        $this->image->blurimage(1, $sigma);
    }

    protected function doReflection(int $height, int $opacity, bool $fade_in): void
    {
        // Clone the current image and flip it for reflection
        $reflection = $this->image->clone();
        $reflection->flipImage();

        // Crop the reflection to the selected height
        $reflection->cropImage($this->width, $height, 0, 0);
        $reflection->setImagePage($this->width, $height, 0, 0);

        // Select the fade direction
        $direction = ['transparent', 'black'];

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
        $image->setColorspace($this->image->getColorspace());

        // Place the image and reflection into the container
        if ($image->compositeImage($this->image, Imagick::COMPOSITE_SRC, 0, 0)
            and $image->compositeImage($reflection, Imagick::COMPOSITE_OVER, 0, $this->height)) {
            // Replace the current image with the reflected image
            $this->image = $image;

            // Reset the width and height
            $this->width = $this->image->getImageWidth();
            $this->height = $this->image->getImageHeight();
        }
    }

    protected function doWatermark(\mii\image\Image $image, int $offset_x, int $offset_y, int $opacity): void
    {
        $image->get_raw_image()->setImageBackgroundColor(new \GmagickPixel('transparent'));
        $image->get_raw_image()->setimagecolorspace(\Gmagick::COLORSPACE_TRANSPARENT);

        $this->image->compositeimage(
            $image->get_raw_image(),
            \Gmagick::COMPOSITE_DEFAULT,
            $offset_x,
            $offset_y
        );
    }

    protected function doBackground(int $r, int $g, int $b, int $opacity): void
    {
        // Create a RGB color for the background
        $color = sprintf('rgb(%d, %d, %d)', $r, $g, $b);

        // Create a new image for the background
        $background = new \Gmagick;
        $background->newImage($this->width, $this->height, (new \GmagickPixel($color))->getcolor(false));

        // Match the colorspace between the two images before compositing
        $background->setimagecolorspace($this->image->getimagecolorspace());

        if ($background->compositeimage($this->image, \Gmagick::COMPOSITE_DISSOLVE, 0, 0)) {
            // Replace the current image with the new image
            $this->image = $background;
        }
    }

    protected function doStrip(): \mii\image\Image
    {
        $this->image->stripimage();
        return $this;
    }

    protected function doSave(string $file, int $quality): bool
    {
        // Get the image format and type
        [$format, $type] = $this->getImageType(pathinfo($file, \PATHINFO_EXTENSION));

        $from_format = strtolower($this->image->getimageformat());

        if ($from_format !== $format && $format === 'jpeg') {
            $background = new \Gmagick;
            $background->newImage($this->width, $this->height, '#FFFFFF');

            if ($background->compositeimage($this->image, \Gmagick::COMPOSITE_OVER, 0, 0)) {
                // Replace the current image with the new image
                $this->image = $background;
            }
        }

        // Set the output image type
        $this->image->setimageformat($format);

        // Set the output quality
        $this->image->setCompressionQuality($quality);

        if ($this->image->writeimage($file)) {
            // Reset the image type and mime type
            $this->type = $type;
            $this->mime = image_type_to_mime_type($type);

            return true;
        }

        return false;
    }

    protected function doBlank(int $width, int $height, array $background): void
    {
        $background = 'rgb(' . implode(',', $background) . ')';

        $this->image = $this->image->newimage($width, $height, $background);
        $this->width = $this->image->getimagewidth();
        $this->height = $this->image->getimageheight();
    }

    public function doCopy()
    {
        return new self(clone $this->image);
    }

    protected function doRender(string $type, int $quality): string
    {
        // Get the image format and type
        [$format, $type] = $this->getImageType($type);

        // Set the output image type
        $this->image->setimageformat($format);

        // Set the output quality
        $this->image->setCompressionQuality($quality);

        // Reset the image type and mime type
        $this->type = $type;
        $this->mime = image_type_to_mime_type($type);

        return (string)$this->image;
    }

    /**
     * Get the image type and format for an extension.
     *
     * @param string $extension image extension: png, jpg, etc
     * @return  array
     * @throws ImageException
     */
    protected function getImageType($extension)
    {
        // Normalize the extension to a format
        $format = strtolower($extension);
        if ($format === 'jpg') {
            $format = 'jpeg';
        }

        switch ($format) {
            case 'jpeg':
                $type = \IMAGETYPE_JPEG;
                break;
            case 'gif':
                $type = \IMAGETYPE_GIF;
                break;
            case 'png':
                $type = \IMAGETYPE_PNG;
                break;
            default:
                throw new ImageException("Installed Gmagick does not support $extension images");
                break;
        }

        return [$format, $type];
    }
}

<?php declare(strict_types=1);

namespace mii\image;

use mii\util\Debug;

abstract class Image
{
    // Resizing constraints
    public const NONE = 0x01;
    public const WIDTH = 0x02;
    public const HEIGHT = 0x03;
    public const AUTO = 0x04;
    public const INVERSE = 0x05;
    public const PRECISE = 0x06;

    // Flipping directions
    public const HORIZONTAL = 0x11;
    public const VERTICAL = 0x12;

    /**
     * @var  string  image file path
     */
    public string $file;

    /**
     * @var  integer  image width
     */
    public int $width;

    /**
     * @var  integer  image height
     */
    public int $height;

    /**
     * @var  integer  one of the IMAGETYPE_* constants
     */
    public int $type;

    /**
     * @var  string  mime type of the image
     */
    public string $mime;


    public int $quality = 100;

    /**
     * Loads information about the image. Will throw an exception if the image
     * does not exist or is not an image.
     *
     * @param string $file image file path
     * @return  void
     * @throws  \Exception
     */
    public function __construct(string $file)
    {
        try {
            // Get the real path to the file
            $realfile = realpath($file);

            // Get the image information
            $info = getimagesize($realfile);
        } catch (\Exception $e) {
            // Ignore all errors while reading the image
        }

        if (empty($realfile) || empty($info)) {
            throw new ImageException('Not an image or invalid image: ' . \mii\util\Debug::path($file));
        }

        // Store the image information
        $this->file = $realfile;
        $this->width = $info[0];
        $this->height = $info[1];
        $this->type = $info[2];
        $this->mime = image_type_to_mime_type($this->type);
    }

    /**
     * Render the current image.
     *
     *     echo $image;
     *
     * [!!] The output of this function is binary and must be rendered with the
     * appropriate Content-Type header or it will not be displayed correctly!
     *
     * @return  string
     */
    public function __toString()
    {
        try {
            // Render the current image
            return $this->render();
        } catch (\Throwable $e) {
            \Mii::error($e);

            // Showing any kind of error will be "inside" image data
            return '';
        }
    }


    /**
     * Resize the image to the given size. Either the width or the height can
     * be omitted and the image will be resized proportionally.
     *
     *     // Resize to 200 pixels on the shortest side
     *     $image->resize(200, 200);
     *
     *     // Resize to 200x200 pixels, keeping aspect ratio
     *     $image->resize(200, 200, Image::INVERSE);
     *
     *     // Resize to 500 pixel width, keeping aspect ratio
     *     $image->resize(500, null);
     *
     *     // Resize to 500 pixel height, keeping aspect ratio
     *     $image->resize(null, 500);
     *
     *     // Resize to 200x500 pixels, ignoring aspect ratio
     *     $image->resize(200, 500, Image::NONE);
     *
     * @param integer $width new width
     * @param integer $height new height
     * @param integer $master master dimension
     * @return  $this
     * @uses    Image::doResize
     */
    public function resize(int $width = null, int $height = null, int $master = null): Image
    {
        if ($master === null) {
            // Choose the master dimension automatically
            $master = Image::AUTO;
        }
        // Image::WIDTH and Image::HEIGHT deprecated. You can use it in old projects,
        // but in new you must pass empty value for non-master dimension
        elseif ($master == Image::WIDTH and !empty($width)) {
            $master = Image::AUTO;

            // Set empty height for backward compatibility
            $height = null;
        } elseif ($master == Image::HEIGHT and !empty($height)) {
            $master = Image::AUTO;

            // Set empty width for backward compatibility
            $width = null;
        }

        if (empty($width)) {
            if ($master === Image::NONE) {
                // Use the current width
                $width = $this->width;
            } else {
                // If width not set, master will be height
                $master = Image::HEIGHT;
            }
        }

        if (empty($height)) {
            if ($master === Image::NONE) {
                // Use the current height
                $height = $this->height;
            } else {
                // If height not set, master will be width
                $master = Image::WIDTH;
            }
        }

        switch ($master) {
            case Image::AUTO:
                // Choose direction with the greatest reduction ratio
                $master = ($this->width / $width) > ($this->height / $height) ? Image::WIDTH : Image::HEIGHT;
                break;
            case Image::INVERSE:
                // Choose direction with the minimum reduction ratio
                $master = ($this->width / $width) > ($this->height / $height) ? Image::HEIGHT : Image::WIDTH;
                break;
        }

        switch ($master) {
            case Image::WIDTH:
                // Recalculate the height based on the width proportions
                $height = $this->height * $width / $this->width;
                break;
            case Image::HEIGHT:
                // Recalculate the width based on the height proportions
                $width = $this->width * $height / $this->height;
                break;
            case Image::PRECISE:
                // Resize to precise size
                $ratio = $this->width / $this->height;

                if ($width / $height > $ratio) {
                    $height = $this->height * $width / $this->width;
                } else {
                    $width = $this->width * $height / $this->height;
                }
                break;
        }

        // Convert the width and height to integers, minimum value is 1px
        $width = max((int)round($width), 1);
        $height = max((int)round($height), 1);

        $this->doResize($width, $height);

        return $this;
    }

    /**
     * Crop an image to the given size. Either the width or the height can be
     * omitted and the current width or height will be used.
     *
     * If no offset is specified, the center of the axis will be used.
     * If an offset of true is specified, the bottom of the axis will be used.
     *
     *     // Crop the image to 200x200 pixels, from the center
     *     $image->crop(200, 200);
     *
     * @param integer $width new width
     * @param integer $height new height
     * @param mixed   $offset_x offset from the left
     * @param mixed   $offset_y offset from the top
     * @return  $this
     * @uses    Image::doCrop
     */
    public function crop(int $width, int $height, int $offset_x = null, int $offset_y = null): Image
    {
        if ($width > $this->width) {
            // Use the current width
            $width = $this->width;
        }

        if ($height > $this->height) {
            // Use the current height
            $height = $this->height;
        }

        if ($offset_x === null) {
            // Center the X offset
            $offset_x = round(($this->width - $width) / 2);
        } elseif ($offset_x === true) {
            // Bottom the X offset
            $offset_x = $this->width - $width;
        } elseif ($offset_x < 0) {
            // Set the X offset from the right
            $offset_x = $this->width - $width + $offset_x;
        }

        if ($offset_y === null) {
            // Center the Y offset
            $offset_y = round(($this->height - $height) / 2);
        } elseif ($offset_y === true) {
            // Bottom the Y offset
            $offset_y = $this->height - $height;
        } elseif ($offset_y < 0) {
            // Set the Y offset from the bottom
            $offset_y = $this->height - $height + $offset_y;
        }

        // Determine the maximum possible width and height
        $max_width = $this->width - $offset_x;
        $max_height = $this->height - $offset_y;

        if ($width > $max_width) {
            // Use the maximum available width
            $width = $max_width;
        }

        if ($height > $max_height) {
            // Use the maximum available height
            $height = $max_height;
        }

        $this->doCrop((int)$width, (int)$height, (int)$offset_x, (int)$offset_y);

        return $this;
    }

    /**
     * Rotate the image by a given amount.
     *
     *     // Rotate 45 degrees clockwise
     *     $image->rotate(45);
     *
     *     // Rotate 90% counter-clockwise
     *     $image->rotate(-90);
     *
     * @param integer $degrees degrees to rotate: -360-360
     * @return  $this
     * @uses    Image::doRotate
     */
    public function rotate(int $degrees): Image
    {
        // Make the degrees an integer
        $degrees = (int) $degrees;

        if ($degrees > 180) {
            do {
                // Keep subtracting full circles until the degrees have normalized
                $degrees -= 360;
            } while ($degrees > 180);
        }

        if ($degrees < -180) {
            do {
                // Keep adding full circles until the degrees have normalized
                $degrees += 360;
            } while ($degrees < -180);
        }

        $this->doRotate($degrees);

        return $this;
    }

    /**
     * Flip the image along the horizontal or vertical axis.
     *
     *     // Flip the image from top to bottom
     *     $image->flip(Image::HORIZONTAL);
     *
     *     // Flip the image from left to right
     *     $image->flip(Image::VERTICAL);
     *
     * @param integer $direction direction: Image::HORIZONTAL, Image::VERTICAL
     * @return  $this
     * @uses    Image::doFlip
     */
    public function flip(int $direction): Image
    {
        if ($direction !== Image::HORIZONTAL) {
            // Flip vertically
            $direction = Image::VERTICAL;
        }

        $this->doFlip($direction);

        return $this;
    }

    /**
     * Sharpen the image by a given amount.
     *
     *     // Sharpen the image by 20%
     *     $image->sharpen(20);
     *
     * @param integer $amount amount to sharpen: 1-100
     * @return  $this
     * @uses    Image::doSharpen
     */
    public function sharpen(int $amount): Image
    {
        // The amount must be in the range of 1 to 100
        $amount = min(max($amount, 1), 100);

        $this->doSharpen($amount);

        return $this;
    }

    public function blur(int $sigma): Image
    {
        $this->doBlur($sigma);

        return $this;
    }

    /**
     * Add a reflection to an image. The most opaque part of the reflection
     * will be equal to the opacity setting and fade out to full transparent.
     * Alpha transparency is preserved.
     *
     *     // Create a 50 pixel reflection that fades from 0-100% opacity
     *     $image->reflection(50);
     *
     *     // Create a 50 pixel reflection that fades from 100-0% opacity
     *     $image->reflection(50, 100, true);
     *
     *     // Create a 50 pixel reflection that fades from 0-60% opacity
     *     $image->reflection(50, 60, true);
     *
     * [!!] By default, the reflection will be go from transparent at the top
     * to opaque at the bottom.
     *
     * @param integer $height reflection height
     * @param integer $opacity reflection opacity: 0-100
     * @param boolean $fade_in true to fade in, false to fade out
     * @return  $this
     * @uses    Image::doReflection
     */
    public function reflection(int $height = null, int $opacity = 100, bool $fade_in = false): Image
    {
        if ($height === null or $height > $this->height) {
            // Use the current height
            $height = $this->height;
        }

        // The opacity must be in the range of 0 to 100
        $opacity = min(max($opacity, 0), 100);

        $this->doReflection($height, $opacity, $fade_in);

        return $this;
    }

    /**
     * Add a watermark to an image with a specified opacity. Alpha transparency
     * will be preserved.
     *
     * If no offset is specified, the center of the axis will be used.
     * If an offset of true is specified, the bottom of the axis will be used.
     *
     *     // Add a watermark to the bottom right of the image
     *     $mark = Image::factory('upload/watermark.png');
     *     $image->watermark($mark, true, true);
     *
     * @param Image   $watermark watermark Image instance
     * @param integer $offset_x offset from the left
     * @param integer $offset_y offset from the top
     * @param integer $opacity opacity of watermark: 1-100
     * @return  $this
     * @uses    Image::doWatermark
     */
    public function watermark(Image $watermark, int $offset_x = null, int $offset_y = null, int $opacity = 100): Image
    {
        if ($offset_x === null) {
            // Center the X offset
            $offset_x = round(($this->width - $watermark->width) / 2);
        } elseif ($offset_x === true) {
            // Bottom the X offset
            $offset_x = $this->width - $watermark->width;
        } elseif ($offset_x < 0) {
            // Set the X offset from the right
            $offset_x = $this->width - $watermark->width + $offset_x;
        }

        if ($offset_y === null) {
            // Center the Y offset
            $offset_y = round(($this->height - $watermark->height) / 2);
        } elseif ($offset_y === true) {
            // Bottom the Y offset
            $offset_y = $this->height - $watermark->height;
        } elseif ($offset_y < 0) {
            // Set the Y offset from the bottom
            $offset_y = $this->height - $watermark->height + $offset_y;
        }

        // The opacity must be in the range of 1 to 100
        $opacity = min(max($opacity, 1), 100);

        $this->doWatermark($watermark, $offset_x, $offset_y, $opacity);

        return $this;
    }

    /**
     * Set the background color of an image. This is only useful for images
     * with alpha transparency.
     *
     *     // Make the image background black
     *     $image->background('#000');
     *
     *     // Make the image background black with 50% opacity
     *     $image->background('#000', 50);
     *
     * @param string  $color hexadecimal color value
     * @param integer $opacity background opacity: 0-100
     * @return  $this
     * @uses    Image::doBackground
     */
    public function background(string $color, int $opacity = 100): Image
    {
        if ($color[0] === '#') {
            // Remove the pound
            $color = substr($color, 1);
        }

        if (strlen($color) === 3) {
            // Convert shorthand into longhand hex notation
            $color = preg_replace('/./', '$0$0', $color);
        }

        // Convert the hex into RGB values
        [$r, $g, $b] = array_map('hexdec', str_split($color, 2));

        // The opacity must be in the range of 0 to 100
        $opacity = min(max($opacity, 0), 100);

        $this->doBackground($r, $g, $b, $opacity);

        return $this;
    }

    public function blank(int $width, int $height, array $background = [255, 255, 255]): Image
    {
        if (!is_array($background) or count($background) < 3 or count($background) > 3) {
            $background = [255, 255, 255];
        }

        $this->doBlank($width, $height, $background);
        return $this;
    }

    public function strip(): Image
    {
        return $this->doStrip();
    }

    public function copy(): Image
    {
        return $this->doCopy();
    }

    public function quality(int $quality): Image
    {
        $this->quality = $quality;
        return $this;
    }

    /**
     * Save the image. If the filename is omitted, the original image will
     * be overwritten.
     *
     *     // Save the image as a PNG
     *     $image->save('saved/cool.png');
     *
     *     // Overwrite the original image
     *     $image->save();
     *
     * [!!] If the file exists, but is not writable, an exception will be thrown.
     *
     * [!!] If the file does not exist, and the directory is not writable, an
     * exception will be thrown.
     *
     * @param string  $file new image path
     * @param integer $quality quality of image: 1-100
     * @return bool
     * @throws ImageException
     */
    public function save(string $file = null, int $quality = null): bool
    {
        if ($file === null) {
            // Overwrite the file
            $file = $this->file;
        } else {
            $file = \Mii::resolve($file);
        }

        if ($quality === null) {
            $quality = $this->quality;
        }

        if (is_file($file)) {
            if (!is_writable($file)) {
                throw new ImageException('File must be writable: ' . Debug::path($file));
            }
        } else {
            // Get the directory of the file
            $directory = realpath(pathinfo($file, \PATHINFO_DIRNAME));

            if (!is_dir($directory) or !is_writable($directory)) {
                throw new ImageException('Directory must be writable: ' . Debug::path($directory));
            }
        }

        // The quality must be in the range of 1 to 100
        $quality = min(max($quality, 1), 100);

        return $this->doSave($file, $quality);
    }

    /**
     * Render the image and return the binary string.
     *
     *     // Render the image at 50% quality
     *     $data = $image->render(null, 50);
     *
     *     // Render the image as a PNG
     *     $data = $image->render('png');
     *
     * @param string  $type image type to return: png, jpg, gif, etc
     * @param integer $quality quality of image: 1-100
     * @return  string
     * @uses    Image::doRender
     */
    public function render(string $type = null, int $quality = null): string
    {
        if ($quality === null) {
            $quality = $this->quality;
        }

        if ($type === null) {
            // Use the current image type
            $type = image_type_to_extension($this->type, false);
        }

        return $this->doRender($type, $quality);
    }

    /**
     * Execute a resize.
     *
     * @param integer $width new width
     * @param integer $height new height
     * @return  void
     */
    abstract protected function doResize(int $width, int $height): void;

    /**
     * Execute a crop.
     *
     * @param integer $width new width
     * @param integer $height new height
     * @param integer $offset_x offset from the left
     * @param integer $offset_y offset from the top
     * @return  void
     */
    abstract protected function doCrop(int $width, int $height, int $offset_x, int $offset_y): void;

    /**
     * Execute a rotation.
     *
     * @param integer $degrees degrees to rotate
     * @return  void
     */
    abstract protected function doRotate(int $degrees): void;

    /**
     * Execute a flip.
     *
     * @param integer $direction direction to flip
     * @return  void
     */
    abstract protected function doFlip(int $direction): void;

    /**
     * Execute a sharpen.
     *
     * @param integer $amount amount to sharpen
     * @return  void
     */
    abstract protected function doSharpen(int $amount): void;

    /**
     * Execute a blur.
     *
     * @param integer $sigma
     * @return  void
     */
    abstract protected function doBlur(int $sigma): void;

    /**
     * Execute a reflection.
     *
     * @param integer $height reflection height
     * @param integer $opacity reflection opacity
     * @param boolean $fade_in true to fade out, false to fade in
     * @return  void
     */
    abstract protected function doReflection(int $height, int $opacity, bool $fade_in): void;

    /**
     * Execute a watermarking.
     *
     * @param Image   $image watermarking Image
     * @param integer $offset_x offset from the left
     * @param integer $offset_y offset from the top
     * @param integer $opacity opacity of watermark
     * @return  void
     */
    abstract protected function doWatermark(Image $image, int $offset_x, int $offset_y, int $opacity): void;

    abstract protected function doBlank(int $width, int $height, array $background): void;

    abstract protected function doStrip(): Image;

    abstract protected function doCopy();

    /**
     * Execute a background.
     *
     * @param integer $r red
     * @param integer $g green
     * @param integer $b blue
     * @param integer $opacity opacity
     * @return void
     */
    abstract protected function doBackground(int $r, int $g, int $b, int $opacity): void;

    /**
     * Execute a save.
     *
     * @param string  $file new image filename
     * @param integer $quality quality
     * @return  boolean
     */
    abstract protected function doSave(string $file, int $quality): bool;

    /**
     * Execute a render.
     *
     * @param string  $type image type: png, jpg, gif, etc
     * @param integer $quality quality
     * @return  string
     */
    abstract protected function doRender(string $type, int $quality): string;
} // End Image

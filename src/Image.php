<?php declare(strict_types=1);

namespace mii\image;


use Mii;
use RuntimeException;
use Throwable;

abstract class Image
{
    // Flipping directions
    public const HORIZONTAL = 0x11;
    public const VERTICAL = 0x12;

    /**
     * @var  string  image file path
     */
    public string $file;

    public int $width;
    public int $height;

    public bool $needStrip = false;

    /**
     * @var  integer  one of the IMAGETYPE_* constants
     */
    public int $type;

    public int $quality = 98;

    /**
     * Loads information about the image. Will throw an exception if the image
     * does not exist or is not an image.
     *
     * @param string $file image file path
     * @return  void
     */
    public function __construct(string $file)
    {
        try {
            // Get the real path to the file
            $realFile = realpath($file);

            // Get the image information
            $info = getimagesize($realFile);

        } catch (Throwable) {
            // Ignore all errors while reading the image
        }

        if (empty($realFile) || empty($info)) {
            throw new RuntimeException('Not an image or invalid image: ' . \mii\util\Debug::path($file));
        }

        $this->file = $realFile;
        $this->width = $info[0];
        $this->height = $info[1];
        $this->type = $info[2];
    }

    /**
     * Render the current image.
     *
     * [!!] The output of this function is binary and must be rendered with the
     * appropriate Content-Type header, or it will not be displayed correctly!
     *
     * @return  string
     */
    public function __toString()
    {
        try {
            // Render the current image
            return $this->render();

        } catch (Throwable $e) {
            Mii::error($e);

            // Showing any kind of error will be "inside" image data
            return '';
        }
    }


    /**
     * Resize the image to the given size. Either the width or the height can
     * be omitted and the image will be resized proportionally.
     *
     * @param int|null $width new width
     * @param int|null $height new height
     * @param bool $upscale
     * @param bool $inverse
     * @return  $this
     */
    public function resize(int $width = null, int $height = null, bool $upscale = false, bool $inverse = false): Image
    {
        if ($width === null) {
            $widthMain = false;
        } elseif ($height === null) {
            $widthMain = true;
        } else {
            $widthMain = ($this->width / $width) > ($this->height / $height);
        }

        if($inverse) {
            $widthMain = !$widthMain;
        }

        if($widthMain) {
            // Recalculate the height based on the width proportions
            $height = $this->height * $width / $this->width;
        } else {
            // Recalculate the width based on the height proportions
            $width = $this->width * $height / $this->height;
        }

        // Convert the width and height to integers, minimum value is 1px
        $width = (int) max(round($width), 1);
        $height = (int) max(round($height), 1);

        if($upscale || ($width < $this->width && $height < $this->height)) {
            $this->doResize($width, $height);
        }

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
     * @param mixed $offset_x offset from the left
     * @param mixed $offset_y offset from the top
     * @return  $this
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

        $this->doCrop($width, $height, $offset_x, $offset_y);

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
     */
    public function rotate(int $degrees): Image
    {
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
     * @param integer $direction direction: Image::HORIZONTAL, Image::VERTICAL
     * @return  $this
     */
    public function flip(int $direction = Image::VERTICAL): Image
    {
        $this->doFlip($direction);

        return $this;
    }

    /**
     * Sharpen the image by a given amount.
     *
     * @param integer $amount amount to sharpen: 1-100
     * @return  $this
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
     * @param int|null $height reflection height
     * @param integer $opacity reflection opacity: 0-100
     * @param boolean $fadeIn true to fade in, false to fade out
     * @return  $this
     * @uses    Image::doReflection
     */
    public function reflection(int $height = null, int $opacity = 100, bool $fadeIn = false): Image
    {
        if ($height === null or $height > $this->height) {
            // Use the current height
            $height = $this->height;
        }

        // The opacity must be in the range of 0 to 100
        $opacity = min(max($opacity, 0), 100);

        $this->doReflection($height, $opacity, $fadeIn);

        return $this;
    }

    /**
     * Add a watermark to an image with a specified opacity. Alpha transparency
     * will be preserved.
     *
     * If no offset is specified, the center of the axis will be used.
     * If an offset of true is specified, the bottom of the axis will be used.
     *
     * @param Image $watermark watermark Image instance
     * @param int|null $offset_x offset from the left
     * @param int|null $offset_y offset from the top
     * @param integer $opacity opacity of watermark: 1-100
     * @return  $this
     */
    public function watermark(Image $watermark, int $offset_x = null, int $offset_y = null, int $opacity = 100): Image
    {
        if ($offset_x === null) {
            // Center the X offset
            $offset_x = round(($this->width - $watermark->width) / 2);
        } elseif ($offset_x <= 0) {
            // Set the X offset from the right
            $offset_x = $this->width - $watermark->width + $offset_x;
        }

        if ($offset_y === null) {
            // Center the Y offset
            $offset_y = round(($this->height - $watermark->height) / 2);
        } elseif ($offset_y <= 0) {
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
     * @param string $color hexadecimal color value
     * @param integer $opacity background opacity: 0-100
     * @return  $this
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
        list ($r, $g, $b) = array_map('hexdec', str_split($color, 2));

        // The opacity must be in the range of 0 to 100
        $opacity = min(max($opacity, 0), 100);

        $this->doBackground($r, $g, $b, $opacity);

        return $this;
    }

    public function blank(int $width, int $height, array $background = [255, 255, 255]): Image
    {
        if (!is_array($background)) {
            $background = [255, 255, 255];
        }
        assert(count($background) === 3);

        $this->doBlank($width, $height, $background);
        return $this;
    }


    public function strip(bool $enable = true): Image
    {
        $this->needStrip = $enable;
        return $this;
    }

    public function copy(): Image
    {
        return $this->doCopy();
    }

    public function quality(int $quality): Image
    {
        $this->quality = min(max($quality, 1), 100);
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
     * @param string|null $file new image path
     * @param integer|null $quality quality of image: 1-100
     * @param int|null $type
     * @return bool
     */
    public function save(string $file = null, int $quality = null, int $type = null): bool
    {
        if ($file === null) {
            // Overwrite the file
            $file = $this->file;
            $type = $this->type;
        } else {
            $file = Mii::resolve($file);
            if($type === null) {
                $type = $this->extensionToImageType(pathinfo($file, PATHINFO_EXTENSION));
            }
        }

        if ($quality !== null) {
            $this->quality($quality);
        }

        if (is_file($file)) {
            if (!is_writable($file)) {
                throw new RuntimeException('File must be writable: ' . \mii\util\Debug::path($file));
            }
        } else {
            // Get the directory of the file
            $directory = realpath(pathinfo($file, PATHINFO_DIRNAME));

            if (!is_dir($directory) || !is_writable($directory)) {
                throw new RuntimeException('Directory must be writable: ' . \mii\util\Debug::path($directory));
            }
        }

        return $this->doSave($file, $type);
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
     * @param ?string $type image type to return: png, jpg, gif, etc
     * @param int|null $quality quality of image: 1-100
     * @return  string
     */
    public function render(?string $type = null, int $quality = null): string
    {
        if ($quality !== null) {
            $this->quality($quality);
        }

        $type = ($type === null)
            ? $this->type
            : $this->extensionToImageType($type);

        return $this->doRender($type);
    }


    protected function extensionToImageType(string $extension): int
    {
        return match (strtolower($extension)) {
            'jpg', 'jpe', 'jpeg' => IMAGETYPE_JPEG,
            'png' => IMAGETYPE_PNG,
            'webp' => IMAGETYPE_WEBP,
            'gif' => IMAGETYPE_GIF,
            'bmp' => IMAGETYPE_BMP,
            default => IMAGETYPE_UNKNOWN
        };
    }


    /**
     * @param integer $width new width
     * @param integer $height new height
     * @return  void
     */
    abstract protected function doResize(int $width, int $height): void;

    /**
     * @param integer $width new width
     * @param integer $height new height
     * @param integer $offsetX offset from the left
     * @param integer $offsetY offset from the top
     * @return  void
     */
    abstract protected function doCrop(int $width, int $height, int $offsetX, int $offsetY): void;

    /**
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
     * @param integer $amount amount to sharpen
     * @return  void
     */
    abstract protected function doSharpen(int $amount): void;

    /**
     * @param integer $sigma
     * @return  void
     */
    abstract protected function doBlur(int $sigma): void;

    /**
     * @param integer $height reflection height
     * @param integer $opacity reflection opacity
     * @param boolean $fadeIn true to fade out, false to fade in
     * @return  void
     */
    abstract protected function doReflection(int $height, int $opacity, bool $fadeIn): void;

    /**
     * @param Image $image watermarking Image
     * @param integer $offsetX offset from the left
     * @param integer $offsetY offset from the top
     * @param integer $opacity opacity of watermark
     * @return  void
     */
    abstract protected function doWatermark(Image $image, int $offsetX, int $offsetY, int $opacity): void;


    abstract protected function doBlank(int $width, int $height, array $background): void;


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
    abstract protected function doBackground(int $r, int $g, int $b, int $opacity);

    /**
     * Execute a save.
     *
     * @param string $file new image filename
     * @param integer $type
     * @return  boolean
     */
    abstract protected function doSave(string $file, int $type): bool;

    /**
     * Execute a render.
     *
     * @param int $type image type
     * @return  string
     */
    abstract protected function doRender(int $type): string;

}

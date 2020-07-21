<?php declare(strict_types=1);

namespace mii\image\vips;

use Jcupitt\Vips\Exception;
use Jcupitt\Vips\Interpretation;
use Jcupitt\Vips\Size;
use mii\image\ImageException;

class Image extends \mii\image\Image
{
    // Temporary image resource
    /**
     * @var \Jcupitt\Vips\Image $image
     */
    protected $image;

    // Function name to open Image
    protected string $create_function;

    // Options for create function (autorotate for jpeg)
    protected array $options = [];

    // Flag for strip metadata when save image
    protected bool $need_strip = false;


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

            $this->image = \Jcupitt\Vips\Image::newFromBuffer($file);
            $loader = $this->image->get('vips-loader');

            switch ($loader) {
                case 'jpegload_buffer':
                    $this->type = \IMAGETYPE_JPEG;
                    break;
                case 'pngload_buffer':
                    $this->type = \IMAGETYPE_PNG;
                    break;
                case 'gifload_buffer':
                    $this->type = \IMAGETYPE_GIF;
                    break;
            }

            if (!$this->type) {
                throw new ImageException("Unsupported image type, vips-loader: $loader");
            }

            $this->mime = image_type_to_mime_type($this->type);
            $this->width = $this->image->width;
            $this->height = $this->image->height;

            return;
        }

        parent::__construct($file);

        $options = [];

        // Set the image creation function name
        switch ($this->type) {
            case \IMAGETYPE_JPEG:
                $create = 'jpegload';
                $options = ['autorotate' => true];
                break;
            case \IMAGETYPE_GIF:
                $create = 'gifload';
                break;
            case \IMAGETYPE_PNG:
                $create = 'pngload';
                break;
        }

        if (!isset($create)) { //  OR !function_exists($create)
            throw new ImageException('Installed vips does not support ' . image_type_to_extension($this->type, false) . ' images');
        }

        // Save function and options for future use
        $this->create_function = $create;
        $this->options = $options;

        $this->loadImage();

        //if($this->image->get('icc-profile-data')) {
        try {
            $this->image = $this->image->icc_import(['embedded' => true]);

            if ($this->image->interpretation !== Interpretation::B_W && $this->image->interpretation !== Interpretation::GREY16) {
                $this->image->colourspace(Interpretation::SRGB);
            }
        } catch (\Throwable $e) {
        }
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
    protected function loadImage(): void
    {
        if (!$this->image instanceof \Jcupitt\Vips\Image) {
            // Gets create function
            $create = $this->create_function;
            // Open the temporary image
            $this->image = \Jcupitt\Vips\Image::$create($this->file, $this->options);
        }
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
        // Loads image if not yet loaded
        $this->loadImage();

        $this->image = $this->image->thumbnail_image($width, ['height' => $height, 'size' => Size::DOWN]);

        $this->width = $this->image->width;
        $this->height = $this->image->height;
    }

    protected function doStrip(): \mii\image\Image
    {
        $this->need_strip = true;
        return $this;
    }

    /**
     * Execute a render.
     *
     * @param string  $type image type: png, jpg, gif, etc
     * @param integer $quality quality
     * @return  string
     * @throws ImageException
     */
    protected function doRender(string $type, int $quality): string
    {
        // Loads image if not yet loaded
        $this->loadImage();

        // Get the save function and IMAGETYPE
        [$save, $type, $options] = $this->saveFunction($type, $quality);

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
     * @param int    $quality image quality
     * @return array save function, IMAGETYPE_* constant, options
     * @throws ImageException
     */
    protected function saveFunction(string $extension, int $quality): array
    {
        if (!$extension || null === ($type = $this->extensionToImageType($extension))) {
            $type = $this->type;
        }

        switch ($type) {
            case \IMAGETYPE_JPEG:
                $save = 'jpegsave_buffer';
                $type = \IMAGETYPE_JPEG;
                $options = $this->need_strip
                    ? ['Q' => $quality, 'strip' => true, 'optimize_coding' => true]
                    : ['Q' => $quality];
                break;
            case \IMAGETYPE_PNG:
                $save = 'pngsave_buffer';
                $type = \IMAGETYPE_PNG;
                // Use a compression level of 9 (does not affect quality!)
                $options = ['compression' => 9];
                $quality = 9;
                break;
            default:
                throw new ImageException("Installed vips does not support saving $extension images");
                break;
        }

        return [$save, $type, $options];
    }

    protected function extensionToImageType(string $extension)
    {
        switch (strtolower($extension)) {
            case 'jpg':
            case 'jpe':
            case 'jpeg':
                return \IMAGETYPE_JPEG;
            case 'png':
                return \IMAGETYPE_PNG;
            case 'webp':
                return \IMAGETYPE_WEBP;
            case 'gif':
                return \IMAGETYPE_GIF;
        }
        return null;
    }

    protected function doCrop(int $width, int $height, int $offset_x, int $offset_y): void
    {
    }

    protected function doRotate(int $degrees): void
    {
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

    protected function doReflection(int $height, int $opacity, bool $fade_in): void
    {
    }

    protected function doWatermark(\mii\image\Image $image, int $offset_x, int $offset_y, int $opacity): void
    {
    }

    protected function doBackground(int $r,int $g, int $b, int $opacity): void
    {
    }

    protected function doSave(string $file, int $quality): bool
    {
        return (bool)file_put_contents($file, $this->render(pathinfo($file, \PATHINFO_EXTENSION), $quality));
    }

    protected function doBlank(int $width, int $height, array $background): void
    {
    }

    public function doCopy()
    {
    }
}

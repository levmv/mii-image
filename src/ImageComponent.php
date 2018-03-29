<?php

namespace levmorozov\image;

use mii\core\Component;

class ImageComponent extends Component
{
    /**
     * @var  string  default driver: GD, Gmagick, etc
     */
    public $driver = \levmorozov\image\gmagick\Image::class;

    public function factory($file)
    {
        return new $this->driver($file);
    }
}
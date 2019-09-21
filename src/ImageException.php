<?php

namespace levmorozov\image;

use Throwable;

class ImageException extends \Exception {

    public function __construct(string $message = "", $params = [])
    {
        parent::__construct(strtr($message, $params));
    }


}
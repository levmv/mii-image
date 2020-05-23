<?php

namespace mii\image;


class ImageException extends \Exception
{

    public function __construct(string $message = "", $params = [])
    {
        parent::__construct(strtr($message, $params));
    }

}

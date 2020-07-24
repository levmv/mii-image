<?php declare(strict_types=1);

namespace mii\image;

class ImageException extends \Exception
{
    public function __construct(string $message = '', array $params = [])
    {
        parent::__construct(strtr($message, $params));
    }
}

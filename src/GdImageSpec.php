<?php

namespace App;

use Safe\Exceptions\ImageException;
use function Safe\getimagesize;

abstract class GdImageSpec implements ImageSpecInterface
{
    public function __construct(protected int $type = \IMAGETYPE_JPEG)
    {
        if (\image_type_to_extension($this->type) === false) {
            throw new \InvalidArgumentException("Invalid image type: {$this->type}.");
        }
    }

    public function mimeType() : string
    {
        return \image_type_to_mime_type($this->type);
    }

    abstract protected function assert(int $width, int $height, int $type) : void;

    public function assertStream($stream) : void
    {
        [$width, $height, $type] = StreamUri::cast($stream, fn($uri) => getimagesize($uri));
        $this->assert($width, $height, $type);
    }

    public function assertFile(string $path) : void
    {
        [$width, $height, $type] = getimagesize($path);
        $this->assert($width, $height, $type);
    }

    public function assertBinary(string $data) : void
    {
        error_clear_last();
        $result = \getimagesizefromstring($data);

        if ($result === false) {
            throw ImageException::createFromPhpError();
        }

        [$width, $height, $type] = $result;
        $this->assert($width, $height, $type);
    }
}

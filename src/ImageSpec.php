<?php

namespace App;

final class ImageSpec extends GdImageSpec
{
    public function __construct(
        private int $width,
        private int $height,
        int $type = \IMAGETYPE_JPEG
    )
    {
        if ($this->width < 0 || $this->height < 0) {
            throw new \InvalidArgumentException("Invalid width or height: {$this->width}x{$this->height}.");
        }

        parent::__construct($type);
    }

    protected function assert(int $width, int $height, int $type) : void
    {
        if ([$width, $height, $type] !== [$this->width, $this->height, $this->type]) {
            throw new \UnexpectedValueException(\sprintf(
                'Got %s (%dx%d) rather than %s (%dx%d).',
                \image_type_to_mime_type($type), $width, $height,
                \image_type_to_mime_type($this->type), $this->width, $this->height
            ));
        }
    }
}

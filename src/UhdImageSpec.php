<?php

namespace App;

final class UhdImageSpec extends GdImageSpec
{
    protected function assert(int $width, int $height, int $type) : void
    {
        if ($width < 1920 || $height < 1080 || $type !== $this->type) {
            throw new \UnexpectedValueException(\sprintf(
                'Got %s (%dx%d) while %s (width >= 1920, height >= 1080) is excepted.',
                \image_type_to_mime_type($type), $width, $height,
                \image_type_to_mime_type($this->type)
            ));
        }
    }
}

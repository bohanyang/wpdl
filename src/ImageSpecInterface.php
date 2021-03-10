<?php

namespace App;

interface ImageSpecInterface
{
    public function mimeType() : string;
    public function assertStream($stream) : void;
}

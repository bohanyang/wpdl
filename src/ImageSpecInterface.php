<?php

namespace App;

interface ImageSpecInterface
{
    public function mimeType() : string;
    public function assertStream($stream) : void;
    public function assertFile(string $path) : void;
    public function assertBinary(string $data) : void;
}

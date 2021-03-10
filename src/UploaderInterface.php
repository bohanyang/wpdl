<?php

namespace App;

interface UploaderInterface
{
    public function __invoke(string $path, $contents, string $contentType) : callable;
}

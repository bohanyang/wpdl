<?php

namespace App;

class ReplicateUploader implements UploaderInterface
{
    /** @var UploaderInterface[] */
    private array $replicas;

    public function __construct(UploaderInterface ...$replicas)
    {
        $this->replicas = $replicas;
    }

    public function __invoke(string $path, $contents, string $contentType) : callable
    {
        $results = [];

        foreach ($this->replicas as $replica) {
            $results[] = $replica($path, \is_resource($contents) ? StreamShallowCopy::create($contents) : $contents, $contentType);
        }

        return function () use ($results) {
            foreach ($results as $result) {
                $result();
            }
        };
    }
}

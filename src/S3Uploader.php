<?php

namespace App;

use AsyncAws\Core\Result;
use AsyncAws\S3\S3Client;
use Symfony\Contracts\HttpClient\ResponseInterface;

class S3Uploader implements UploaderInterface
{
    public function __construct(
        private S3Client $client,
        private string $bucket,
        private string $prefix,
        private array $options
    )
    {
    }

    public function __invoke(string $path, $contents, string $contentType) : callable
    {
        $options = [
            'Bucket' => $this->bucket,
            'Key' => $this->prefix . $path,
            'Body' => $contents,
            'ContentType' => $contentType
        ];

        $result = $this->client->putObject($options + $this->options);

        return function () use ($result) {
            /** @var ResponseInterface $response */
            $response = $result->info()['response'];
            $response->getContent();
        };
    }
}
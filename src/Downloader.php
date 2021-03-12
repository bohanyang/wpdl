<?php

namespace App;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function Safe\{fclose, fopen, substr};

class Downloader
{
    public const ENDPOINT = 'https://www.bing.com/th?id=OHR.';

    /** @param ImageSpec[] $specs */
    public function __construct(
        private HttpClientInterface $httpClient,
        private UploaderInterface $uploader,
        private array $specs
    )
    {
    }

    public function __invoke(string $urlBase) : void
    {
        if (!\str_starts_with($urlBase, $prefix = '/az/hprichbg/rb/')) {
            throw new \UnexpectedValueException("Got unexpected URL base: $urlBase");
        }

        $urlBase = substr($urlBase, \strlen($prefix));
        $responsePool = new \SplObjectStorage();

        foreach ($this->specs as $urlSuffix => $spec) {
            $response = $this->httpClient->request('GET', self::ENDPOINT . $urlBase . $urlSuffix, [
                'buffer' => $stream = fopen('php://temp', 'w+')
            ]);

            $responsePool[$response] = [$urlSuffix, $stream];
        }

        $results = [];

        /** @var ResponseInterface $response */
        foreach ($responsePool as $response) {
            [$urlSuffix, $stream] = $responsePool[$response];
            $data = $response->getContent();

            if (\is_resource($stream)) {
                fclose($stream);
            }

            $spec = $this->specs[$urlSuffix];
            $spec->assertBinary($data);
            $results[] = ($this->uploader)($urlBase . $urlSuffix, $data, $spec->mimeType());
        }

        foreach ($results as $result) {
            $result();
        }
    }
}
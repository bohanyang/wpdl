<?php

namespace App;

use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use function Safe\substr;

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
        if (!\str_starts_with($urlBase, '/az/hprichbg/rb/')) {
            throw new \UnexpectedValueException("Got unexpected URL base: $urlBase");
        }

        $urlBase = substr($urlBase, 16);
        $responsePool = new \SplObjectStorage();

        foreach ($this->specs as $urlSuffix => $spec) {
            $response = $this->httpClient->request('GET', self::ENDPOINT . $urlBase . $urlSuffix);
            $responsePool[$response] = $urlSuffix;
        }

        $length = \count($responsePool);
        $results = [];

        /** @var ResponseInterface $response */
        foreach ($this->httpClient->stream($responsePool) as $response => $chunk) {
            if ($chunk->isFirst()) {
                $response->getHeaders();
                if ($response->getStatusCode() !== 200) {
                    throw new \UnexpectedValueException('Got non-200 status for request: ' . $response->getInfo('url'));
                }
                $urlSuffix = $responsePool[$response];
                $spec = $this->specs[$urlSuffix];
                $spec->assertStream($stream = StreamWrapper::createResource($response));
                $results[] = ($this->uploader)($urlBase . $urlSuffix, $stream, $spec->mimeType());
                if (--$length === 0) {
                    break;
                }
            }
        }

        foreach ($results as $result) {
            $result();
        }
    }
}
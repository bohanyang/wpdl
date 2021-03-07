<?php

use App\LeanCloud;
use AsyncAws\S3\S3Client;
use AsyncAws\SimpleS3\SimpleS3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Config;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

require __DIR__ . '/vendor/autoload.php';

$logger = new Logger('app', [new StreamHandler('php://stderr')]);
$httpClient = new CurlHttpClient();
$httpClient->setLogger(new Logger('http', [new StreamHandler('php://stderr')]));

$leancloud = new LeanCloud(
    client: $httpClient,
    endpoint: \getenv('LEANCLOUD_API_SERVER') . '/1.1/',
    appId: \getenv('LEANCLOUD_APP_ID'),
    appKey: \getenv('LEANCLOUD_APP_KEY'),
    sessionToken: \getenv('LEANCLOUD_SESSION_TOKEN')
);

$s3 = new SimpleS3Client(
    configuration: [
    'endpoint' => \getenv('S3_ENDPOINT'),
    'accessKeyId' => \getenv('AWS_ACCESS_KEY_ID'),
    'accessKeySecret' => \getenv('AWS_SECRET_ACCESS_KEY'),
    'region' => \getenv('AWS_DEFAULT_REGION'),
    'pathStyleEndpoint' => true,
    'sendChunkedBody' => false
],
    httpClient: $httpClient,
    logger: new Logger('s3', [new StreamHandler('php://stderr')])
);

$dump = [
    'skip' => 0,
    'failures' => []
];

try {
    foreach ($leancloud->scan('Image') as $image) {
        $urlbase = substr($image['urlbase'], 16);
        $retryableClient = new RetryableHttpClient(
            $httpClient,
            new class implements RetryStrategyInterface {
                public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
                {
                    if ($exception !== null) {
                        return true;
                    }
                    $statusCode = $context->getStatusCode();
                    return $statusCode !== 200 && $statusCode !== 404;
                }

                public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
                {
                    return 1000;
                }
            },
            1,
            new Logger('retry', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()])
        );
        try {
            $uhdResponse = $retryableClient->request('GET', "https://www.bing.com/th?id=OHR.${urlbase}_UHD.jpg", [
                'max_redirects' => 0
            ]);
            if ($uhdResponse->getStatusCode() === 200) {
                $s3->upload(
                    bucket: 'bing-1251008007',
                    key: "az/hprichbg/rb/${urlbase}_UHD.jpg",
                    object: StreamWrapper::createResource($uhdResponse, $retryableClient),
                    options: [
                        'CacheControl' => 'max-age=31536000',
                        'ContentType' => 'image/jpeg'
                    ]
                );
            } else {
                $dump['failures'][] = $image['name'];
            }
        } catch (Throwable $e) {
            $dump['failures'][] = $image;
            $logger->error($e->getMessage());
        }
        ++$dump['skip'];
    }
} finally {
    file_put_contents(
        filename: __DIR__ . '/dump.json',
        data: json_encode($dump, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

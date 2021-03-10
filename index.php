<?php

namespace App;

use AsyncAws\Core\HttpClient\AwsRetryStrategy;
use AsyncAws\S3\S3Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Safe\substr;

require __DIR__ . '/vendor/autoload.php';

function videoDownloader(HttpClientInterface $httpClient, string $videoUrl, UploaderInterface $uploader)
{
    if (
        !\str_starts_with($videoUrl, '//az29176.vo.msecnd.net/videocontent/') ||
        !\str_ends_with($videoUrl, '.mp4')
    ) {
        throw new \UnexpectedValueException("Got unexpected video URL: $videoUrl");
    }

    $response = $httpClient->request('GET', "https:$videoUrl");
    $response->getHeaders();

    if ($response->getStatusCode() !== 200) {
        throw new \UnexpectedValueException('Got non-200 status for request: ' . $response->getInfo('url'));
    }

    $uploader(substr($videoUrl, 37), StreamWrapper::createResource($response), 'video/mp4')();
}

$httpClient = HttpClient::create(['max_redirects' => 0]);
$httpLogger = new Logger('http', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()]);

if ($httpClient instanceof LoggerAwareInterface) {
    $httpClient->setLogger($httpLogger);
}

$s3RetryCodes = GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES;
$retryCodes[408] = GenericRetryStrategy::IDEMPOTENT_METHODS;
$s3RetryCodes[404] = 404; // Bucket not found
$s3HttpClient = new RetryableHttpClient($httpClient, new AwsRetryStrategy($s3RetryCodes), 3, $httpLogger);

$s3r1 = new S3Client(
    [
        'endpoint' => \getenv('S3_ENDPOINT'),
        'accessKeyId' => \getenv('AWS_ACCESS_KEY_ID'),
        'accessKeySecret' => \getenv('AWS_SECRET_ACCESS_KEY'),
        'region' => \getenv('AWS_DEFAULT_REGION'),
        'pathStyleEndpoint' => true,
        'sendChunkedBody' => false
    ],
    httpClient: $s3HttpClient,
    logger: new Logger('s3r1', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()])
);

$s3r2 = new S3Client(
    [
        'endpoint' => \getenv('S3_ENDPOINT_2'),
        'accessKeyId' => \getenv('AWS_ACCESS_KEY_ID_2'),
        'accessKeySecret' => \getenv('AWS_SECRET_ACCESS_KEY_2'),
        'region' => \getenv('AWS_DEFAULT_REGION_2'),
        'pathStyleEndpoint' => true,
        'sendChunkedBody' => false
    ],
    httpClient: $s3HttpClient,
    logger: new Logger('s3r2', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()])
);

$uploader = new ReplicateUploader(
    new S3Uploader($s3r1, \getenv('S3_BUCKET'), 'az/hprichbg/rb/', [
        'CacheControl' => 'max-age=31536000'
    ]),
    new S3Uploader($s3r2, \getenv('S3_BUCKET_2'), 'az/hprichbg/rb/', [
        'CacheControl' => 'max-age=31536000',
        'ACL' => 'public-read'
    ])
);

$videoUploader = new ReplicateUploader(
    new S3Uploader($s3r1, \getenv('S3_BUCKET'), 'videocontent/', [
        'CacheControl' => 'max-age=31536000'
    ]),
    new S3Uploader($s3r2, \getenv('S3_BUCKET_2'), 'videocontent/', [
        'CacheControl' => 'max-age=31536000',
        'ACL' => 'public-read'
    ])
);

$specs = [
    '_800x480.jpg' => new ImageSpec(800, 480),
    '_480x800.jpg' => new ImageSpec(480, 800),
    '_1366x768.jpg' => new ImageSpec(1366, 768),
    '_768x1280.jpg' => new ImageSpec(768, 1280),
    '_1920x1080.jpg' => new ImageSpec(1920, 1080),
    '_1080x1920.jpg' => new ImageSpec(1080, 1920),
    '_UHD.jpg' => new UhdImageSpec(),
];

$specsExtra = $specs;
$specsExtra['_1920x1200.jpg'] = new ImageSpec(1920, 1200);

$retryCodes = GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES;
$retryCodes[408] = GenericRetryStrategy::IDEMPOTENT_METHODS;
$httpClient = new RetryableHttpClient($httpClient, new GenericRetryStrategy($retryCodes), 3, $httpLogger);

$downloader = new Downloader($httpClient, $uploader, $specs);
$downloaderExtra = new Downloader($httpClient, $uploader, $specsExtra);

$database = new LeanCloud(
    $httpClient,
    \getenv('LEANCLOUD_API_SERVER') . '/1.1/',
    \getenv('LEANCLOUD_APP_ID'),
    \getenv('LEANCLOUD_APP_KEY'),
    \getenv('LEANCLOUD_SESSION_TOKEN')
);

$images = $database->query('Image', ['where' => ['available' => false]])['results'];

foreach ($images as $image) {
    ($image['wp'] ? $downloaderExtra : $downloader)($image['urlbase']);

    if (null !== $videoUrl = $image['vid']['sources'][1][2] ?? null) {
        videoDownloader($httpClient, $videoUrl, $videoUploader);
    }

    $database->update('Image', $image['objectId'], ['available' => true]);
}

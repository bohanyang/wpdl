<?php

namespace App;

use AsyncAws\S3\S3Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Safe\{fclose, fopen, substr};

require __DIR__ . '/vendor/autoload.php';

if (\is_file(__DIR__ . '/.env')) {
    (new Dotenv())->bootEnv(__DIR__ . '/.env');
}

function videoDownloader(HttpClientInterface $httpClient, string $videoUrl, UploaderInterface $uploader)
{
    if (
        !\str_starts_with($videoUrl, $prefix = '//az29176.vo.msecnd.net/videocontent/') ||
        !\str_ends_with($videoUrl, '.mp4')
    ) {
        throw new \UnexpectedValueException("Got unexpected video URL: $videoUrl");
    }

    $response = $httpClient->request('GET', "https:$videoUrl", [
        'buffer' => $stream = fopen('php://temp', 'w+')
    ]);

    $data = $response->getContent();

    if (\is_resource($stream)) {
        fclose($stream);
    }

    $uploader(substr($videoUrl, \strlen($prefix)), $data, 'video/mp4')();
}

$httpClient = HttpClient::create(['max_redirects' => 0]);
$httpLogger = new Logger('http', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()]);

if ($httpClient instanceof LoggerAwareInterface) {
    $httpClient->setLogger($httpLogger);
}

$s3RetryCodes = GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES;
$retryCodes[408] = GenericRetryStrategy::IDEMPOTENT_METHODS;
$s3RetryCodes[404] = 404; // Bucket not found
$s3HttpClient = new RetryableHttpClient($httpClient, new S3Track404($httpLogger, $s3RetryCodes), 3, $httpLogger);

$s3_1 = new S3Client(
    [
        'endpoint' => $_SERVER['S3_ENDPOINT'],
        'accessKeyId' => $_SERVER['AWS_ACCESS_KEY_ID'],
        'accessKeySecret' => $_SERVER['AWS_SECRET_ACCESS_KEY'],
        'region' => $_SERVER['AWS_DEFAULT_REGION'],
        'pathStyleEndpoint' => true,
        'sendChunkedBody' => false
    ],
    httpClient: $s3HttpClient,
    logger: new Logger('s3_1', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()])
);

$s3_2 = new S3Client(
    [
        'endpoint' => $_SERVER['S3_ENDPOINT_2'],
        'accessKeyId' => $_SERVER['AWS_ACCESS_KEY_ID_2'],
        'accessKeySecret' => $_SERVER['AWS_SECRET_ACCESS_KEY_2'],
        'region' => $_SERVER['AWS_DEFAULT_REGION_2'],
        'pathStyleEndpoint' => true,
        'sendChunkedBody' => false
    ],
    httpClient: $s3HttpClient,
    logger: new Logger('s3_2', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()])
);

$s3_3 = new S3Client(
    [
        'endpoint' => $_SERVER['S3_ENDPOINT_3'],
        'accessKeyId' => $_SERVER['AWS_ACCESS_KEY_ID_3'],
        'accessKeySecret' => $_SERVER['AWS_SECRET_ACCESS_KEY_3'],
        'region' => $_SERVER['AWS_DEFAULT_REGION_3'],
        'pathStyleEndpoint' => true,
        'sendChunkedBody' => false
    ],
    httpClient: $s3HttpClient,
    logger: new Logger('s3_3', [new StreamHandler('php://stderr')], [new PsrLogMessageProcessor()])
);

$uploader = new ReplicateUploader(
    new S3Uploader($s3_1, $_SERVER['S3_BUCKET'], 'az/hprichbg/rb/', [
        'CacheControl' => 'max-age=31536000'
    ]),
    new S3Uploader($s3_2, $_SERVER['S3_BUCKET_2'], 'az/hprichbg/rb/', [
        'CacheControl' => 'max-age=31536000',
        'ACL' => 'public-read'
    ]),
    new S3Uploader($s3_3, $_SERVER['S3_BUCKET_3'], 'az/hprichbg/rb/', [])
);

$videoUploader = new ReplicateUploader(
    new S3Uploader($s3_1, $_SERVER['S3_BUCKET'], 'videocontent/', [
        'CacheControl' => 'max-age=31536000'
    ]),
    new S3Uploader($s3_2, $_SERVER['S3_BUCKET_2'], 'videocontent/', [
        'CacheControl' => 'max-age=31536000',
        'ACL' => 'public-read'
    ]),
    new S3Uploader($s3_3, $_SERVER['S3_BUCKET_3'], 'videocontent/', [])
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
    $_SERVER['LEANCLOUD_API_SERVER'] . '/1.1/',
    $_SERVER['LEANCLOUD_APP_ID'],
    $_SERVER['LEANCLOUD_APP_KEY'],
    $_SERVER['LEANCLOUD_SESSION_TOKEN']
);

$images = $database->query('Image', ['where' => ['available' => false]])['results'];

foreach ($images as $image) {
    ($image['wp'] ? $downloaderExtra : $downloader)($image['urlbase']);

    if (null !== $videoUrl = $image['vid']['sources'][1][2] ?? null) {
        videoDownloader($httpClient, $videoUrl, $videoUploader);
    }

    $database->update('Image', $image['objectId'], ['available' => true]);
}

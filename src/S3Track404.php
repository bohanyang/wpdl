<?php

namespace App;

use AsyncAws\Core\HttpClient\AwsRetryStrategy;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class S3Track404 extends AwsRetryStrategy
{
    public function __construct(
        private LoggerInterface $logger,
        array $statusCodes = self::DEFAULT_RETRY_STATUS_CODES,
        int $delayMs = 1000, float $multiplier = 2.0, int $maxDelayMs = 0, float $jitter = 0.1
    )
    {
        parent::__construct($statusCodes, $delayMs, $multiplier, $maxDelayMs, $jitter);
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception) : ?bool
    {
        $shouldRetry = parent::shouldRetry($context, $responseContent, $exception);

        if ($shouldRetry) {
            $this->logger->debug('', [
                'status' => $context->getStatusCode(),
                'headers' => $context->getHeaders(),
                'body' => $responseContent
            ]);
        }

        return $shouldRetry;
    }
}
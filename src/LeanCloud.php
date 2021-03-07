<?php

namespace App;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LeanCloud
{
    private array $options;

    public function __construct(
        private HttpClientInterface $client,
        string $endpoint,
        string $appId,
        string $appKey,
        string $sessionToken
    )
    {
        $this->options = [
            'base_uri' => $endpoint,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-LC-Id' => $appId,
                'X-LC-Key' => $appKey,
                'X-LC-Session' => $sessionToken
            ]
        ];
    }

    private static function encodeQuery(iterable $query)
    {
        foreach ($query as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
                continue;
            }

            if (!is_scalar($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $query[$key] = $value;
        }

        return $query;
    }

    public function query(string $collection, iterable $query) : array
    {
        return $this->client->request('GET', "classes/$collection", [
            'query' => self::encodeQuery($query)
        ] + $this->options)->toArray();
    }

    public function scan(string $collection) : \Iterator
    {
        $limit = 1000;
        $skip = 0;
        do {
            $response = $this->query($collection, [
                'limit' => $limit,
                'where' => [
                    'createdAt' => [
                        '$gte' => [
                            '__type' => 'Date',
                            'iso' => '2019-04-14T13:02:49.436Z'
                        ]
                    ]
                ],
                'order' => 'createdAt',
                'skip' => $skip
            ]);
            yield from $response['results'];
            $skip += $limit;
        } while (count($response['results']) === $limit);
    }
}

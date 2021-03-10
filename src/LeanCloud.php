<?php

namespace App;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Safe\json_encode;

class LeanCloud
{
    private array $options;

    public function __construct(
        private HttpClientInterface $client,
        string $endpoint,
        string $appId,
        string $appKey,
        string $sessionToken = ''
    )
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-LC-Id' => $appId,
            'X-LC-Key' => $appKey
        ];

        if ($sessionToken !== '') {
            $headers['X-LC-Session'] = $sessionToken;
        }

        $this->options = ['base_uri' => $endpoint, 'headers' => $headers];
    }

    private static function encodeQuery(array $query)
    {
        foreach ($query as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
                continue;
            }

            if (!is_scalar($value)) {
                $value = self::jsonEncode($value);
            }

            $query[$key] = $value;
        }

        return $query;
    }

    public function query(string $collection, array $query) : array
    {
        return $this->client->request('GET', "classes/$collection", [
                'query' => self::encodeQuery($query)
            ] + $this->options)->toArray();
    }

    public function update(string $collection, string $objectId, array $object) : array
    {
        return $this->client->request('PUT', "classes/$collection/$objectId", [
                'body' => self::jsonEncode($object)
            ] + $this->options)->toArray();
    }

    private static function jsonEncode(array $value)
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

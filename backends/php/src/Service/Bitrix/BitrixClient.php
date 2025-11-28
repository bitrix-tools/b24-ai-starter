<?php

namespace App\Service\Bitrix;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class BitrixClient
{
    private HttpClientInterface $client;
    private string $domain;
    private string $accessToken;
    private LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $client,
        string $domain,
        string $accessToken,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->domain = $domain;
        $this->accessToken = $accessToken;
        $this->logger = $logger;
    }

    /**
     * Fetch items from Smart Process (entityTypeId: 1164) with pagination.
     *
     * @param array $filter
     * @param int $limit Max records to fetch (default 1500)
     * @return array
     */
    public function fetchSmartProcessItems(array $filter = [], int $limit = 1500): array
    {
        $items = [];
        $start = 0;
        $batchSize = 50;

        while (count($items) < $limit) {
            $response = $this->call('crm.item.list', [
                'entityTypeId' => 1164,
                'filter' => $filter,
                'start' => $start,
                'limit' => $batchSize,
            ]);

            if (empty($response['result']['items'])) {
                break;
            }

            $fetchedItems = $response['result']['items'];
            $items = array_merge($items, $fetchedItems);

            // If we received fewer items than requested, it means we reached the end
            if (count($fetchedItems) < $batchSize) {
                break;
            }

            $start += count($fetchedItems);
            
            // Safety break to avoid infinite loops if something goes wrong
            if ($start >= $limit) {
                break;
            }
        }

        // Trim to limit if we over-fetched
        return array_slice($items, 0, $limit);
    }

    /**
     * Generic method to call Bitrix24 API using OAuth token
     */
    public function call(string $method, array $params = []): array
    {
        try {
            // Build API URL: https://{domain}/rest/{method}
            $url = sprintf('https://%s/rest/%s', $this->domain, $method);
            
            // Add access token to parameters
            $params['auth'] = $this->accessToken;

            $response = $this->client->request('POST', $url, [
                'json' => $params,
            ]);

            $data = $response->toArray();
            
            // Debug logging
            error_log('Bitrix24 Response for ' . $method . ': ' . json_encode($data));

            if (isset($data['error'])) {
                throw new \RuntimeException('Bitrix24 API Error: ' . ($data['error_description'] ?? $data['error']));
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Bitrix24 API Request Failed', [
                'method' => $method,
                'domain' => $this->domain,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

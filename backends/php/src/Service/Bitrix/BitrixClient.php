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
    /**
     * Fetch all items from a list method with pagination.
     *
     * @param string $method API method (e.g. 'user.get', 'crm.item.list')
     * @param array $params Initial parameters
     * @param int $limit Max records to fetch (default 5000)
     * @return array
     */
    public function fetchAll(string $method, array $params = [], int $limit = 5000): array
    {
        $items = [];
        $start = 0;
        $batchSize = 50;

        while (count($items) < $limit) {
            $params['start'] = $start;
            // Some methods use 'limit', others might rely on batch size implicitly, but 'user.get' doesn't support 'limit' param in all versions, 
            // usually it's standard list navigation. 'crm.item.list' uses 'limit'.
            // For safety, we won't force 'limit' unless we know the method supports it, 
            // but for 'user.get' it's usually just 'start'.
            // However, providing 'filter' is common.
            
            $response = $this->call($method, $params);

            if (empty($response['result'])) {
                break;
            }
            
            // Result can be array of items or array with 'items' key depending on method
            $fetchedItems = $response['result'];
            if (isset($fetchedItems['items'])) {
                $fetchedItems = $fetchedItems['items'];
            }

            if (empty($fetchedItems)) {
                break;
            }

            $items = array_merge($items, $fetchedItems);

            // If we received fewer items than batch size (50 is default), we are done
            if (count($fetchedItems) < $batchSize) {
                break;
            }

            $start += count($fetchedItems);
            
            if (isset($response['next'])) {
                $start = $response['next'];
            } elseif (!isset($response['total']) || $start >= $response['total']) {
                // If no 'next' and we can't determine total, rely on count < 50 check above
                // But for user.get, 'next' is often not returned if total is small.
            }
            
            if ($start >= $limit) {
                break;
            }
        }

        return array_slice($items, 0, $limit);
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
        // Use the new generic fetchAll but adapted for crm.item.list structure
        // crm.item.list returns { result: { items: [...] } }
        // fetchAll handles this structure.
        return $this->fetchAll('crm.item.list', [
            'entityTypeId' => 1164,
            'filter' => $filter,
        ], $limit);
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

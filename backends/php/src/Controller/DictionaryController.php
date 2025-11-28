<?php

namespace App\Controller;

use App\Service\Bitrix\BitrixClient;
use App\Service\JwtService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DictionaryController extends AbstractController
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    #[Route('/api/users', name: 'api_users', methods: ['GET'])]
    public function getUsers(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        if (!$payload) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $domain = $payload['domain'];
        $accessToken = $payload['access_token'] ?? null;

        if (!$accessToken) {
            return new JsonResponse(['error' => 'Access token not found in JWT'], 401);
        }

        try {
            $bitrixClient = new BitrixClient($this->httpClient, $domain, $accessToken, $this->logger);
            
            // Fetch users
            // user.get returns list of users
            $response = $bitrixClient->call('user.get', [
                'FILTER' => ['ACTIVE' => 'Y']
            ]);

            $users = array_map(function($user) {
                return [
                    'id' => $user['ID'],
                    'name' => trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? '')),
                    'avatar' => $user['PERSONAL_PHOTO'] ?? null // Photo URL might need processing
                ];
            }, $response['result'] ?? []);

            return new JsonResponse(['items' => $users]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch users: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to fetch users'], 500);
        }
    }

    #[Route('/api/debug/schema', name: 'api_debug_schema', methods: ['GET'])]
    public function debugSchema(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        if (!$payload) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $domain = $payload['domain'];
        $accessToken = $payload['access_token'] ?? null;

        try {
            $bitrixClient = new BitrixClient($this->httpClient, $domain, $accessToken, $this->logger);
            
            // Fetch Smart Process Types
            $response = $bitrixClient->call('crm.type.list', []);

            return new JsonResponse(['types' => $response['result'] ?? []]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch schema: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to fetch schema: ' . $e->getMessage()], 500);
        }
    }
}

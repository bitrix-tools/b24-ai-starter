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
            // Use fetchAll to get all users, not just first 50
            $response = $bitrixClient->fetchAll('user.get', [
                'FILTER' => ['ACTIVE' => 'Y']
            ]);
            
            // fetchAll returns items directly
            $usersList = $response;

            $users = array_map(function($user) {
                return [
                    'id' => $user['ID'],
                    'name' => trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? '')),
                    'avatar' => $user['PERSONAL_PHOTO'] ?? null // Photo URL might need processing
                ];
            }, $usersList);

            return new JsonResponse(['items' => $users]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/projects', name: 'api_projects', methods: ['GET'])]
    public function getProjects(Request $request): JsonResponse
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

            // Fetch active projects (workgroups)
            // sonet_group.get
            $response = $bitrixClient->fetchAll('sonet_group.get', [
                'FILTER' => ['ACTIVE' => 'Y', 'CLOSED' => 'N'],
                'ORDER' => ['NAME' => 'ASC']
            ]);
            
            $projectsList = $response;

            $projects = array_map(function($group) {
                return [
                    'id' => $group['ID'],
                    'name' => $group['NAME'],
                ];
            }, $projectsList);

            return new JsonResponse(['items' => $projects]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
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

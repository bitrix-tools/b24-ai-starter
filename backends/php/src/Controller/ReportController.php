<?php

namespace App\Controller;

use App\Service\Report\ReportDataService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportDataService $reportService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/reports/data', name: 'api_reports_data', methods: ['GET'])]
    public function getData(Request $request): JsonResponse
    {
        $this->logger->debug('ReportController.getData.start', [
            'query' => $request->query->all(),
        ]);

        try {
            // Extract OAuth credentials from JWT payload
            $jwtPayload = $request->attributes->get('jwt_payload');
            if (!$jwtPayload) {
                return new JsonResponse([
                    'error' => 'Missing JWT payload',
                    'message' => 'This endpoint requires authentication. Please ensure you are accessing it from within the Bitrix24 application.',
                ], 401);
            }

            $domain = $jwtPayload['domain'] ?? null;
            $accessToken = $jwtPayload['access_token'] ?? null;

            if (!$domain || !$accessToken) {
                return new JsonResponse([
                    'error' => 'Invalid JWT payload',
                    'message' => 'JWT payload must contain domain and access_token',
                    'payload' => $jwtPayload, // Debug info
                ], 401);
            }

            $filter = [];

            // Date Range - using reflection date field
            $dateFrom = $request->query->get('dateFrom');
            $dateTo = $request->query->get('dateTo');

            if ($dateFrom) {
                $filter['>=ufCrm87_1764446274'] = $dateFrom;
            }
            if ($dateTo) {
                $filter['<=ufCrm87_1764446274'] = $dateTo;
            }

            // Employee
            $employeeId = $request->query->get('employeeId');
            if ($employeeId) {
                $filter['=assignedById'] = $employeeId;
            }

            // Project (Name)
            $projectName = $request->query->get('projectName');
            if ($projectName) {
                // Assuming exact match for now
                $filter['=ufCrm87_1764265641'] = $projectName;
            }

            // Project (ID)
            $projectId = $request->query->get('projectId');
            if ($projectId) {
                $filter['=ufCrm87_1764265626'] = $projectId;
            }

            // Fetch Data with OAuth credentials
            $data = $this->reportService->getReportData($domain, $accessToken, $filter);

            return new JsonResponse([
                'items' => $data,
                'count' => count($data),
            ]);

        } catch (\Throwable $throwable) {
            $this->logger->error('ReportController.getData.error', [
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => $throwable->getMessage(),
                'type' => get_class($throwable),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ], 500);
        }
    }
}

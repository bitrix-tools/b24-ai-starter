<?php

namespace App\Controller;

use App\Service\Excel\ExcelExportService;
use App\Service\Report\ReportDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/export')]
class ExcelController extends AbstractController
{
    public function __construct(
        private readonly ExcelExportService $excelExportService,
        private readonly ReportDataService $reportDataService
    ) {}
    
    #[Route('/employees', name: 'export_employees', methods: ['GET'])]
    public function exportEmployees(Request $request): Response
    {
        $token = $this->extractJwtFromRequest($request);
        if (!$token) {
            return $this->json(['error' => 'Missing JWT token'], Response::HTTP_UNAUTHORIZED);
        }
        
        $payload = $this->decodeJwt($token);
        $domain = $payload['domain'] ?? null;
        $accessToken = $payload['access_token'] ?? null;
        
        if (!$domain || !$accessToken) {
            return $this->json(['error' => 'Invalid JWT payload'], Response::HTTP_UNAUTHORIZED);
        }
        
        // Get filters from query params
        $filter = [
            'employeeId' => $request->query->get('employeeId', ''),
            'projectId' => $request->query->get('projectId', ''),
            'dateFrom' => $request->query->get('dateFrom', ''),
            'dateTo' => $request->query->get('dateTo', ''),
        ];
        
        $data = $this->reportDataService->getReportData($domain, $accessToken, $filter);
        
        return $this->excelExportService->exportEmployeesReport($data);
    }
    
    #[Route('/projects', name: 'export_projects', methods: ['GET'])]
    public function exportProjects(Request $request): Response
    {
        $token = $this->extractJwtFromRequest($request);
        if (!$token) {
            return $this->json(['error' => 'Missing JWT token'], Response::HTTP_UNAUTHORIZED);
        }
        
        $payload = $this->decodeJwt($token);
        $domain = $payload['domain'] ?? null;
        $accessToken = $payload['access_token'] ?? null;
        
        if (!$domain || !$accessToken) {
            return $this->json(['error' => 'Invalid JWT payload'], Response::HTTP_UNAUTHORIZED);
        }
        
        // Get filters from query params
        $filter = [
            'employeeId' => $request->query->get('employeeId', ''),
            'projectId' => $request->query->get('projectId', ''),
            'dateFrom' => $request->query->get('dateFrom', ''),
            'dateTo' => $request->query->get('dateTo', ''),
        ];
        
        $data = $this->reportDataService->getReportData($domain, $accessToken, $filter);
        
        return $this->excelExportService->exportProjectsReport($data);
    }
    
    private function extractJwtFromRequest(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    private function decodeJwt(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }
        
        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        return json_decode($payload, true) ?: [];
    }
}

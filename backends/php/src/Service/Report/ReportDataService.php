<?php

namespace App\Service\Report;

use App\Service\Bitrix\BitrixClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReportDataService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function getReportData(string $domain, string $accessToken, array $filter = []): array
    {
        // Create BitrixClient with OAuth credentials
        $bitrixClient = new BitrixClient($this->httpClient, $domain, $accessToken, $this->logger);
        
        // Step 0: Fetch data
        $rawItems = $bitrixClient->fetchSmartProcessItems($filter);
        
        $normalizedItems = [];
        
        foreach ($rawItems as $item) {
            // Step 1: Validate and Normalize
            $normalized = $this->normalizeItem($item);
            if ($normalized) {
                $normalizedItems[] = $normalized;
            }
        }

        return $normalizedItems;
    }

    private function normalizeItem(array $item): ?array
    {
        // Field Mapping (based on Master_poley.md and Docs.md)
        // Note: Keys might be camelCase in the response
        $fieldIdZadachi = 'ufCrm87_1761919581';
        $fieldIdIerarhiya = 'ufCrm87_1764191110';
        $fieldTitleIerarhiya = 'ufCrm87_1764191133';
        $fieldProjectName = 'ufCrm87_1764265641'; // Assuming camelCase for UF_CRM_87_1764265641
        $fieldUchityvat = 'ufCrm87_1763717129'; // Boolean/Enum
        $fieldHours = 'ufCrm87_1761919617';
        $fieldSotrudnik = 'assignedById'; // Standard field

        // Helper to get value case-insensitively if needed, but usually it's camelCase
        $getValue = fn($key) => $item[$key] ?? $item[strtoupper($key)] ?? null;

        // 1. Validate ID Zadachi
        $idZadachi = $getValue($fieldIdZadachi);
        if (empty($idZadachi)) {
            $this->logger->warning("Skipping item [{$item['id']}]: Missing task ID");
            return null;
        }

        // 2. Parse Hierarchy
        $idIerarhiyaRaw = $getValue($fieldIdIerarhiya);
        $titleIerarhiyaRaw = $getValue($fieldTitleIerarhiya);
        
        $idIerarhiya = [];
        $titleIerarhiya = [];

        try {
            if ($idIerarhiyaRaw) {
                $idIerarhiya = json_decode($idIerarhiyaRaw, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($titleIerarhiyaRaw) {
                $titleIerarhiya = json_decode($titleIerarhiyaRaw, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (\JsonException $e) {
            $this->logger->warning("Skipping item [{$item['id']}]: Invalid JSON in hierarchy fields");
            return null;
        }

        if (!is_array($idIerarhiya) || !is_array($titleIerarhiya)) {
             $this->logger->warning("Skipping item [{$item['id']}]: Hierarchy fields are not arrays");
             return null;
        }

        // 3. Determine Project
        $projectName = $getValue($fieldProjectName);
        if (empty($projectName)) {
            // Fallback to first element of hierarchy
            $projectName = $titleIerarhiya[0] ?? 'Не определён';
        }

        // 4. Date
        $createdTime = $item['createdTime'] ?? null;

        // 5. Type of Hours
        $uchityvat = $getValue($fieldUchityvat);
        // Check for various truthy values (Bitrix boolean can be 'Y', '1', true)
        $isUchityvat = $uchityvat === 'Y' || $uchityvat === '1' || $uchityvat === true || $uchityvat === 1;
        $type = $isUchityvat ? 'Учитываемые' : 'Неучитываемые';

        // 6. Task Name
        $taskName = end($titleIerarhiya);
        if ($taskName === false) {
            $taskName = 'Без названия';
        }

        // 7. Employee (Sotrudnik)
        // assignedById is usually an ID. We might need to fetch user details or it might be expanded.
        // For now, we'll store the ID. If we need the name, we might need a separate user cache or check if it's expanded.
        // Docs says "ФИО пользователя". Usually crm.item.list doesn't expand users by default unless specified.
        // We will return the ID for now, and maybe the frontend can map it, or we fetch users separately.
        // Or maybe 'ufCrm87_1761919601' is the employee field? Master_poley says `ufCrm87_1761919601` | Сотрудник | employee.
        // If it's a 'user' type field, it might return "12_User Name" or just ID.
        // Let's use assignedById as a fallback or primary if the custom field is empty.
        $sotrudnikId = $getValue('assignedById');
        
        return [
            'id' => $item['id'],
            'taskId' => $idZadachi,
            'taskName' => $taskName,
            'projectName' => $projectName,
            'hierarchyIds' => $idIerarhiya,
            'hierarchyTitles' => $titleIerarhiya,
            'hours' => (float)$getValue($fieldHours),
            'type' => $type,
            'date' => $createdTime,
            'employeeId' => $sotrudnikId,
            // 'raw' => $item // debug
        ];
    }
}

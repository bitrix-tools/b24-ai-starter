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
        $fieldTimeEntryTitle = 'title'; // Название метки
        $fieldIdZadachi = 'ufCrm87_1761919581';
        $fieldTaskTitle = 'ufCrm87_1764361585'; // Название задачи
        $fieldIdIerarhiya = 'ufCrm87_1764191110';
        $fieldTitleIerarhiya = 'ufCrm87_1764191133';
        $fieldProjectId = 'ufCrm87_1764265626'; // ID проекта
        $fieldProjectName = 'ufCrm87_1764265641'; // Название проекта
        $fieldUchityvat = 'ufCrm87_1763717129'; // Boolean/Enum
        $fieldHours = 'ufCrm87_1761919617';
        $fieldEmployee = 'ufCrm87_1761919601'; // Сотрудник с ФИО
        $fieldSotrudnik = 'assignedById'; // Standard field (fallback)
        $fieldReflectionDate = 'ufCrm87_1764446274'; // Дата отражения

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

        // 4. Reflection Date
        $reflectionDate = $getValue($fieldReflectionDate);
        if (empty($reflectionDate)) {
            // Fallback to createdTime if reflection date is not set
            $reflectionDate = $item['createdTime'] ?? null;
        }

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
        // Try custom employee field first (might contain name), fallback to assignedById
        $employeeField = $getValue($fieldEmployee);
        $employeeName = null;
        $employeeId = $getValue('assignedById');
        
        // If employee field contains name (format: "ID_Name" or just name)
        if ($employeeField && is_string($employeeField)) {
            if (strpos($employeeField, '_') !== false) {
                // Format: "123_John Doe"
                $parts = explode('_', $employeeField, 2);
                $employeeName = $parts[1] ?? null;
                $employeeId = $parts[0] ?? $employeeId;
            } else {
                // Just a name
                $employeeName = $employeeField;
            }
        }
        
        // 8. Time Entry Title and Task Title
        $timeEntryTitle = $getValue($fieldTimeEntryTitle) ?: null;
        $taskTitle = $getValue($fieldTaskTitle) ?: null;
        
        // 9. Project ID
        $projectId = $getValue($fieldProjectId) ?: null;
        
        return [
            'id' => $item['id'],
            'entryTitle' => $timeEntryTitle,
            'taskId' => $idZadachi,
            'taskName' => $taskName,
            'taskTitle' => $taskTitle,
            'projectId' => $projectId,
            'projectName' => $projectName,
            'hierarchyIds' => $idIerarhiya,
            'hierarchyTitles' => $titleIerarhiya,
            'hours' => (float)$getValue($fieldHours),
            'type' => $type,
            'date' => $reflectionDate,
            'employeeId' => $employeeId,
            'employeeName' => $employeeName,
            // 'raw' => $item // debug
        ];
    }
}

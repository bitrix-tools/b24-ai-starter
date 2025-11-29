<?php

namespace App\Service\Excel;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportService
{
    public function exportEmployeesReport(array $data): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Отчет по сотрудникам');
        
        // Headers
        $sheet->fromArray([
            ['Название задачи / Метка', 'ID задачи', 'Учитываемые', 'Неучитываемые', 'Всего', 'Дата']
        ], null, 'A1');
        
        $this->styleHeader($sheet, 'A1:F1');
        
        $row = 2;
        $empMap = $this->groupEmployeesReport($data);
        
        foreach ($empMap as $empData) {
            // Employee header
            $sheet->setCellValue("A{$row}", "👤 Сотрудник: {$empData['name']} (ID: {$empData['id']})");
            $sheet->mergeCells("A{$row}:B{$row}");
            $this->styleGroupHeader($sheet, "A{$row}:F{$row}");
            $row++;
            
            foreach ($empData['projects'] as $projData) {
                // Project header
                $sheet->setCellValue("A{$row}", "  📁 Проект: {$projData['name']}");
                $sheet->mergeCells("A{$row}:B{$row}");
                $row++;
                
                foreach ($projData['tasks'] as $taskData) {
                    // Task header
                    $sheet->setCellValue("A{$row}", "    📝 {$taskData['name']}");
                    $sheet->mergeCells("A{$row}:B{$row}");
                    $row++;
                    
                    // Entries
                    foreach ($taskData['entries'] as $entry) {
                        $billable = $entry['type'] === 'Учитываемые' ? $entry['hours'] : '';
                        $nonBillable = $entry['type'] === 'Неучитываемые' ? $entry['hours'] : '';
                        
                        $sheet->fromArray([[
                            "      ⏱ " . ($entry['entryTitle'] ?: 'Метка #' . $entry['id']),
                            $entry['taskId'],
                            $billable,
                            $nonBillable,
                            $entry['hours'],
                            date('d.m.Y', strtotime($entry['date']))
                        ]], null, "A{$row}");
                        $row++;
                    }
                    
                    // Task total
                    $sheet->fromArray([[
                        "    Итого по задаче:",
                        '',
                        $taskData['billableHours'],
                        $taskData['nonBillableHours'],
                        $taskData['totalHours'],
                        ''
                    ]], null, "A{$row}");
                    $this->styleTotalRow($sheet, "A{$row}:F{$row}");
                    $row++;
                }
                
                // Project total
                $sheet->fromArray([[
                    "  Итого по проекту:",
                    '',
                    $projData['billableHours'],
                    $projData['nonBillableHours'],
                    $projData['totalHours'],
                    ''
                ]], null, "A{$row}");
                $this->styleTotalRow($sheet, "A{$row}:F{$row}");
                $row++;
            }
            
            // Employee total
            $sheet->fromArray([[
                "Итого по сотруднику:",
                '',
                $empData['billableHours'],
                $empData['nonBillableHours'],
                $empData['totalHours'],
                ''
            ]], null, "A{$row}");
            $this->styleTotalRow($sheet, "A{$row}:F{$row}", true);
            $row += 2; // spacing
        }
        
        $this->autoSizeColumns($sheet, ['A', 'B', 'C', 'D', 'E', 'F']);
        
        return $this->createResponse($spreadsheet, 'Отчет_по_сотрудникам.xlsx');
    }
    
    public function exportProjectsReport(array $data): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Отчет по проектам');
        
        // Headers
        $sheet->fromArray([
            ['Название задачи / Метка', 'ID задачи', 'Учитываемые', 'Неучитываемые', 'Всего', 'Дата']
        ], null, 'A1');
        
        $this->styleHeader($sheet, 'A1:F1');
        
        $row = 2;
        $projMap = $this->groupProjectsReport($data);
        
        foreach ($projMap as $projData) {
            // Project header
            $sheet->setCellValue("A{$row}", "📁 Проект: {$projData['name']}");
            $sheet->mergeCells("A{$row}:B{$row}");
            $this->styleGroupHeader($sheet, "A{$row}:F{$row}");
            $row++;
            
            foreach ($projData['employees'] as $empData) {
                // Employee header
                $sheet->setCellValue("A{$row}", "  👤 Сотрудник: {$empData['name']} (ID: {$empData['id']})");
                $sheet->mergeCells("A{$row}:B{$row}");
                $row++;
                
                foreach ($empData['tasks'] as $taskData) {
                    // Task header
                    $sheet->setCellValue("A{$row}", "    📝 {$taskData['name']}");
                    $sheet->mergeCells("A{$row}:B{$row}");
                    $row++;
                    
                    // Entries
                    foreach ($taskData['entries'] as $entry) {
                        $billable = $entry['type'] === 'Учитываемые' ? $entry['hours'] : '';
                        $nonBillable = $entry['type'] === 'Неучитываемые' ? $entry['hours'] : '';
                        
                        $sheet->fromArray([[
                            "      ⏱ " . ($entry['entryTitle'] ?: 'Метка #' . $entry['id']),
                            $entry['taskId'],
                            $billable,
                            $nonBillable,
                            $entry['hours'],
                            date('d.m.Y', strtotime($entry['date']))
                        ]], null, "A{$row}");
                        $row++;
                    }
                    
                    // Task total
                    $sheet->fromArray([[
                        "    Итого по задаче:",
                        '',
                        $taskData['billableHours'],
                        $taskData['nonBillableHours'],
                        $taskData['totalHours'],
                        ''
                    ]], null, "A{$row}");
                    $this->styleTotalRow($sheet, "A{$row}:F{$row}");
                    $row++;
                }
                
                // Employee total
                $sheet->fromArray([[
                    "  Итого по сотруднику:",
                    '',
                    $empData['billableHours'],
                    $empData['nonBillableHours'],
                    $empData['totalHours'],
                    ''
                ]], null, "A{$row}");
                $this->styleTotalRow($sheet, "A{$row}:F{$row}");
                $row++;
            }
            
            // Project total
            $sheet->fromArray([[
                "Итого по проекту:",
                '',
                $projData['billableHours'],
                $projData['nonBillableHours'],
                $projData['totalHours'],
                ''
            ]], null, "A{$row}");
            $this->styleTotalRow($sheet, "A{$row}:F{$row}", true);
            $row += 2; // spacing
        }
        
        $this->autoSizeColumns($sheet, ['A', 'B', 'C', 'D', 'E', 'F']);
        
        return $this->createResponse($spreadsheet, 'Отчет_по_проектам.xlsx');
    }
    
    private function groupEmployeesReport(array $data): array
    {
        $empMap = [];
        
        foreach ($data as $item) {
            $empId = $item['employeeId'] ?: 'unknown';
            $empName = $item['employeeName'] ?: "User {$empId}";
            $projKey = $item['projectId'] ?: $item['projectName'] ?: 'Не определён';
            $projName = $item['projectName'] ?: 'Не определён';
            $taskId = $item['taskId'];
            
            if (!isset($empMap[$empId])) {
                $empMap[$empId] = [
                    'id' => $empId,
                    'name' => $empName,
                    'projects' => [],
                    'billableHours' => 0,
                    'nonBillableHours' => 0,
                    'totalHours' => 0
                ];
            }
            
            if (!isset($empMap[$empId]['projects'][$projKey])) {
                $empMap[$empId]['projects'][$projKey] = [
                    'name' => $projName,
                    'tasks' => [],
                    'billableHours' => 0,
                    'nonBillableHours' => 0,
                    'totalHours' => 0
                ];
            }
            
            if (!isset($empMap[$empId]['projects'][$projKey]['tasks'][$taskId])) {
                $empMap[$empId]['projects'][$projKey]['tasks'][$taskId] = [
                    'name' => $item['taskTitle'] ?: $item['taskName'],
                    'entries' => [],
                    'billableHours' => 0,
                    'nonBillableHours' => 0,
                    'totalHours' => 0
                ];
            }
            
            $empMap[$empId]['projects'][$projKey]['tasks'][$taskId]['entries'][] = $item;
            
            $hours = (float)$item['hours'];
            $isBillable = $item['type'] === 'Учитываемые';
            
            $empMap[$empId]['projects'][$projKey]['tasks'][$taskId]['totalHours'] += $hours;
            $empMap[$empId]['projects'][$projKey]['totalHours'] += $hours;
            $empMap[$empId]['totalHours'] += $hours;
            
            if ($isBillable) {
                $empMap[$empId]['projects'][$projKey]['tasks'][$taskId]['billableHours'] += $hours;
                $empMap[$empId]['projects'][$projKey]['billableHours'] += $hours;
                $empMap[$empId]['billableHours'] += $hours;
            } else {
                $empMap[$empId]['projects'][$projKey]['tasks'][$taskId]['nonBillableHours'] += $hours;
                $empMap[$empId]['projects'][$projKey]['nonBillableHours'] += $hours;
                $empMap[$empId]['nonBillableHours'] += $hours;
            }
        }
        
        return array_values($empMap);
    }
    
    private function groupProjectsReport(array $data): array
    {
        $projMap = [];
        
        foreach ($data as $item) {
            $projKey = $item['projectId'] ?: $item['projectName'] ?: 'Не определён';
            $projName = $item['projectName'] ?: 'Не определён';
            $empId = $item['employeeId'] ?: 'unknown';
            $empName = $item['employeeName'] ?: "User {$empId}";
            $taskId = $item['taskId'];
            
            if (!isset($projMap[$projKey])) {
                $projMap[$projKey] = [
                    'name' => $projName,
                    'employees' => [],
                    'billableHours' => 0,
                    'nonBillableHours' => 0,
                    'totalHours' => 0
                ];
            }
            
            if (!isset($projMap[$projKey]['employees'][$empId])) {
                $projMap[$projKey]['employees'][$empId] = [
                    'id' => $empId,
                    'name' => $empName,
                    'tasks' => [],
                    'billableHours' => 0,
                    'nonBillableHours' => 0,
                    'totalHours' => 0
                ];
            }
            
            if (!isset($projMap[$projKey]['employees'][$empId]['tasks'][$taskId])) {
                $projMap[$projKey]['employees'][$empId]['tasks'][$taskId] = [
                    'name' => $item['taskTitle'] ?: $item['taskName'],
                    'entries' => [],
                    'billableHours' => 0,
                    'nonBillableHours' => 0,
                    'totalHours' => 0
                ];
            }
            
            $projMap[$projKey]['employees'][$empId]['tasks'][$taskId]['entries'][] = $item;
            
            $hours = (float)$item['hours'];
            $isBillable = $item['type'] === 'Учитываемые';
            
            $projMap[$projKey]['employees'][$empId]['tasks'][$taskId]['totalHours'] += $hours;
            $projMap[$projKey]['employees'][$empId]['totalHours'] += $hours;
            $projMap[$projKey]['totalHours'] += $hours;
            
            if ($isBillable) {
                $projMap[$projKey]['employees'][$empId]['tasks'][$taskId]['billableHours'] += $hours;
                $projMap[$projKey]['employees'][$empId]['billableHours'] += $hours;
                $projMap[$projKey]['billableHours'] += $hours;
            } else {
                $projMap[$projKey]['employees'][$empId]['tasks'][$taskId]['nonBillableHours'] += $hours;
                $projMap[$projKey]['employees'][$empId]['nonBillableHours'] += $hours;
                $projMap[$projKey]['nonBillableHours'] += $hours;
            }
        }
        
        return array_values($projMap);
    }
    
    private function styleHeader($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
    }
    
    private function styleGroupHeader($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E7E6E6']]
        ]);
    }
    
    private function styleTotalRow($sheet, string $range, bool $bold = false): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'italic' => !$bold],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bold ? 'FFF2CC' : 'F4F4F4']]
        ]);
    }
    
    private function autoSizeColumns($sheet, array $columns): void
    {
        foreach ($columns as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    private function createResponse(Spreadsheet $spreadsheet, string $filename): Response
    {
        $response = new StreamedResponse(function() use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });
        
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');
        
        return $response;
    }
}

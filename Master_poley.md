---
title: "Мастер полей: Определение и сопоставление полей Битрикс24 и Приложения"
---

Этот файл описывает поля, используемые в смарт-процессе "Метки времени" в Битрикс24, а также соответствующие поля внутри приложения и их сопоставление.

## 1\. Поля Битрикс24 (`bitrix_fields`)

Список полей, доступных в смарт-процессе "Метки времени" в Битрикс24:

| ID                             | Название                    | Тип          |
|--------------------------------|-----------------------------|--------------|
| `id`                           | ID                          | integer      |
| `title`                        | Название                    | string       |
| `xmlId`                        | Внешний код                 | string       |
| `createdTime`                  | Когда создан                | datetime     |
| `updatedTime`                  | Когда обновлён              | datetime     |
| `createdBy`                    | Кем создан                  | user         |
| `updatedBy`                    | Кем обновлён                | user         |
| `assignedById`                 | Ответственный               | user         |
| `opened`                       | Доступно для всех           | boolean      |
| `webformId`                    | Создано CRM-формой          | integer      |
| `lastCommunicationTime`        | Дата последней коммуникации | string       |
| `categoryId`                   | Воронка                     | crm_category |
| `movedTime`                    | Дата изменения стадии       | datetime     |
| `movedBy`                      | Кто изменил стадию          | user         |
| `stageId`                      | Стадия                      | crm_status   |
| `previousStageId`              | Предыдущая стадия           | crm_status   |
| `lastActivityBy`               | Автор последней активности  | user         |
| `lastActivityTime`             | Последняя активность        | datetime     |
| `parentId1160`                 | Справочник задач            | crm_entity   |
| `lastCommunicationCallTime`    | Дата последнего звонка      | datetime     |
| `lastCommunicationEmailTime`   | Дата последнего e-mail      | datetime     |
| `lastCommunicationImolTime`    | Дата последнего диалога     | datetime     |
| `lastCommunicationWebformTime` | Дата последнего заполнения  | datetime     |
| `ufCrm87_1761919581`           | ID задачи                   | string       |
| `ufCrm87_1761919601`           | Сотрудник                   | employee     |
| `ufCrm87_1761919617`           | Количество часов            | double       |
| `ufCrm87_1761919633`           | Учитываем?                  | enumeration  |
| `ufCrm87_1762023633`           | Не учитываемые часы         | double       |
| `ufCrm87_1762026149771`        | Описание                    | string       |
| `ufCrm87_1763717129`           | Учитываем?                  | boolean      |
| `ufCrm87_1764191110`           | ID задач(иерархия)          | string       |
| `ufCrm87_1764191133`           | title задач(иерархия)       | string       |
| `UF_CRM_87_1764265626`         | ID проекта                  | string       |
| `UF_CRM_87_1764265641`         | Название проекта            | string       |
| `ufCrm87_1764446274`           | Дата отражения              | datetime     |

## 2\. Поля Приложения (`application_fields`)

Список полей, используемых внутри приложения:

| ID                       | Название              | Тип      |
|--------------------------|-----------------------|----------|
| `id_elem`                | ID элемента           | string   |
| `id_zadachi`             | ID задачи             | string   |
| `sotrudnik`              | Сотрудник             | string   |
| `kolichestvo_chasov`     | Количество часов      | double   |
| `uchitivaem`             | Учитываем?            | boolean  |
| `ne_uchitivaemie_chasi`  | Не учитываемые часы   | double   |
| `opisanie`               | Описание              | string   |
| `id_zadach_ierarhiya`    | ID задач(иерархия)    | string   |
| `title_zadach_ierarhiya` | title задач(иерархия) | string   |
| `data_nachala_rabot`     | Дата начала работ     | datetime |
| `data_okonchaniya_rabot` | Дата окончания работ  | datetime |
| `nazvanie_zadachi`       | Название задачи       | string   |

## 3\. Сопоставление полей (`field_mapping`)

Сопоставление полей Битрикс24 с полями приложения:

| ID поля Битрикс24       | ID поля Приложения       |
|-------------------------|--------------------------|
| `id`                    | `id_elem`                |
| `ufCrm87_1761919581`    | `id_zadachi`             |
| `ufCrm87_1761919601`    | `sotrudnik`              |
| `ufCrm87_1761919617`    | `kolichestvo_chasov`     |
| `ufCrm87_1763717129`    | `uchitivaem`             |
| `ufCrm87_1762023633`    | `ne_uchitivaemie_chasi`  |
| `ufCrm87_1762026149771` | `opisanie`               |
| `ufCrm87_1764191110`    | `id_zadach_ierarhiya`    |
| `ufCrm87_1764191133`    | `title_zadach_ierarhiya` |
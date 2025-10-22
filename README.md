# Profitbase API Client for PHP
[![CI](https://github.com/Kenny-MGN/profitbase-php-client/actions/workflows/ci.yml/badge.svg)](https://github.com/Kenny-MGN/profitbase-php-client/actions)
[![Latest Stable Version](https://img.shields.io/packagist/v/Kenny-MGN/profitbase-php-client.svg)](https://packagist.org/packages/Kenny-MGN/profitbase-php-client)
[![License](https://img.shields.io/github/license/Kenny-MGN/profitbase-php-client.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/static--analysis-PHPStan-blue.svg)](https://phpstan.org/)
[![Code Style](https://img.shields.io/badge/code%20style-PSR--12-green.svg)](https://www.php-fig.org/psr/psr-12/)

## Содержание
- [Описание](#описание)
- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Query-параметры](#query-параметры)
  - [Передача множественных значений](#передача-множественных-значений-в-query-параметрах)
- [Конфигурация HTTP-клиента (Guzzle)](#конфигурация-http-клиента-guzzle)
- [Потоковый режим](#потоковый-режим)
- [Ограничение частоты запросов (Rate Limit)](#ограничение-частоты-запросов-rate-limit)
  - [Асинхронность и многопоточность](#асинхронность-и-многопоточность)
- [Методы доступа к Profitbase API](#методы-доступа-к-profitbase-api)
- [Обратная связь](#обратная-связь)
- [Лицензия](#лицензия)

## Описание

**Profitbase API Client for PHP** – это обёртка над HTTP-клиентом [Guzzle](https://github.com/guzzle/guzzle), предназначенная для взаимодействия с [Profitbase API](https://developer.profitbase.ru/). Данный пакет реализует автоматическое управление токеном доступа (access token): его первоначальное получение, обновление по истечении срока действия и троттлинг запросов для соблюдения ограничений частоты обращений (rate limit) к **Profitbase API**.
## Требования
- PHP >= 8.1
- PHP Extensions: `curl`, `json`

## Установка
```bash
composer require kenny-mgn/profitbase-php-client
```

## Быстрый старт

```php
use KennyMgn\ProfitbaseClient\ProfitbaseClient;

try {  
    $client = ProfitbaseClient::create('your-api-key', 'https://pbXXXXX.profitbase.ru/api/v4/json');  
    $response = $client->houses();  
  
    $jsonString = $response->getBody()->getContents();  
    $arrayResult = json_decode($jsonString, associative: true);  
} catch (Throwable $throwable) {  
    echo $throwable->getMessage();  
}
```

Методы запросов к Profitbase API возвращают ответ в виде [ResponseInterface](https://docs.guzzlephp.org/en/stable/psr7.html#responses). Основные методы:

```php
# HTTP статус ответа
$response->getStatusCode();

# Тело ответа
$body = $response->getBody();

# Читаем всё содержимое в память
$jsonString = $body->getContents();
```
## Query-параметры

Во всех методах обращения к Profitbase API можно передавать дополнительные query-параметры через массив `$queryParams`.

Если эндпоинт имеет стандартные параметры, они обычно уже вынесены в сигнатуру метода, например:

```php
public function specialOffers(
    ?bool $isArchived = null,
    ?bool $isDiscounted = null,
    array $queryParams = []
)
```

Параметры `$isArchived` и `$isDiscounted` — основные, определённые в методе.

Массив `$queryParams` позволяет добавлять любые другие query-параметры для гибкости, даже если эндпоинт  сейчас их не принимает.

### Передача множественных значений в query-параметрах

В **Profitbase API** для передачи нескольких значений одного параметра query string должен иметь вид:

```text
?ids[]=1&ids[]=2&ids[]=3
```

В данном пакете это достигается созданием массива вида:

```php
$queryParams = ['ids[]' => [1, 2, 3]];
```

Библиотека автоматически преобразует такой массив в правильный формат query string для запроса к API.

Пример использования:

```php
$client = ProfitbaseClient::create($apiKey, $baseEndpoint);  
$response = $client->properties(['status[]' => ['AVAILABLE', 'BOOKED']]);
```
## Конфигурация HTTP-клиента (Guzzle)
При создании экземпляра клиента `ProfitbaseClient` можно передавать массив `$httpClientConfig` с дополнительными параметрами конфигурации Guzzle.  Любые переданные параметры **переопределяют стандартные настройки HTTP-клиента**,  
что позволяет гибко управлять поведением соединений, таймаутами, заголовками и другими опциями.

Пример создания клиента с кастомной конфигурацией:

```php
$client = ProfitbaseClient::create(
    $apiKey,
    $baseEndpoint,
    [
        'timeout' => 20,
        'headers' => ['X-Custom-Header' => 'value'],
    ]
);
```

Все доступные параметры **Guzzle** можно найти в официальной документации: [Guzzle Request Options](https://docs.guzzlephp.org/en/stable/request-options.html).

## Потоковый режим

По умолчанию клиент работает в потоковом режиме, позволяющем обрабатывать данные по мере их поступления и использовать библиотеки вроде [cerbero/json-parser](https://github.com/cerbero90/json-parser)  для порционной обработки больших JSON-ответов.

```php
use Cerbero\JsonParser\JsonParser;  
use KennyMgn\ProfitbaseClient\ProfitbaseClient;

try {  
    $client = ProfitbaseClient::create($apiKey, $baseEndpoint);  
    $response = $client->houses();  
  
    $stream = $response->getBody();  
    JsonParser::parse($stream)->traverse(function (mixed $value, string|int $key, JsonParser $parser) {  
        // Действия
    });  
} catch (Throwable $throwable) {  
    echo $throwable->getMessage();  
}
```

Чтобы создать экземпляр клиента не в потоковом режиме, передайте параметр конфигурации `stream => false`:

```php
$client = ProfitbaseClient::create($apiKey, $baseEndpoint, ['stream' => false]);
```

## Ограничение частоты запросов (Rate Limit)

**Profitbase API** ограничивает частоту обращений: **не более одного запроса в секунду** от одного клиента. Чтобы соблюдать это требование, класс `ProfitbaseClient` включает встроенный механизм ограничения количества запросов. По умолчанию между последовательными запросами выдерживается пауза в **1 секунду**.

Интервал можно изменить методом:
```php
$client->limitRequestRateTo(2);
```

### Асинхронность и многопоточность

Класс `ProfitbaseClient` реализован синхронно — все запросы выполняются последовательно в одном потоке. Он **не поддерживает асинхронные вызовы или параллельное выполнение** запросов из коробки.

Если вы попытаетесь выполнять несколько запросов одновременно (например, из разных потоков или процессов),  ограничение на уровне PHP-кода не сможет их синхронизировать — в этом случае ограничение частоты запросов  нужно обеспечивать самостоятельно.

## Методы доступа к Profitbase API

Раздел описывает публичные методы класса `ProfitbaseClient`, соответствующие эндпоинтам **Profitbase API**, с указанием HTTP-метода, пути и поддерживаемых параметров.

Структура данного раздела (группировка и наименования) повторяет структуру официальной документации **Profitbase API**. Это сделано для удобства навигации и быстрой сопоставимости методов клиента с эндпоинтами API.

Подробное описание параметров запросов (включая `query string` и `body`) и форматов ответов см. в официальной документации Profitbase: [https://developer.profitbase.ru/](https://developer.profitbase.ru/).

---
### Навигация по методам доступа к Profitbase API
[auth](#auth)
- [Авторизация](#Авторизация)

[houses](#houses)
- [Метод получения списка домов с возможностью фильтрации](#метод-получения-списка-домов-с-возможностью-фильтрации)
- [Метод получения количества этажей в конкретном доме](#Метод-получения-количества-этажей-в-конкретном-доме)
- [Метод получения количества помещений в конкретном доме на конкретном этаже](#Метод-получения-количества-помещений-в-конкретном-доме-на-конкретном-этаже)
- [Устаревший метод v3 получения списка домов](#Устаревший-метод-v3-получения-списка-домов)
- [Метод создания дома в существующий ЖК](#Метод-создания-дома-в-существующий-ЖК)
- [Метод обновления данных в конкретном доме](#Метод-обновления-данных-в-конкретном-доме)
- [Метод для поиска домов по названию, адресу и названию ЖК](#Метод-для-поиска-домов-по-названию-адресу-и-названию-ЖК)

[projects](#projects)
- [Метод получения списка ЖК](#Метод-получения-списка-ЖК)
- [Метод создания ЖК](#Метод-создания-ЖК)
- [Метод обновления ЖК](#Метод-обновления-ЖК)
- [Метод для поиска ЖК по названию, адресу и названию дома, названию застройщика](#Метод-для-поиска-ЖК-по-названию-адресу-и-названию-дома-названию-застройщика)

[properties](#properties)
- [Метод получения списка помещений с возможностью фильтрации](#Метод-получения-списка-помещений-с-возможностью-фильтрации)
- [Метод создания помещения в конкретный дом](#Метод-создания-помещения-в-конкретный-дом)
- [Метод обновления данных в конкретном помещении](#Метод-обновления-данных-в-конкретном-помещении)
- [Метод получения списка типов помещений и их дополнительных полей](#Метод-получения-списка-типов-помещений-и-их-дополнительных-полей)
- [Метод получения списка помещений привязанных к конкретной сделке](#Метод-получения-списка-помещений-привязанных-к-конкретной-сделке)
- [Метод получения истории изменения статусов по конкретному помещению](#Метод-получения-истории-изменения-статусов-по-конкретному-помещению)
- [Устаревший метод v3 получения списка помещений в конкретном доме](#Устаревший-метод-v3-получения-списка-помещений-в-конкретном-доме)
- [Метод получения списка сделок привязанных к конкретным помещениям](#Метод-получения-списка-сделок-привязанных-к-конкретным-помещениям)
- [Метод изменения статуса помещения](#Метод-изменения-статуса-помещения)
- [Метод продления брони в конкретном помещении](#Метод-продления-брони-в-конкретном-помещении)

[board](#board)
- [Метод получения шахматки дома](#Метод-получения-шахматки-дома)

[presets](#presets)
- [Метод получения планировок помещений с возможностью фильтрации](#Метод-получения-планировок-помещений-с-возможностью-фильтрации)
- [Устаревший метод получения планировок помещений в доме](#Устаревший-метод-получения-планировок-помещений-в-доме)

[facade](#facade)
- [Метод получения списка фасадов дома](#Метод-получения-списка-фасадов-дома)

[floor](#floor)
- [Метод получения планировок этажей дома](#Метод-получения-планировок-этажей-дома)

[actions](#actions)
- [Метод получения списка активных акции со списком помещений по каждой акции](#Метод-получения-списка-активных-акции-со-списком-помещений-по-каждой-акции)

[crm](#crm)
- [Метод получения списка сделок или конкретной сделки](#Метод-получения-списка-сделок-или-конкретной-сделки)
- [Метод получения списка сделок в которые добавлено конкретное помещение](#Метод-получения-списка-сделок-в-которые-добавлено-конкретное-помещение)
- [Метод добавления помещения в сделку](#Метод-добавления-помещения-в-сделку)
- [Метод удаления помещений из сделки](#Метод-удаления-помещений-из-сделки)
- [Метод обновления полей Proftibase в сделке по помещению](#Метод-обновления-полей-Proftibase-в-сделке-по-помещению)
- [Метод синхронизации статуса помещения с этапом сделки CRM согласно разметке статусов приложения CRM для Profitbase](#Метод-синхронизации-статуса-помещения-с-этапом-сделки-CRM-согласно-разметке-статусов-приложения-CRM-для-Profitbase)

[order](#order)
- [Метод создания заявки на помещение](#Метод-создания-заявки-на-помещение)

[history](#history)
- [Метод получения истории изменения статусов помещений с возможностью фильтрации](#Метод-получения-истории-изменения-статусов-помещений-с-возможностью-фильтрации)

[statuses](#statuses)
- [Метод получения списка статусов для crm, или конкретного статуса по id](#Метод-получения-списка-статусов-для-crm-или-конкретного-статуса-по-id)

[filter](#filter)
- [Метод для получения списка отображаемых в виджете фильтров](#Метод-для-получения-списка-отображаемых-в-виджете-фильтров)
- [Метод для получения списка доступных отделок для отображения в фильтре по отделке](#Метод-для-получения-списка-доступных-отделок-для-отображения-в-фильтре-по-отделке)
- [Получить характеристики для фильтра](#Получить-характеристики-для-фильтра)

[property-specification](#property-specification)
- [Метод получения списка характеристик для помещений с количеством аналогичных помещений в доме](#Метод-получения-списка-характеристик-для-помещений-с-количеством-аналогичных-помещений-в-доме)
- [Получить список всех характеристик](#Получить-список-всех-характеристик)
- [Получить характеристики по дому](#Получить-характеристики-по-дому)

[queue-reserve](#queue-reserve)
- [Метод получения списка очереди бронирования по указанному помещению](#Метод-получения-списка-очереди-бронирования-по-указанному-помещению)
- [Метод для удаления сделки из очереди бронирования](#Метод-для-удаления-сделки-из-очереди-бронирования)
- [Метод для добавления сделки в очередь бронирования](#Метод-для-добавления-сделки-в-очередь-бронирования)
- [Метод для изменения порядка сделок в очереди бронирования](#Метод-для-изменения-порядка-сделок-в-очереди-бронирования)

[render](#render)
- [Метод получения списка генпланов](#Метод-получения-списка-генпланов)

[users](#users)
- [Метод для получения информации о пользователях](#Метод-для-получения-информации-о-пользователях)
- [Метод для изменения прав пользователей](#Метод-для-изменения-прав-пользователей)
- [Инициализация сброса пароля для пользователя](#Инициализация-сброса-пароля-для-пользователя)

[stock-version](#stock-version)
- [Получить изменения по версиям](#Получить-изменения-по-версиям)

---

###  ⚠️ Примечание о непроверенных методах 

На реальном API была проверена работоспособность **только идемпотентных методов** (выполняющих безопасные операции не изменяющие состояние системы).  Это связано с тем, что тестирование проводилось с **доступом только на чтение**.

Методы, выполнение которых изменяет состояние данных, **не были проверены на реальном API**.  Такие методы в документации помечены специальным образом: ⚠️ _Не проверено на реальном API_.

---
### auth

---
#### Авторизация

Метод для аутентификации. Возвращает access_token для доступа к API.

⚠️ **Примечание:**   вызов `auth()` **не обязателен** — клиент **автоматически** обрабатывает получение, обновление и передачу `access_token` при каждом запросе.

**Эндпоинт:** `POST /authentication`

**Сигнатура:**

```php
auth(string $apiKey): ResponseInterface
```
**Пример вызова:**

```php
$client->auth('app-some-key');
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### houses

---

#### Метод получения списка домов с возможностью фильтрации

**Эндпоинт:** `GET /house`

**Сигнатура:**

```php
houses(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->houses(['isArchive' => false]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод получения количества этажей в конкретном доме

**Эндпоинт:** `GET /house/get-count-floors`

**Сигнатура:**

```php
houseFloorCount(int $houseID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->houseFloorCount(houseID: 12345);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод получения количества помещений в конкретном доме на конкретном этаже

**Эндпоинт:** `/house/get-count-properties-on-floor`

**Сигнатура:**

```php
houseFloorPropertyCount(int $houseID, int $floor, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->houseFloorPropertyCount(houseID: 12345, floor: 5);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Устаревший метод v3 получения списка домов

**Эндпоинт:** ` /projects/{projectId}/houses`

**Сигнатура:**

```php
housesLegacyV3(int $projectID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->housesLegacyV3(projectID: 789);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод создания дома в существующий ЖК

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /house`

**Сигнатура:**

```php
houseCreate(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->houseCreate(['title' => 'Корпус 1', 'projectId' => 789]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод обновления данных в конкретном доме

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `PUT /houses/{id}`

**Сигнатура:**

```php
houseUpdate(int $houseID, array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->houseUpdate(12345, ['title' => 'Корпус 1А']);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод для поиска домов по названию, адресу и названию ЖК


**Эндпоинт:** `GET /houses/search`

**Сигнатура:**

```php
housesSearch(string $searchQuery, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->housesSearch('Корпус 1');
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### projects

---

####  Метод получения списка ЖК 

**Эндпоинт:** `GET /projects`

**Сигнатура:**

```php
projects(?bool $isArchive = null, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->projects(isArchive: false);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод создания ЖК

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /projects`

**Сигнатура:**

```php
projectCreate(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->projectCreate(['title' => 'Новомосковский', 'type' => 'complex']);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод обновления ЖК

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `PUT /projects/{id}`

**Сигнатура:**

```php
projectUpdate(int $projectID, array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->projectUpdate(789, ['title' => 'Квартальный', 'type' => 'quarter']);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод для поиска ЖК по названию, адресу и названию дома, названию застройщика

**Эндпоинт:** `GET /projects/search`

**Сигнатура:**

```php
projectsSearch(string $searchQuery, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->projectsSearch('Новомосковский');
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### properties

---

#### Метод получения списка помещений с возможностью фильтрации

**Эндпоинт:** `GET /property`

**Сигнатура:**

```php
properties(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->properties(['status[]' => ['AVAILABLE', 'BOOKED']]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод создания помещения в конкретный дом

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /properties`

**Сигнатура:**

```php
propertyCreate(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertyCreate([  
    'house_id' => 1,  
    'number' => 'A101',  
    'rooms_amount' => 3,  
    'section' => '1',  
    'floor' => 11,  
    'status' => 'AVAILABLE',  
    "area" => [  
        "area_total" => 60.2,  
        "area_estimated" => 60.2,  
        "area_living" => 60.2,  
        "area_kitchen" => 60.2,  
        "area_balcony" => 60.2,  
        "area_without_balcony" => 60.2  
    ],  
]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод обновления данных в конкретном помещении

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `PATCH /properties/{propertyId}`

**Сигнатура:**

```php
propertyUpdate(int $propertyID, array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertyUpdate(56789, ['status' => 'SOLD']);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод получения списка типов помещений и их дополнительных полей

**Эндпоинт:** `GET /property-types`

**Сигнатура:**

```php
propertyTypes(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertyTypes();
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод получения списка помещений привязанных к конкретной сделке

**Эндпоинт:** `GET /property/deal/{dealId}`

**Сигнатура:**

```php
propertyDealList(int $dealID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertyDealList(dealID: 112233);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод получения истории изменения статусов по конкретному помещению

**Эндпоинт:** `GET /property/history/{propertyId}`

**Сигнатура:**

```php
propertyHistory(  
    int $propertyID,  
    ?int $offset = null,  
    ?int $limit = null,  
    array $queryParams = []  
): ResponseInterface
```
**Пример вызова:**

```php
$client->propertyHistory(56789, offset: 0, limit: 10);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Устаревший метод v3 получения списка помещений в конкретном доме

**Эндпоинт:** `GET /projects/{projectId}/houses/{houseId}/properties/list`

**Сигнатура:**

```php
propertiesLegacyV3(int $projectID, int $houseID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertiesLegacyV3(projectID: 123, houseID: 456);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод получения списка сделок привязанных к конкретным помещениям

**Эндпоинт:** `GET /get-property-deals`

**Сигнатура:**

```php
propertyDeals(array $propertyIDs, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertyDeals([56789, 56790]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод изменения статуса помещения

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /properties/{id}/status-change`

**Сигнатура:**

```php
propertyStatusChange(int $propertyID, array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertyStatusChange(56789, ['status' => 'UNAVAILABLE']);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод продления брони в конкретном помещении

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `PATCH /reserve/prolong`

**Сигнатура:**

```php
reserveProlong(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->reserveProlong(['propertyId' => 56789, 'date' => '2025-11-16 12:00']);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### board

---

#### Метод получения шахматки дома

**Эндпоинт:** `GET /board`

**Сигнатура:**

```php
board(int $houseID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->board(houseID: 12345);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### presets

---

#### Метод получения планировок помещений с возможностью фильтрации

**Эндпоинт:** `GET /plan`

**Сигнатура:**

```php
plans(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->plans(['projectId' => 123]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Устаревший метод получения планировок помещений в доме

**Эндпоинт:** `GET /projects/{projectId}/houses/{houseId}/presets`

**Сигнатура:**

```php
presetsLegacy(int $projectID, int $houseID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->presetsLegacy(projectID: 789, houseID: 12345);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### facade

---

#### Метод получения списка фасадов дома

**Эндпоинт:** `GET /facade`

**Сигнатура:**

```php
facades(int $houseID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->facades(houseID: 12345);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### floor

---

#### Метод получения планировок этажей дома

**Эндпоинт:** `GET /floor`

**Сигнатура:**

```php
floors(int $houseID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->floors(houseID: 12345);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### actions

---

#### Метод получения списка активных акции со списком помещений по каждой акции

**Эндпоинт:** `GET /special-offer`

**Сигнатура:**

```php
specialOffers(  
    ?bool $isArchived = null,  
    ?bool $isDiscounted = null,  
    array $queryParams = []  
): ResponseInterface
```
**Пример вызова:**

```php
$client->specialOffers(isArchived: false, isDiscounted: true);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### crm

---

#### Метод получения списка сделок или конкретной сделки

**Эндпоинт:** `GET /crm/deals`

**Сигнатура:**

```php
crmDeals(?int $dealID = null, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->crmDeals(dealID: 112233);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод получения списка сделок в которые добавлено конкретное помещение

**Эндпоинт:** `GET /crm/deals/property/{propertyId}`

**Сигнатура:**

```php
crmPropertyDeals(int $propertyID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->crmPropertyDeals(56789);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод добавления помещения в сделку

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /crm/addPropertyDeal`

**Сигнатура:**

```php
crmPropertyDealAdd(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->crmPropertyDealAdd(['dealId' => 112233, 'propertyId' => 56789]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод удаления помещений из сделки

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /crm/removePropertyDeal`

**Сигнатура:**

```php
crmPropertyDealRemove(int $dealID, array $body = [], array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->crmPropertyDealRemove(dealID: 112233);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод обновления полей Proftibase в сделке по помещению

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `GET /crm/update/deal/{dealId}/property/{propertyId}
`
**Сигнатура:**

```php
crmDealPropertyUpdate(int $dealID, int $propertyID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->crmDealPropertyUpdate(dealID: 112233, propertyID: 56789);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод синхронизации статуса помещения с этапом сделки CRM согласно разметке статусов приложения CRM для Profitbase

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `GET /crm/syncPropertyStatus`

**Сигнатура:**

```php
crmPropertyStatusSync(int $dealID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->crmPropertyStatusSync(dealID: 112233);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### order

---

#### Метод создания заявки на помещение

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /orders`

**Сигнатура:**

```php
orderCreate(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->orderCreate([
	'order' => [
		'name' => 'TEST',
		'phone' => '+79111111111',
		'email' => 'test@profitbase.ru',
		'apartment_id' => 248408,
		'calc_credit' => 1,
		'comment' => 'тестовая заявка',
		'widget_id' => 1
	]
]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### history

---

#### Метод получения истории изменения статусов помещений с возможностью фильтрации

**Эндпоинт:** `POST /history`

**Сигнатура:**

```php
history(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->history([  
    'offset' => 0,  
    'limit' => 20,  
    'property_ids' => [  
        3225787,  
        3225788  
    ],  
    'from' => '2023-03-01 10:56:15',  
    'to' => '2023-03-02 07:20:50',  
]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### statuses

---

#### Метод получения списка статусов для crm, или конкретного статуса по id

**Эндпоинт:** `GET /custom-status/list`

**Сигнатура:**

```php
customStatuses(string $crmID, ?string $statusID = null, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->customStatuses('bitrix');
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### filter

---

#### Метод для получения списка отображаемых в виджете фильтров

**Эндпоинт:** `GET /filter`

**Сигнатура:**

```php
filters(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->filters();
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод для получения списка доступных отделок для отображения в фильтре по отделке

**Эндпоинт:** `GET /filter/facings`

**Сигнатура:**

```php
filterFacings(array $houseIDs = [], array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->filterFacings([12345, 12346]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Получить характеристики для фильтра

**Эндпоинт:** `GET /filter/property-specifications`

**Сигнатура:**

```php
filterPropertySpecifications(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->filterPropertySpecifications();
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### property-specification

---

#### Метод получения списка характеристик для помещений с количеством аналогичных помещений в доме

**Эндпоинт:** `/property-specification`

**Сигнатура:**

```php
propertySpecifications(array $propertyIDs = [], array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertySpecifications([56789, 56790]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Получить список всех характеристик

**Эндпоинт:** `GET /property-specification/list`

**Сигнатура:**

```php
propertySpecificationList(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertySpecificationList();
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Получить характеристики по дому

**Эндпоинт:** `GET /property-specification/house`

**Сигнатура:**

```php
propertySpecificationHouse(int $houseID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->propertySpecificationHouse(houseID: 12345);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### queue-reserve

---

#### Метод получения списка очереди бронирования по указанному помещению

**Эндпоинт:** `GET /queue-reserve/list`
**Сигнатура:**

```php
queueReserveList(int $propertyID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->queueReserveList(propertyID: 56789);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод для удаления сделки из очереди бронирования

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /queue-reserve/delete`

**Сигнатура:**

```php
queueReserveDelete(  
    int $dealQueueItemID,  
    array $body = [],  
    array $queryParams = []  
): ResponseInterface
```
**Пример вызова:**

```php
$client->queueReserveDelete(dealQueueItemID: 123456);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод для добавления сделки в очередь бронирования

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /queue-reserve`

**Сигнатура:**

```php
queueReserveCreate(  
    int $propertyID,  
    int|string $dealID,  
    array $body = [],  
    array $queryParams = []  
): ResponseInterface
```
**Пример вызова:**

```php
$client->queueReserveCreate(propertyID: 56789, dealID: 112233);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод для изменения порядка сделок в очереди бронирования

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `POST /queue-reserve/change-position`

**Сигнатура:**

```php
queueReserveChangePosition(  
    int $sourceDealQueueItemID,  
    int $targetDealQueueItemID,  
    array $body = [],  
    array $queryParams = []  
): ResponseInterface
```
**Пример вызова:**

```php
$client->queueReserveChangePosition(12456, 124);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### render

---

#### Метод получения списка генпланов

**Эндпоинт:** `GET /render`

**Сигнатура:**

```php
renders(?int $projectID = null, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->renders(projectID: 789);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### users

---

#### Метод для получения информации о пользователях

**Эндпоинт:** `GET /user/info`

**Сигнатура:**

```php
userInfo(array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->userInfo(['email' => 'some_email@test.com']);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Метод для изменения прав пользователей

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `PATCH /user/{userId}/access`

**Сигнатура:**

```php
userAccessUpdate(int $userID, array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->userAccessUpdate(  
    334455,  
    [  
        'isAccountAccessEnabled' => 'true',  
        'isUsersSectionAccessEnabled' => 'true',  
        'isObjectsSectionAccessEnabled' => 'true'  
    ]  
);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
#### Инициализация сброса пароля для пользователя

⚠️ _Не проверено на реальном API_

**Эндпоинт:** `GET /user/{userId}/password/forgot`

**Сигнатура:**

```php
userPasswordForgot(int $userID, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->userPasswordForgot(userID: 334455);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

---
### stock-version

---

#### Получить изменения по версиям

**Эндпоинт:** `POST /versions/find`

**Сигнатура:**

```php
stockVersionsFind(array $body, array $queryParams = []): ResponseInterface
```
**Пример вызова:**

```php
$client->stockVersionsFind([  
    'ids' => [  
        12345,  
        12346  
    ],  
    'fields' => [  
        'price',  
        'status'  
    ]  
]);
```

[⤴ Вернуться к навигации по методам](#Навигация-по-методам-доступа-к-Profitbase-API)

## Обратная связь
Если вы нашли ошибку или хотите предложить улучшение — создайте [issue](https://github.com/Kenny-MGN/profitbase-php-client/issues/new).

## Лицензия
Проект распространяется под лицензией [MIT](LICENSE).
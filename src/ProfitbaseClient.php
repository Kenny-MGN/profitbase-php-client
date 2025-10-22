<?php

namespace KennyMgn\ProfitbaseClient;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use JsonException;
use KennyMgn\ProfitbaseClient\Exceptions\AccessTokenExpiredException;
use KennyMgn\ProfitbaseClient\Exceptions\AccessTokenRequestException;
use KennyMgn\ProfitbaseClient\Exceptions\HttpClientInitializationException;
use KennyMgn\ProfitbaseClient\Exceptions\HttpClientRuntimeException;
use KennyMgn\ProfitbaseClient\Support\QueryStringBuilder;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** @phpstan-consistent-constructor */
class ProfitbaseClient
{
    protected const GET = 'GET';
    protected const PATCH = 'PATCH';
    protected const POST = 'POST';
    protected const PUT = 'PUT';
    protected int $minSecondsBetweenRequests = 1;
    protected ?string $accessToken = null;
    protected int $lastRequestTimestamp;

    /**
     * @param array<string, mixed> $httpClientConfig
     * @throws AccessTokenRequestException
     * @throws HttpClientInitializationException
     */
    public static function create(string $apiKey, string $baseEndpoint, array $httpClientConfig = []): static
    {
        try {
            $client = new Client(self::buildHttpClientConfig($baseEndpoint, $httpClientConfig));
            return new static($client, new QueryStringBuilder(), $apiKey);
        } catch (AccessTokenRequestException $accessTokenRequestException) {
            throw $accessTokenRequestException;
        } catch (Throwable $t) {
            throw new HttpClientInitializationException('Failed to initialize HTTP client', previous: $t);
        }
    }

    /**
     * @param array<string, mixed> $customConfig
     * @return array<string, mixed>
     */
    protected static function buildHttpClientConfig(string $baseEndpoint, array $customConfig): array
    {
        $defaultConfig = [
            'base_uri' => rtrim($baseEndpoint, '/') . '/',
            'connect_timeout' => 5,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'http_errors' => false,
            'stream' => true,
            'timeout' => 10,
        ];

        return array_merge($defaultConfig, $customConfig);
    }

    /**
     * @throws AccessTokenRequestException
     */
    protected function __construct(
        protected Client $httpClient,
        protected QueryStringBuilder $queryStringBuilder,
        protected string $apiKey
    ) {
        $this->refreshAccessToken();
    }

    /**
     * @throws AccessTokenRequestException
     */
    public function refreshAccessToken(): void
    {
        $this->accessToken = $this->fetchAccessToken($this->apiKey);
    }

    /**
     * @throws AccessTokenRequestException
     */
    protected function fetchAccessToken(string $apiKey): string
    {
        try {
            $response = $this->auth($apiKey);

            if ($response->getStatusCode() !== 200) {
                throw new AccessTokenRequestException('Access token request failed with non-200 status code');
            }

            return $this->extractAccessTokenFromResponse($response);
        } catch (Throwable $t) {
            throw new AccessTokenRequestException(
                'Failed to obtain access token from Profitbase API',
                previous: $t
            );
        }
    }

    /**
     * @throws HttpClientRuntimeException
     */
    public function auth(string $apiKey): ResponseInterface
    {
        $request = ['credentials' => ['pb_api_key' => $apiKey], 'type' => 'api-app'];
        return $this->request(self::POST, 'authentication', body: $request);
    }

    /**
     * @throws JsonException
     */
    protected function extractAccessTokenFromResponse(ResponseInterface $response): string
    {
        $bodyContent = $response->getBody()->getContents();
        $data = json_decode($bodyContent, associative: true, flags: JSON_THROW_ON_ERROR);
        return $data['access_token'];
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @param array<string, mixed> $body
     * @throws HttpClientRuntimeException
     */
    public function request(
        string $method,
        string $uri,
        array $queryParams = [],
        array $body = [],
        bool $retry = false
    ): ResponseInterface {
        try {
            $this->throttleIfNecessary();
            $response = $this->httpClient->request($method, $uri, $this->buildRequestOptions($queryParams, $body));
            $this->updateLastRequestTimestamp();
            $this->assertAccessTokenIsValid($response);
            return $response;
        } catch (AccessTokenExpiredException) {
            if ($retry) {
                throw new HttpClientRuntimeException('Access token expired and refresh failed');
            }

            return $this->retryRequestOnce($method, $uri, queryParams: $queryParams, body: $body);
        } catch (Throwable $t) {
            throw new HttpClientRuntimeException('Unexpected error occurred during HTTP request', previous: $t);
        }
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(array $queryParams, array $body): array
    {
        $queryParams = isset($this->accessToken)
            ? array_merge($queryParams, ['access_token' => $this->accessToken])
            : $queryParams;

        return [
            ...(!empty($body) ? [RequestOptions::JSON => $body] : []),
            ...(!empty($queryParams) ? [RequestOptions::QUERY => $this->queryStringBuilder->build($queryParams)] : []),
        ];
    }

    protected function throttleIfNecessary(): void
    {
        if ($this->isRequestAllowed()) {
            return;
        }

        sleep($this->minSecondsBetweenRequests);
    }

    protected function isRequestAllowed(): bool
    {
        if (!isset($this->lastRequestTimestamp)) {
            return true;
        }

        $elapsedSeconds = time() - $this->lastRequestTimestamp;

        return $elapsedSeconds >= $this->minSecondsBetweenRequests;
    }

    protected function updateLastRequestTimestamp(): void
    {
        $this->lastRequestTimestamp = time();
    }

    /**
     * @throws AccessTokenExpiredException
     */
    protected function assertAccessTokenIsValid(ResponseInterface $response): void
    {
        if ($response->getStatusCode() === 403) {
            throw new AccessTokenExpiredException();
        }
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @param array<string, mixed> $body
     * @throws HttpClientRuntimeException
     */
    protected function retryRequestOnce(string $method, string $uri, array $queryParams, array $body): ResponseInterface
    {
        try {
            $this->refreshAccessToken();
        } catch (AccessTokenRequestException) {
            throw new HttpClientRuntimeException('Failed to refresh access token');
        }

        return $this->request($method, $uri, queryParams: $queryParams, body: $body, retry: true);
    }

    public function limitRequestRateTo(int $seconds): void
    {
        $this->minSecondsBetweenRequests = $seconds;
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function houses(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'house', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function houseFloorCount(int $houseID, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['houseId' => $houseID]);
        return $this->request(self::GET, 'house/get-count-floors', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function houseFloorPropertyCount(int $houseID, int $floor, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['houseId' => $houseID, 'floor' => $floor]);
        return $this->request(self::GET, 'house/get-count-properties-on-floor', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function housesLegacyV3(int $projectID, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, "projects/$projectID/houses", queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function houseCreate(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::POST, 'house', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function houseUpdate(int $houseID, array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::PUT, "house/$houseID", queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function housesSearch(string $searchQuery, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['text' => $searchQuery]);
        return $this->request(self::GET, 'house/search', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function projects(?bool $isArchive = null, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, isset($isArchive) ? ['isArchive' => $isArchive] : []);
        return $this->request(self::GET, 'projects', queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function projectCreate(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::POST, 'projects', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function projectUpdate(int $projectID, array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::PUT, "projects/$projectID", queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function projectsSearch(string $searchQuery, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['text' => $searchQuery]);
        return $this->request(self::GET, 'projects/search', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function properties(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'property', queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertyCreate(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::POST, 'properties', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertyUpdate(int $propertyID, array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::PATCH, "properties/$propertyID", queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertyTypes(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'property-types', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertyDealList(int $dealID, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, "property/deal/$dealID", queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertyHistory(
        int $propertyID,
        ?int $offset = null,
        ?int $limit = null,
        array $queryParams = []
    ): ResponseInterface {
        $paginationParams = [
            ...(isset($offset) ? ['offset' => $offset] : []),
            ...(isset($limit) ? ['limit' => $limit] : [])
        ];

        $queryParams = array_merge($queryParams, $paginationParams);

        return $this->request(self::GET, "property/history/$propertyID", queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertiesLegacyV3(int $projectID, int $houseID, array $queryParams = []): ResponseInterface
    {
        $endpoint = "projects/$projectID/houses/$houseID/properties/list";
        return $this->request(self::GET, $endpoint, queryParams: $queryParams);
    }

    /**
     * @param array<scalar> $propertyIDs
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertyDeals(array $propertyIDs, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['ids[]' => $propertyIDs]);
        return $this->request(self::GET, 'get-property-deals', queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertyStatusChange(int $propertyID, array $body, array $queryParams = []): ResponseInterface
    {
        $endpoint = "properties/$propertyID/status-change";
        return $this->request(self::POST, $endpoint, queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function reserveProlong(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::PATCH, 'reserve/prolong', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function board(int $houseID, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['houseId' => $houseID]);
        return $this->request(self::GET, 'board', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function plans(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'plan', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function presetsLegacy(int $projectID, int $houseID, array $queryParams = []): ResponseInterface
    {
        $endpoint = "projects/$projectID/houses/$houseID/presets";
        return $this->request(self::GET, $endpoint, queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function facades(int $houseID, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['houseId' => $houseID]);
        return $this->request(self::GET, 'facade', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function floors(int $houseID, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['houseId' => $houseID]);
        return $this->request(self::GET, 'floor', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function specialOffers(
        ?bool $isArchived = null,
        ?bool $isDiscounted = null,
        array $queryParams = []
    ): ResponseInterface {
        $filterParams = [
            ...(isset($isArchived) ? ['isArchived' => $isArchived] : []),
            ...(isset($isDiscounted) ? ['isDiscounted' => $isDiscounted] : [])
        ];

        $queryParams = array_merge($queryParams, $filterParams);

        return $this->request(self::GET, 'special-offer', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function crmDeals(?int $dealID = null, array $queryParams = []): ResponseInterface
    {
        $filterParams = isset($dealID) ? ['dealId' => $dealID] : [];
        $queryParams = array_merge($queryParams, $filterParams);

        return $this->request(self::GET, 'crm/deals', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function crmPropertyDeals(int $propertyID, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, "crm/deals/property/$propertyID", queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function crmPropertyDealAdd(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::POST, 'crm/addPropertyDeal', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function crmPropertyDealRemove(int $dealID, array $body = [], array $queryParams = []): ResponseInterface
    {
        $body = array_merge($body, ['dealId' => $dealID]);
        return $this->request(self::POST, 'crm/removePropertyDeal', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function crmDealPropertyUpdate(int $dealID, int $propertyID, array $queryParams = []): ResponseInterface
    {
        $endpoint = "crm/update/deal/$dealID/property/$propertyID";
        return $this->request(self::GET, $endpoint, queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function crmPropertyStatusSync(int $dealID, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['dealId' => $dealID]);
        return $this->request(self::GET, 'crm/syncPropertyStatus', queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function orderCreate(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::POST, 'orders', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function history(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::POST, 'history', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function customStatuses(string $crmID, ?string $statusID = null, array $queryParams = []): ResponseInterface
    {
        $baseParams = ['crm' => $crmID, ...(isset($statusID) ? ['id' => $statusID] : [])];
        $queryParams = array_merge($queryParams, $baseParams);
        return $this->request(self::GET, 'custom-status/list', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function filters(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'filter', queryParams: $queryParams);
    }

    /**
     * @param array<scalar> $houseIDs
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function filterFacings(array $houseIDs = [], array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, empty($houseIDs) ? [] : ['houseId[]' => $houseIDs]);
        return $this->request(self::GET, 'filter/facings', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function filterPropertySpecifications(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'filter/property-specifications', queryParams: $queryParams);
    }

    /**
     * @param array<scalar> $propertyIDs
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertySpecifications(array $propertyIDs = [], array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, empty($propertyIDs) ? [] : ['propertyIds[]' => $propertyIDs]);
        return $this->request(self::GET, 'property-specification', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertySpecificationList(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'property-specification/list', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function propertySpecificationHouse(int $houseID, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['houseId' => $houseID]);
        return $this->request(self::GET, 'property-specification/house', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function queueReserveList(int $propertyID, array $queryParams = []): ResponseInterface
    {
        $queryParams = array_merge($queryParams, ['propertyId' => $propertyID]);
        return $this->request(self::GET, 'queue-reserve/list', queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function queueReserveDelete(
        int $dealQueueItemID,
        array $body = [],
        array $queryParams = []
    ): ResponseInterface {
        $queryParams = array_merge($queryParams, ['id' => $dealQueueItemID]);
        return $this->request(self::POST, 'queue-reserve/delete', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function queueReserveCreate(
        int $propertyID,
        int|string $dealID,
        array $body = [],
        array $queryParams = []
    ): ResponseInterface {
        $body = array_merge($body, ['propertyId' => $propertyID, 'dealId' => $dealID]);
        return $this->request(self::POST, 'queue-reserve', queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function queueReserveChangePosition(
        int $sourceDealQueueItemID,
        int $targetDealQueueItemID,
        array $body = [],
        array $queryParams = []
    ): ResponseInterface {
        $endpoint = 'queue-reserve/change-position';
        $body = array_merge($body, ['queueId' => $sourceDealQueueItemID, 'queueDropId' => $targetDealQueueItemID]);
        return $this->request(self::POST, $endpoint, queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function renders(?int $projectID = null, array $queryParams = []): ResponseInterface
    {
        $queryParams =  array_merge($queryParams, isset($projectID) ? ['projectId' => $projectID] : []);
        return $this->request(self::GET, 'render', queryParams: $queryParams);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function userInfo(array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, 'user/info', queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function userAccessUpdate(int $userID, array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::PATCH, "user/$userID/access", queryParams: $queryParams, body: $body);
    }

    /**
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function userPasswordForgot(int $userID, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::GET, "user/$userID/password/forgot", queryParams: $queryParams);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, scalar|null|array<scalar|null>> $queryParams
     * @throws HttpClientRuntimeException
     */
    public function stockVersionsFind(array $body, array $queryParams = []): ResponseInterface
    {
        return $this->request(self::POST, 'versions/find', queryParams: $queryParams, body: $body);
    }
}

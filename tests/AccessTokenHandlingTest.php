<?php

namespace KennyMgn\ProfitbaseClient\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use KennyMgn\ProfitbaseClient\Exceptions\AccessTokenRequestException;
use KennyMgn\ProfitbaseClient\Tests\Traits\CreatesMockClientTrait;
use PHPUnit\Framework\TestCase;

class AccessTokenHandlingTest extends TestCase
{
    use CreatesMockClientTrait;

    public function testExtractAccessTokenFromResponseReturnsToken(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['access_token' => 'valid-token'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $requestBody = ['credentials' => ['pb_api_key' => 'key']];
        $response = $client->request('POST', 'authentication', body: $requestBody);

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('access_token', $body);
        $this->assertSame('valid-token', $body['access_token']);
    }

    public function testAccessTokenRequestThrowsExceptionOnNon200(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $this->expectException(AccessTokenRequestException::class);
        $this->createClientWithMockHandler($mock);
    }

    public function testAccessTokenRequestThrowsExceptionOnInvalidJson(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{invalid json}'),
        ]);

        $this->expectException(AccessTokenRequestException::class);
        $this->createClientWithMockHandler($mock);
    }

    public function testSuccessfulTokenDoesNotThrow(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'initial-token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'ok'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $response = $client->request('GET', 'some-endpoint');
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertSame('ok', $body['data']);
    }
}

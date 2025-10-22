<?php

namespace KennyMgn\ProfitbaseClient\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use KennyMgn\ProfitbaseClient\Tests\Traits\CreatesMockClientTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ThrottleTest extends TestCase
{
    use CreatesMockClientTrait;

    public function testThrottleAppliesBetweenTokenAndFirstRequest(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'first'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $startTime = microtime(true);
        $client->request('GET', 'test-endpoint');
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $errorMsg = 'Throttling should apply between token and first request';
        $this->assertGreaterThanOrEqual(1.0, $executionTime, $errorMsg);
    }

    public function testConsecutiveRequestsRespectThrottleDelay(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'first'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'second'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $client->request('GET', 'test-endpoint');

        $startTime = microtime(true);
        $client->request('GET', 'test-endpoint');
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $errorMsg = 'Second request should be delayed by at least 1 second due to throttling';
        $this->assertGreaterThanOrEqual(1.0, $executionTime, $errorMsg);
    }

    public function testCustomRateLimitIsRespected(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'first'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'second'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);
        $client->limitRequestRateTo(2);

        $client->request('GET', 'test-endpoint');

        $startTime = microtime(true);
        $client->request('GET', 'test-endpoint');
        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $errorMsg = 'Throttling should respect custom rate limit of 2 seconds';
        $this->assertGreaterThanOrEqual(2.0, $executionTime, $errorMsg);
    }

    public function testThrottleCanBeDisabled(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'first'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'second'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);
        $client->limitRequestRateTo(0);

        $startTime = microtime(true);
        $client->request('GET', 'test-endpoint');
        $firstRequestTime = microtime(true) - $startTime;

        $startTime = microtime(true);
        $client->request('GET', 'test-endpoint');
        $secondRequestTime = microtime(true) - $startTime;

        $errorMsg = 'Request should be fast when throttling disabled';
        $this->assertLessThan(0.5, $firstRequestTime, $errorMsg);
        $this->assertLessThan(0.5, $secondRequestTime, $errorMsg);
    }

    public function testLastRequestTimestampIsUpdated(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'token'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['data' => 'first'], JSON_THROW_ON_ERROR)),
        ]);

        $client = $this->createClientWithMockHandler($mock);

        $reflection = new ReflectionClass($client);
        $timestampProperty = $reflection->getProperty('lastRequestTimestamp');
        $timestampProperty->setAccessible(true);

        $initialTimestamp = $timestampProperty->getValue($client);

        $client->request('GET', 'test-endpoint');

        $updatedTimestamp = $timestampProperty->getValue($client);

        $errorMsg = 'lastRequestTimestamp should be updated after request';
        $this->assertGreaterThan($initialTimestamp, $updatedTimestamp, $errorMsg);
    }
}

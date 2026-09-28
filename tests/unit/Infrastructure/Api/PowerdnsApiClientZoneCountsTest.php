<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

#[CoversClass(PowerdnsApiClient::class)]
class PowerdnsApiClientZoneCountsTest extends TestCase
{
    public function testCountsKindsAndSignedZonesFromOneZoneList(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->expects($this->once())
            ->method('makeRequest')
            ->willReturn([
                'responseCode' => 200,
                'data' => [
                    ['name' => 'a.example.', 'kind' => 'Native', 'dnssec' => true],
                    ['name' => 'b.example.', 'kind' => 'Master', 'dnssec' => false],
                    ['name' => 'c.example.', 'kind' => 'native', 'dnssec' => true],
                    ['name' => 'd.example.'],
                ],
            ]);

        $counts = (new PowerdnsApiClient($http, 'localhost'))->getZoneCounts();

        $this->assertSame(['native' => 2, 'master' => 1, 'unknown' => 1], $counts['kinds']);
        $this->assertSame(2, $counts['dnssec_signed']);
    }

    public function testReturnsNullWhenPowerDnsDoesNotAnswer(): void
    {
        $http = $this->createMock(HttpClient::class);
        $http->method('makeRequest')->willReturn(['responseCode' => 502, 'data' => []]);

        $this->assertNull((new PowerdnsApiClient($http, 'localhost'))->getZoneCounts());
    }
}

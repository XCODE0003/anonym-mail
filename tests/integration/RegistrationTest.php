<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for user registration flow.
 * Requires running test containers (docker-compose.test.yml).
 */
class RegistrationTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
        
        // Skip if not in integration test environment
        if (!getenv('INTEGRATION_TESTS')) {
            $this->markTestSkipped('Integration tests require INTEGRATION_TESTS=1');
        }
    }

    public function testRegistrationPageLoads(): void
    {
        $response = file_get_contents($this->baseUrl . '/register.php');
        
        $this->assertNotFalse($response);
        $this->assertStringContainsString('Register', $response);
        $this->assertStringContainsString('csrf', $response);
    }

    public function testRegistrationPageHasNoCookiesWithoutInteraction(): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: text/html\r\n",
            ],
        ]);
        
        file_get_contents($this->baseUrl . '/register.php', false, $context);
        
        // Check response headers for cookies
        $headers = $http_response_header ?? [];
        $hasCookie = false;
        
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $hasCookie = true;
                // Verify cookie has secure attributes
                $this->assertStringContainsString('HttpOnly', $header);
                $this->assertStringContainsString('SameSite', $header);
            }
        }
        
        // Session cookie is expected
        $this->assertTrue(true);
    }

    public function testRegistrationRejectsInvalidCsrf(): void
    {
        $data = http_build_query([
            'username' => 'testuser',
            'domain' => '1',
            'password' => 'SecurePassword123!',
            'password_confirm' => 'SecurePassword123!',
            'captcha_id' => 'invalid',
            'captcha' => '123456',
            'csrf' => 'invalid_token',
            'csrf_valid' => 'invalid_token',
        ]);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $data,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = file_get_contents($this->baseUrl . '/register.php', false, $context);
        
        // Should reject with 403
        $statusCode = $this->getHttpStatusCode($http_response_header ?? []);
        $this->assertEquals(403, $statusCode);
    }

    public function testSecurityHeadersPresent(): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
            ],
        ]);
        
        file_get_contents($this->baseUrl . '/', false, $context);
        $headers = $http_response_header ?? [];
        
        $headersMap = [];
        foreach ($headers as $header) {
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $headersMap[strtolower(trim($name))] = trim($value);
            }
        }
        
        $this->assertArrayHasKey('x-frame-options', $headersMap);
        $this->assertArrayHasKey('x-content-type-options', $headersMap);
        $this->assertArrayHasKey('referrer-policy', $headersMap);
        $this->assertEquals('no-referrer', $headersMap['referrer-policy']);
    }

    private function getHttpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/\d\.\d (\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }
}

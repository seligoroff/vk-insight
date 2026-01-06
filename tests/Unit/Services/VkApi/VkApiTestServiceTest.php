<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkApiTestService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;

class VkApiTestServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        VkApiTestService::setAdapter(null);
        parent::tearDown();
        Mockery::close();
    }
    /**
     * Тест получения битовой маски прав без user_id
     */
    public function test_get_app_permissions_without_user_id()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAccount = Mockery::mock();
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('account')->andReturn($mockAccount);
        $mockAccount->shouldReceive('getAppPermissions')
            ->with('test_token', [])
            ->once()
            ->andReturn(9355263);
        
        VkApiTestService::setAdapter($mockAdapter);

        $result = VkApiTestService::getAppPermissions();

        $this->assertTrue($result['success']);
        $this->assertEquals(9355263, $result['bitmask']);
        $this->assertNull($result['error']);
        $this->assertNull($result['error_code']);
    }

    /**
     * Тест получения битовой маски прав с user_id
     */
    public function test_get_app_permissions_with_user_id()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAccount = Mockery::mock();
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('account')->andReturn($mockAccount);
        $mockAccount->shouldReceive('getAppPermissions')
            ->with('test_token', ['user_id' => 12345678])
            ->once()
            ->andReturn(9355263);
        
        VkApiTestService::setAdapter($mockAdapter);

        $result = VkApiTestService::getAppPermissions(12345678);

        $this->assertTrue($result['success']);
        $this->assertEquals(9355263, $result['bitmask']);
        $this->assertNull($result['error']);
    }

    /**
     * Тест обработки ошибки API при получении битовой маски
     */
    public function test_get_app_permissions_handles_api_error()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAccount = Mockery::mock();
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('account')->andReturn($mockAccount);
        
        // Создаем VKApiError для исключения
        $vkApiError = new \VK\Client\VKApiError([
            'error_code' => 15,
            'error_msg' => 'Access denied'
        ]);
        $exception = new \VK\Exceptions\VKApiException(
            15,
            'Access denied',
            $vkApiError
        );
        
        $mockAccount->shouldReceive('getAppPermissions')
            ->with('test_token', [])
            ->once()
            ->andThrow($exception);
        
        VkApiTestService::setAdapter($mockAdapter);

        $result = VkApiTestService::getAppPermissions();

        $this->assertFalse($result['success']);
        $this->assertNull($result['bitmask']);
        $this->assertEquals('Access denied', $result['error']);
        $this->assertEquals(15, $result['error_code']);
    }

    /**
     * Тест обработки некорректного ответа (не число)
     */
    public function test_get_app_permissions_handles_invalid_response()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAccount = Mockery::mock();
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('account')->andReturn($mockAccount);
        $mockAccount->shouldReceive('getAppPermissions')
            ->with('test_token', [])
            ->once()
            ->andReturn('invalid');
        
        VkApiTestService::setAdapter($mockAdapter);

        $result = VkApiTestService::getAppPermissions();

        $this->assertTrue($result['success']);
        $this->assertNull($result['bitmask']); // Некорректное значение должно стать null
    }

    /**
     * Тест проверки права через битовую маску - право есть
     */
    public function test_has_permission_returns_true_when_permission_exists()
    {
        // Битовая маска 1026 = 2 (friends) + 1024 (status)
        $bitmask = 1026;
        
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 2)); // friends
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 1024)); // status
    }

    /**
     * Тест проверки права через битовую маску - права нет
     */
    public function test_has_permission_returns_false_when_permission_missing()
    {
        // Битовая маска 1026 = 2 (friends) + 1024 (status)
        $bitmask = 1026;
        
        $this->assertFalse(VkApiTestService::hasPermission($bitmask, 4)); // photos
        $this->assertFalse(VkApiTestService::hasPermission($bitmask, 8)); // audio
        $this->assertFalse(VkApiTestService::hasPermission($bitmask, 8192)); // wall
    }

    /**
     * Тест проверки прав с реальными битовыми масками из документации
     */
    public function test_has_permission_with_documentation_bitmasks()
    {
        // Комбинация прав: friends (2) + photos (4) + wall (8192) + groups (262144) + offline (65536)
        // 2 + 4 + 8192 + 262144 + 65536 = 335878
        $bitmask = 335878;
        
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 2)); // friends
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 4)); // photos
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 8192)); // wall
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 262144)); // groups
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 65536)); // offline
        
        $this->assertFalse(VkApiTestService::hasPermission($bitmask, 8)); // audio
        $this->assertFalse(VkApiTestService::hasPermission($bitmask, 1024)); // status
    }

    /**
     * Тест проверки права на пустой битовой маске
     */
    public function test_has_permission_with_zero_bitmask()
    {
        $this->assertFalse(VkApiTestService::hasPermission(0, 2));
        $this->assertFalse(VkApiTestService::hasPermission(0, 8192));
        $this->assertFalse(VkApiTestService::hasPermission(0, 262144));
    }

    /**
     * Тест проверки права с большими битовыми масками
     */
    public function test_has_permission_with_large_bitmask()
    {
        // Большая битовая маска со всеми основными правами
        // wall (8192) + groups (262144) + photos (4) + audio (8) + offline (65536)
        $bitmask = 8192 + 262144 + 4 + 8 + 65536; // 335884
        
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 8192)); // wall
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 262144)); // groups
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 4)); // photos
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 8)); // audio
        $this->assertTrue(VkApiTestService::hasPermission($bitmask, 65536)); // offline
    }
}


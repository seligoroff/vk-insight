<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Services\VkApi\VkApiTestService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;

class TokenCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        VkApiTestService::setAdapter(null);
        parent::tearDown();
        Mockery::close();
    }
    /**
     * Создать мок адаптера для VkApiTestService
     */
    private function setupAdapterMock(array $mocks): VkSdkAdapter
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token')->byDefault();
        
        // Настройка моков для isTokenValid
        if (isset($mocks['isTokenValid'])) {
            $mockUsers = Mockery::mock();
            $mockAdapter->shouldReceive('users')->andReturn($mockUsers);
            
            if ($mocks['isTokenValid']) {
                $mockAdapter->shouldReceive('execute')
                    ->with(Mockery::type('Closure'), Mockery::any())
                    ->andReturnUsing(function($callback) {
                        return $callback();
                    });
                $mockUsers->shouldReceive('get')
                    ->with('test_token', [])
                    ->andReturn([['id' => 12345678]]);
            } else {
                $mockAdapter->shouldReceive('execute')
                    ->andThrow(new \Exception('Invalid token'));
            }
        }
        
        // Настройка моков для getCurrentUser
        if (isset($mocks['getCurrentUser'])) {
            $mockUsers = Mockery::mock();
            $mockAdapter->shouldReceive('users')->andReturn($mockUsers);
            $mockAdapter->shouldReceive('execute')
                ->with(Mockery::type('Closure'), Mockery::any())
                ->andReturnUsing(function($callback) {
                    return $callback();
                });
            $mockUsers->shouldReceive('get')
                ->with('test_token', Mockery::any())
                ->andReturn([$mocks['getCurrentUser']]);
        }
        
        // Настройка моков для getAppPermissions (используется через executeRaw)
        if (isset($mocks['getAppPermissions'])) {
            $mockAccount = Mockery::mock();
            $mockAdapter->shouldReceive('account')->andReturn($mockAccount);
            
            if (isset($mocks['getAppPermissions']['error'])) {
                $vkApiError = new \VK\Client\VKApiError([
                    'error_code' => $mocks['getAppPermissions']['error']['error_code'],
                    'error_msg' => $mocks['getAppPermissions']['error']['error_msg']
                ]);
                $exception = new \VK\Exceptions\VKApiException(
                    $mocks['getAppPermissions']['error']['error_code'],
                    $mocks['getAppPermissions']['error']['error_msg'],
                    $vkApiError
                );
                $mockAccount->shouldReceive('getAppPermissions')
                    ->with('test_token', Mockery::any())
                    ->andThrow($exception);
            } else {
                $mockAccount->shouldReceive('getAppPermissions')
                    ->with('test_token', Mockery::any())
                    ->andReturn($mocks['getAppPermissions']['bitmask']);
            }
        }
        
        // Настройка моков для testMethod (используется через executeRaw)
        // Поддерживаемые методы: wall.get, groups.get, photos.getAlbums, audio.get
        if (isset($mocks['testMethod'])) {
            foreach ($mocks['testMethod'] as $method => $result) {
                $parts = explode('.', $method);
                if (count($parts) === 2) {
                    [$apiName, $apiMethod] = $parts;
                    $apiObject = Mockery::mock();
                    $mockAdapter->shouldReceive($apiName)->andReturn($apiObject);
                    
                    if (isset($result['error'])) {
                        $vkApiError = new \VK\Client\VKApiError([
                            'error_code' => $result['error']['error_code'],
                            'error_msg' => $result['error']['error_msg']
                        ]);
                        $exception = new \VK\Exceptions\VKApiException(
                            $result['error']['error_code'],
                            $result['error']['error_msg'],
                            $vkApiError
                        );
                        $apiObject->shouldReceive($apiMethod)
                            ->with('test_token', Mockery::any())
                            ->andThrow($exception);
                    } else {
                        $apiObject->shouldReceive($apiMethod)
                            ->with('test_token', Mockery::any())
                            ->andReturn($result['response'] ?? []);
                    }
                }
            }
        }
        
        return $mockAdapter;
    }

    /**
     * Тест проверки прав через битовую маску
     */
    public function test_checks_permissions_via_bitmask()
    {
        // Битовая маска: wall (8192) + groups (262144) + photos (4) + offline (65536)
        // 8192 + 262144 + 4 + 65536 = 335876
        $bitmask = 335876;

        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => true,
            'getCurrentUser' => [
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ],
            'getAppPermissions' => ['bitmask' => $bitmask],
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check');

        // Команда должна успешно завершиться (все обязательные права есть)
        $command->assertExitCode(0);
    }

    /**
     * Тест fallback на старый метод, если account.getAppPermissions недоступен
     */
    public function test_fallback_to_legacy_method_when_getapppermissions_fails()
    {
        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => true,
            'getCurrentUser' => [
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ],
            'getAppPermissions' => [
                'error' => [
                    'error_code' => 15,
                    'error_msg' => 'Access denied'
                ]
            ],
            'testMethod' => [
                'wall.get' => ['response' => ['count' => 0, 'items' => []]],
                'groups.get' => ['response' => ['count' => 0, 'items' => []]],
                'photos.getAlbums' => ['response' => ['count' => 0, 'items' => []]],
                'audio.get' => ['response' => ['count' => 0, 'items' => []]],
            ],
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check');

        // Команда должна использовать fallback метод и успешно завершиться
        $command->assertExitCode(0);
    }

    /**
     * Тест гибридного подхода - право не найдено в битовой маске, но проверяется через API
     */
    public function test_hybrid_approach_checks_api_when_permission_not_in_bitmask()
    {
        // Битовая маска только с groups (262144) + offline (65536), без wall
        // 262144 + 65536 = 327680
        $bitmask = 327680;

        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => true,
            'getCurrentUser' => [
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ],
            'getAppPermissions' => ['bitmask' => $bitmask],
            'testMethod' => [
                'wall.get' => ['response' => ['count' => 0, 'items' => []]],
                'photos.getAlbums' => ['response' => ['count' => 0, 'items' => []]],
            ],
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check');

        // Команда должна использовать гибридный подход и успешно завершиться
        // (wall будет проверен через API, так как его нет в битовой маске)
        $command->assertExitCode(0);
    }

    /**
     * Тест использования опции --user-id
     */
    public function test_uses_user_id_option()
    {
        $bitmask = 335876;

        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => true,
            'getCurrentUser' => [
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ],
            'getAppPermissions' => ['bitmask' => $bitmask],
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check', ['--user-id' => '98765432']);

        $command->assertExitCode(0);
    }

    /**
     * Тест валидации user_id (должен быть положительным)
     */
    public function test_validates_user_id_is_positive()
    {
        $command = $this->artisan('vk:token-check', ['--user-id' => '-1']);

        $command->assertExitCode(1);
        $command->expectsOutput('Параметр --user-id должен быть положительным числом');
    }

    /**
     * Тест использования опции --token
     */
    public function test_uses_token_option()
    {
        $originalToken = config('vk.token');
        $testToken = 'test_custom_token';

        $bitmask = 335876;

        // Создаем адаптер с кастомным токеном
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockUsers = Mockery::mock();
        $mockAccount = Mockery::mock();
        
        $mockAdapter->shouldReceive('getToken')->andReturn($testToken);
        $mockAdapter->shouldReceive('users')->andReturn($mockUsers);
        $mockAdapter->shouldReceive('account')->andReturn($mockAccount);
        $mockAdapter->shouldReceive('execute')
            ->with(Mockery::type('Closure'), Mockery::any())
            ->andReturnUsing(function($callback) {
                return $callback();
            });
        $mockUsers->shouldReceive('get')
            ->with($testToken, [])
            ->andReturn([['id' => 12345678]]);
        $mockUsers->shouldReceive('get')
            ->with($testToken, Mockery::any())
            ->andReturn([[
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ]]);
        $mockAccount->shouldReceive('getAppPermissions')
            ->with($testToken, Mockery::any())
            ->andReturn($bitmask);
        
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check', ['--token' => $testToken]);

        $command->assertExitCode(0);
        
        // Проверяем, что токен в конфиге восстановлен
        $this->assertEquals($originalToken, config('vk.token'));
    }

    /**
     * Тест вывода в JSON формате
     */
    public function test_outputs_json_format()
    {
        $bitmask = 335876;

        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => true,
            'getCurrentUser' => [
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ],
            'getAppPermissions' => ['bitmask' => $bitmask],
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check', ['--format' => 'json']);

        // Команда должна успешно завершиться с JSON форматом
        $command->assertExitCode(0);
    }

    /**
     * Тест проверки прав с полной битовой маской (все права)
     */
    public function test_checks_permissions_with_full_bitmask()
    {
        // Все права: wall (8192) + groups (262144) + photos (4) + audio (8) + offline (65536)
        // 8192 + 262144 + 4 + 8 + 65536 = 335884
        $bitmask = 335884;

        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => true,
            'getCurrentUser' => [
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ],
            'getAppPermissions' => ['bitmask' => $bitmask],
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check', ['--format' => 'json']);

        // Команда должна успешно завершиться, так как все права присутствуют
        $command->assertExitCode(0);
    }

    /**
     * Тест проверки прав с частичной битовой маской (некоторые права отсутствуют)
     */
    public function test_checks_permissions_with_partial_bitmask()
    {
        // Только groups (262144) + offline (65536), без wall, photos, audio
        // 262144 + 65536 = 327680
        $bitmask = 327680;

        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => true,
            'getCurrentUser' => [
                'id' => 12345678,
                'first_name' => 'Test',
                'last_name' => 'User',
                'screen_name' => 'testuser',
            ],
            'getAppPermissions' => ['bitmask' => $bitmask],
            'testMethod' => [
                'wall.get' => ['response' => ['count' => 0, 'items' => []]],
                'photos.getAlbums' => ['response' => ['count' => 0, 'items' => []]],
                'audio.get' => ['response' => ['count' => 0, 'items' => []]],
            ],
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check', ['--format' => 'json']);

        // Команда должна использовать гибридный подход (битовая маска + API fallback)
        // и успешно завершиться
        $command->assertExitCode(0);
    }

    /**
     * Тест обработки ошибки при проверке валидности токена
     */
    public function test_handles_invalid_token()
    {
        $mockAdapter = $this->setupAdapterMock([
            'isTokenValid' => false,
        ]);
        VkApiTestService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:token-check');

        // Команда должна вернуть код ошибки при невалидном токене
        $command->assertExitCode(1);
    }
}


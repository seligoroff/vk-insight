<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class TokenCheckTest extends TestCase
{
    /**
     * Создать мок ответа для users.get
     */
    private function createUsersGetResponse(array $userData = []): array
    {
        $defaultUser = [
            'id' => 12345678,
            'first_name' => 'Test',
            'last_name' => 'User',
            'screen_name' => 'testuser',
        ];
        
        return [
            'response' => [
                array_merge($defaultUser, $userData)
            ]
        ];
    }

    /**
     * Создать мок ответа для account.getAppPermissions
     */
    private function createAppPermissionsResponse(int $bitmask): array
    {
        return [
            'response' => $bitmask
        ];
    }

    /**
     * Тест проверки прав через битовую маску
     */
    public function test_checks_permissions_via_bitmask()
    {
        // Битовая маска: wall (8192) + groups (262144) + photos (4) + offline (65536)
        // 8192 + 262144 + 4 + 65536 = 335876
        $bitmask = 335876;

        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response(
                $this->createUsersGetResponse(),
                200
            ),
            'https://api.vk.com/method/account.getAppPermissions*' => Http::response(
                $this->createAppPermissionsResponse($bitmask),
                200
            ),
        ]);

        $command = $this->artisan('vk:token-check');

        // Команда должна успешно завершиться (все обязательные права есть)
        $command->assertExitCode(0);
    }

    /**
     * Тест fallback на старый метод, если account.getAppPermissions недоступен
     */
    public function test_fallback_to_legacy_method_when_getapppermissions_fails()
    {
        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response(
                $this->createUsersGetResponse(),
                200
            ),
            'https://api.vk.com/method/account.getAppPermissions*' => Http::response([
                'error' => [
                    'error_code' => 15,
                    'error_msg' => 'Access denied'
                ]
            ], 200),
            'https://api.vk.com/method/wall.get*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
            'https://api.vk.com/method/groups.get*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
            'https://api.vk.com/method/photos.getAlbums*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
            'https://api.vk.com/method/audio.get*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
        ]);

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

        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response(
                $this->createUsersGetResponse(),
                200
            ),
            'https://api.vk.com/method/account.getAppPermissions*' => Http::response(
                $this->createAppPermissionsResponse($bitmask),
                200
            ),
            // wall.get должен быть вызван для дополнительной проверки, так как wall нет в битовой маске
            'https://api.vk.com/method/wall.get*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
            // photos.getAlbums должен быть вызван для дополнительной проверки
            'https://api.vk.com/method/photos.getAlbums*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
        ]);

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

        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response(
                $this->createUsersGetResponse(),
                200
            ),
            'https://api.vk.com/method/account.getAppPermissions*' => function ($request) use ($bitmask) {
                // Проверяем, что user_id передается в запросе
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);
                $this->assertEquals('98765432', $params['user_id'] ?? null);
                
                return Http::response(
                    $this->createAppPermissionsResponse($bitmask),
                    200
                );
            },
        ]);

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

        Http::fake([
            'https://api.vk.com/method/users.get*' => function ($request) use ($testToken) {
                // Проверяем, что используется переданный токен
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);
                $this->assertEquals($testToken, $params['access_token'] ?? null);
                
                return Http::response(
                    $this->createUsersGetResponse(),
                    200
                );
            },
            'https://api.vk.com/method/account.getAppPermissions*' => Http::response(
                $this->createAppPermissionsResponse($bitmask),
                200
            ),
        ]);

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

        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response(
                $this->createUsersGetResponse(),
                200
            ),
            'https://api.vk.com/method/account.getAppPermissions*' => Http::response(
                $this->createAppPermissionsResponse($bitmask),
                200
            ),
        ]);

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

        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response(
                $this->createUsersGetResponse(),
                200
            ),
            'https://api.vk.com/method/account.getAppPermissions*' => Http::response(
                $this->createAppPermissionsResponse($bitmask),
                200
            ),
        ]);

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

        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response(
                $this->createUsersGetResponse(),
                200
            ),
            'https://api.vk.com/method/account.getAppPermissions*' => Http::response(
                $this->createAppPermissionsResponse($bitmask),
                200
            ),
            // Дополнительные проверки через API для прав, которых нет в маске
            'https://api.vk.com/method/wall.get*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
            'https://api.vk.com/method/photos.getAlbums*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
            'https://api.vk.com/method/audio.get*' => Http::response([
                'response' => ['count' => 0, 'items' => []]
            ], 200),
        ]);

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
        Http::fake([
            'https://api.vk.com/method/users.get*' => Http::response([
                'error' => [
                    'error_code' => 5,
                    'error_msg' => 'Invalid access token'
                ]
            ], 200),
        ]);

        $command = $this->artisan('vk:token-check');

        // Команда должна вернуть код ошибки при невалидном токене
        $command->assertExitCode(1);
    }
}


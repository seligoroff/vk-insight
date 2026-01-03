<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkGroupService;
use Illuminate\Support\Facades\Http;

class VkGroupServiceTest extends TestCase
{
    /**
     * Создать мок ответа VK API для groups.getById
     */
    private function createGroupByIdResponse(array $groupData): array
    {
        return [
            'response' => [
                array_merge([
                    'id' => 12345678,
                    'name' => 'Test Group',
                    'screen_name' => 'testgroup',
                ], $groupData)
            ]
        ];
    }

    /**
     * Тест получения информации о группе без дополнительных полей
     */
    public function test_get_by_id_without_fields()
    {
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse([]),
                200
            ),
        ]);

        $group = VkGroupService::getById(12345678);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
        $this->assertEquals('Test Group', $group->name);
        $this->assertEquals('testgroup', $group->screen_name);
    }

    /**
     * Тест получения информации о группе с полем members_count
     */
    public function test_get_by_id_with_members_count()
    {
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse([
                    'members_count' => 5000
                ]),
                200
            ),
        ]);

        $group = VkGroupService::getById(12345678, ['members_count']);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
        $this->assertEquals(5000, $group->members_count);
    }

    /**
     * Тест получения информации о группе с несколькими полями
     */
    public function test_get_by_id_with_multiple_fields()
    {
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse([
                    'members_count' => 5000,
                    'description' => 'Test description',
                    'status' => 'Active'
                ]),
                200
            ),
        ]);

        $group = VkGroupService::getById(12345678, ['members_count', 'description', 'status']);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
        $this->assertEquals(5000, $group->members_count);
        $this->assertEquals('Test description', $group->description);
        $this->assertEquals('Active', $group->status);
    }

    /**
     * Тест обратной совместимости - вызов без параметра fields
     */
    public function test_get_by_id_backward_compatibility()
    {
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse([]),
                200
            ),
        ]);

        // Вызов без второго параметра (старый способ)
        $group = VkGroupService::getById(12345678);

        $this->assertNotNull($group);
        $this->assertEquals(12345678, $group->id);
    }

    /**
     * Тест валидации полей - пустые строки должны игнорироваться
     */
    public function test_get_by_id_filters_empty_fields()
    {
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => function ($request) {
                // Проверяем, что в запросе только валидные поля
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);
                $this->assertStringContainsString('members_count', $params['fields'] ?? '');
                $this->assertStringNotContainsString('  ', $params['fields'] ?? ''); // Нет двойных пробелов
                
                return Http::response(
                    $this->createGroupByIdResponse(['members_count' => 5000]),
                    200
                );
            },
        ]);

        $group = VkGroupService::getById(12345678, ['members_count', '', '  ', 'description']);

        $this->assertNotNull($group);
        $this->assertEquals(5000, $group->members_count);
    }

    /**
     * Тест обработки пустого ответа от API
     */
    public function test_get_by_id_handles_empty_response()
    {
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => []
            ], 200),
        ]);

        $group = VkGroupService::getById(12345678);

        $this->assertNull($group);
    }

    /**
     * Тест обработки ошибки API
     */
    public function test_get_by_id_handles_api_error()
    {
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'error' => [
                    'error_code' => 100,
                    'error_msg' => 'One of the parameters specified was missing or invalid'
                ]
            ], 200),
        ]);

        $group = VkGroupService::getById(12345678);

        // При ошибке API parseResponse вернет null
        $this->assertNull($group);
    }
}


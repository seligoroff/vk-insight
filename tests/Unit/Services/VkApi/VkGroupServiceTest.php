<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkGroupService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;

class VkGroupServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        // Сбрасываем адаптер после каждого теста
        VkGroupService::setAdapter(null);
        parent::tearDown();
    }
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
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockGroups = Mockery::mock();
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('groups')->andReturn($mockGroups);
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

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
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
            'members_count' => 5000,
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

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
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
            'members_count' => 5000,
            'description' => 'Test description',
            'status' => 'Active',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

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
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

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
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $groupData = [
            'id' => 12345678,
            'name' => 'Test Group',
            'screen_name' => 'testgroup',
            'members_count' => 5000,
            'description' => 'Test description',
        ];
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$groupData]);
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678, ['members_count', '', '  ', 'description']);

        // Проверяем, что пустые поля были отфильтрованы и запрос прошел успешно
        $this->assertNotNull($group);
        $this->assertEquals(5000, $group->members_count);
        $this->assertEquals('Test description', $group->description);
    }

    /**
     * Тест обработки пустого ответа от API
     */
    public function test_get_by_id_handles_empty_response()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([]);
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678);

        $this->assertNull($group);
    }

    /**
     * Тест обработки ошибки API
     */
    public function test_get_by_id_handles_api_error()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andThrow(new \Exception('VK API Error: One of the parameters specified was missing or invalid', 100));
        
        VkGroupService::setAdapter($mockAdapter);

        $group = VkGroupService::getById(12345678);

        // При ошибке API метод вернет null
        $this->assertNull($group);
    }
}


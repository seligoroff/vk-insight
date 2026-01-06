<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Services\VkApi\VkGroupService;
use Illuminate\Support\Facades\File;
use stdClass;
use Mockery;

class GroupsInfoMockTest extends TestCase
{
    private $testCsvFile;
    private $backupFile;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Создаем тестовый CSV файл
        $this->testCsvFile = resource_path('vk-groups.csv');
        $this->backupFile = resource_path('vk-groups.csv.backup');
        
        // Сохраняем оригинальный файл, если существует
        if (file_exists($this->testCsvFile)) {
            copy($this->testCsvFile, $this->backupFile);
        }
    }

    protected function tearDown(): void
    {
        // Сбрасываем адаптер VkGroupService
        VkGroupService::setAdapter(null);
        
        // Восстанавливаем оригинальный файл
        if (file_exists($this->backupFile)) {
            copy($this->backupFile, $this->testCsvFile);
            unlink($this->backupFile);
        } elseif (file_exists($this->testCsvFile)) {
            // Удаляем тестовый файл, если оригинального не было
            unlink($this->testCsvFile);
        }
        
        parent::tearDown();
    }

    /**
     * Создать тестовый CSV файл
     */
    private function createTestCsvFile(array $groups): void
    {
        $lines = array_map(function($group) {
            return "https://vk.com/{$group}";
        }, $groups);
        
        file_put_contents($this->testCsvFile, implode("\n", $lines));
    }

    /**
     * Создать мок ответа для utils.resolveScreenName
     */
    private function createResolveResponse(int $objectId, string $type = 'group'): array
    {
        return [
            'response' => [
                'type' => $type,
                'object_id' => $objectId
            ]
        ];
    }

    /**
     * Создать мок ответа для groups.getById
     */
    private function createGroupByIdResponse(string $name, int $id): array
    {
        return [
            'response' => [
                [
                    'id' => $id,
                    'name' => $name,
                    'screen_name' => 'testgroup',
                ]
            ]
        ];
    }

    /**
     * Тест получения информации о группах с моками
     * 
     * Примечание: Тест использует реальный VkGroupService, но мокирует SDK адаптер
     */
    public function test_gets_groups_info_with_mocks()
    {
        $this->createTestCsvFile(['group1', 'group2']);

        // Мокируем SDK адаптер для VkGroupService
        $mockAdapter = Mockery::mock(\App\Services\VkApi\VkSdkAdapter::class);
        
        $resolveMeta1 = ['type' => 'group', 'object_id' => 12345678];
        $resolveMeta2 = ['type' => 'group', 'object_id' => 87654321];
        
        $group1Data = ['id' => 12345678, 'name' => 'Test Group 1', 'screen_name' => 'testgroup'];
        $group2Data = ['id' => 87654321, 'name' => 'Test Group 2', 'screen_name' => 'testgroup2'];
        
        // Настраиваем resolveName - два вызова
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('utils')->andReturn(Mockery::mock());
        $mockAdapter->shouldReceive('execute')
            ->with(Mockery::type('Closure'), Mockery::pattern('/resolving screen name \'group1\'/'))
            ->once()
            ->andReturn($resolveMeta1);
        $mockAdapter->shouldReceive('execute')
            ->with(Mockery::type('Closure'), Mockery::pattern('/resolving screen name \'group2\'/'))
            ->once()
            ->andReturn($resolveMeta2);
        
        // Настраиваем getById - два вызова
        $mockAdapter->shouldReceive('groups')->andReturn(Mockery::mock());
        $mockAdapter->shouldReceive('execute')
            ->with(Mockery::type('Closure'), Mockery::pattern('/getting group by ID 12345678/'))
            ->once()
            ->andReturn([$group1Data]);
        $mockAdapter->shouldReceive('execute')
            ->with(Mockery::type('Closure'), Mockery::pattern('/getting group by ID 87654321/'))
            ->once()
            ->andReturn([$group2Data]);
        
        VkGroupService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:groups-info');

        $command->assertExitCode(0);
    }

    /**
     * Тест обработки ошибки резолва группы
     */
    public function test_handles_resolve_error()
    {
        $this->createTestCsvFile(['nonexistent']);

        $mockAdapter = Mockery::mock(\App\Services\VkApi\VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('utils')->andReturn(Mockery::mock());
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(null); // resolveName возвращает null при ошибке
        
        VkGroupService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:groups-info');

        // Команда возвращает 1, если не удалось получить информацию ни об одной группе
        $command->assertExitCode(1);
    }

    /**
     * Тест обработки ошибки получения информации о группе
     */
    public function test_handles_get_by_id_error()
    {
        $this->createTestCsvFile(['group1']);

        $mockAdapter = Mockery::mock(\App\Services\VkApi\VkSdkAdapter::class);
        
        // resolveName успешно возвращает мета-данные
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('utils')->andReturn(Mockery::mock());
        $mockAdapter->shouldReceive('execute')
            ->with(Mockery::type('Closure'), Mockery::pattern('/resolving screen name/'))
            ->once()
            ->andReturn(['type' => 'group', 'object_id' => 12345678]);
        
        // getById возвращает null (пустой ответ)
        $mockAdapter->shouldReceive('groups')->andReturn(Mockery::mock());
        $mockAdapter->shouldReceive('execute')
            ->with(Mockery::type('Closure'), Mockery::pattern('/getting group by ID/'))
            ->once()
            ->andReturn([]); // Пустой массив
        
        VkGroupService::setAdapter($mockAdapter);

        $command = $this->artisan('vk:groups-info');

        // Команда возвращает 1, если не удалось получить информацию ни об одной группе
        $command->assertExitCode(1);
    }

    /**
     * Тест обработки пустого списка групп
     */
    public function test_handles_empty_groups_list()
    {
        $this->createTestCsvFile([]);

        $command = $this->artisan('vk:groups-info');

        $command->assertExitCode(1);
    }

    /**
     * Тест обработки отсутствия файла
     */
    public function test_handles_missing_file()
    {
        if (file_exists($this->testCsvFile)) {
            unlink($this->testCsvFile);
        }

        $command = $this->artisan('vk:groups-info');

        $command->assertExitCode(1);
    }
}


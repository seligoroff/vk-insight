<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use stdClass;

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
     */
    public function test_gets_groups_info_with_mocks()
    {
        $this->createTestCsvFile(['group1', 'group2']);

        Http::fake([
            'https://api.vk.com/method/utils.resolveScreenName*' => Http::sequence()
                ->push($this->createResolveResponse(12345678, 'group'), 200)
                ->push($this->createResolveResponse(87654321, 'group'), 200),
            'https://api.vk.com/method/groups.getById*' => Http::sequence()
                ->push($this->createGroupByIdResponse('Test Group 1', 12345678), 200)
                ->push($this->createGroupByIdResponse('Test Group 2', 87654321), 200),
        ]);

        $command = $this->artisan('vk:groups-info');

        $command->assertExitCode(0);
        
        // Проверяем, что команда выполнилась успешно
        // (моки HTTP запросов проверяются автоматически через Http::fake)
    }

    /**
     * Тест обработки ошибки резолва группы
     */
    public function test_handles_resolve_error()
    {
        $this->createTestCsvFile(['nonexistent']);

        Http::fake([
            'https://api.vk.com/method/utils.resolveScreenName*' => Http::response([
                'response' => null
            ], 200),
        ]);

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

        Http::fake([
            'https://api.vk.com/method/utils.resolveScreenName*' => Http::response(
                $this->createResolveResponse(12345678, 'group'), 
                200
            ),
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => []
            ], 200),
        ]);

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


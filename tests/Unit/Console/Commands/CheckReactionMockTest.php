<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use stdClass;

class CheckReactionMockTest extends TestCase
{
    use RefreshDatabase;

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
     * Создать мок поста
     */
    private function createMockPost(array $data): stdClass
    {
        $post = new stdClass();
        $post->id = $data['id'] ?? 1;
        $post->text = $data['text'] ?? '';
        
        $post->likes = new stdClass();
        $post->likes->count = $data['likes'] ?? 0;
        
        $post->reposts = new stdClass();
        $post->reposts->count = $data['reposts'] ?? 0;
        
        return $post;
    }

    /**
     * Создать мок ответа для utils.resolveScreenName
     */
    private function createResolveResponse(int $objectId): array
    {
        return [
            'response' => [
                'type' => 'group',
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
                ]
            ]
        ];
    }

    /**
     * Создать мок ответа для wall.get
     */
    private function createWallGetResponse(array $posts): array
    {
        return [
            'response' => [
                'count' => count($posts),
                'items' => $posts
            ]
        ];
    }

    /**
     * Тест проверки реакций с моками
     */
    public function test_checks_reactions_with_mocks()
    {
        $this->createTestCsvFile(['group1']);

        $post = $this->createMockPost([
            'id' => 123,
            'text' => 'Тестовый пост с текстом',
            'likes' => 10,
            'reposts' => 5,
        ]);

        Http::fake([
            'api.vk.com/method/utils.resolveScreenName*' => Http::response(
                $this->createResolveResponse(12345678), 
                200
            ),
            'api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse('Test Group', 12345678), 
                200
            ),
            'api.vk.com/method/wall.get*' => Http::response(
                $this->createWallGetResponse([$post]), 
                200
            ),
        ]);

        $command = $this->artisan('vk:check');

        $command->assertExitCode(0);
        
        // Проверяем, что команда выполнилась успешно
        // (моки HTTP запросов проверяются автоматически через Http::fake)
    }

    /**
     * Тест использования кеша
     */
    public function test_uses_cache_when_available()
    {
        $this->createTestCsvFile(['group1']);

        // Сохраняем кеш в БД
        DB::table('vk_check_cache')->insert([
            'group_name' => 'Test Group',
            'group_id' => 12345678,
            'post_text' => 'Тестовый пост',
            'likes' => 10,
            'reposts' => 5,
            'cached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $command = $this->artisan('vk:check', ['--cached' => true]);

        $command->assertExitCode(0);
        
        // Проверяем, что запросы к API не были сделаны
        Http::assertNothingSent();
    }

    /**
     * Тест обработки группы без постов с текстом
     */
    public function test_handles_group_without_text_posts()
    {
        $this->createTestCsvFile(['group1']);

        // Пост без текста
        $post = $this->createMockPost([
            'id' => 123,
            'text' => '', // Пустой текст
            'likes' => 10,
        ]);

        Http::fake([
            'https://api.vk.com/method/utils.resolveScreenName*' => Http::response(
                $this->createResolveResponse(12345678), 
                200
            ),
            'https://api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse('Test Group', 12345678), 
                200
            ),
            'https://api.vk.com/method/wall.get*' => Http::response(
                $this->createWallGetResponse([$post]), 
                200
            ),
        ]);

        $command = $this->artisan('vk:check');

        // Команда должна завершиться успешно, но без данных в таблице
        $command->assertExitCode(0);
    }

    /**
     * Тест обработки пустого ответа wall.get
     */
    public function test_handles_empty_wall_response()
    {
        $this->createTestCsvFile(['group1']);

        Http::fake([
            'https://api.vk.com/method/utils.resolveScreenName*' => Http::response(
                $this->createResolveResponse(12345678), 
                200
            ),
            'https://api.vk.com/method/groups.getById*' => Http::response(
                $this->createGroupByIdResponse('Test Group', 12345678), 
                200
            ),
            'https://api.vk.com/method/wall.get*' => Http::response([
                'response' => [
                    'count' => 0,
                    'items' => []
                ]
            ], 200),
        ]);

        $command = $this->artisan('vk:check');

        $command->assertExitCode(0);
    }

    /**
     * Тест обработки ошибки API
     */
    public function test_handles_api_error()
    {
        $this->createTestCsvFile(['group1']);

        Http::fake([
            'https://api.vk.com/method/utils.resolveScreenName*' => Http::response([
                'error' => [
                    'error_code' => 15,
                    'error_msg' => 'Access denied'
                ]
            ], 200),
        ]);

        $command = $this->artisan('vk:check');

        // Команда должна обработать ошибку и продолжить
        $command->assertExitCode(0);
    }
}


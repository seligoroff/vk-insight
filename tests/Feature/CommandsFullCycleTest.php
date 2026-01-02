<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use stdClass;

class CommandsFullCycleTest extends TestCase
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
        
        // Кеш очищается автоматически через RefreshDatabase
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
        $post->date = $data['date'] ?? time();
        $post->text = $data['text'] ?? '';
        
        $post->likes = new stdClass();
        $post->likes->count = $data['likes'] ?? 0;
        
        $post->reposts = new stdClass();
        $post->reposts->count = $data['reposts'] ?? 0;
        
        $post->comments = new stdClass();
        $post->comments->count = $data['comments'] ?? 0;
        
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
     * Тест полного цикла: получение информации о группах -> получение постов -> сохранение в БД
     */
    public function test_full_cycle_groups_info_to_posts_to_database()
    {
        $this->createTestCsvFile(['testgroup']);

        $post = $this->createMockPost([
            'id' => 123,
            'date' => 1672531200,
            'text' => 'Тестовый пост',
            'likes' => 10,
            'reposts' => 5,
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

        // Шаг 1: Получение информации о группах
        $this->artisan('vk:groups-info')
            ->assertExitCode(0);

        // Шаг 2: Получение постов и сохранение в БД
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Проверяем, что пост сохранен в БД
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 123,
            'owner_id' => '-12345678',
            'text' => 'Тестовый пост',
        ]);
    }

    /**
     * Тест полного цикла: проверка реакций -> получение постов -> сохранение в БД
     */
    public function test_full_cycle_check_reaction_to_posts_to_database()
    {
        $this->createTestCsvFile(['testgroup']);

        $post = $this->createMockPost([
            'id' => 124,
            'date' => 1672531200,
            'text' => 'Пост для проверки реакций',
            'likes' => 15,
            'reposts' => 8,
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

        // Шаг 1: Проверка реакций
        $this->artisan('vk:check')
            ->assertExitCode(0);

        // Шаг 2: Получение постов и сохранение в БД
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Проверяем, что пост сохранен в БД
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 124,
            'owner_id' => '-12345678',
            'likes' => 15,
            'reposts' => 8,
        ]);
    }

    /**
     * Тест полного цикла с фильтрацией постов
     */
    public function test_full_cycle_with_post_filtering()
    {
        $this->createTestCsvFile(['testgroup']);

        $posts = [
            $this->createMockPost([
                'id' => 125,
                'date' => 1672531200,
                'text' => 'Пост с текстом',
                'likes' => 20, // Много лайков
            ]),
            $this->createMockPost([
                'id' => 126,
                'date' => 1672531200,
                'text' => '', // Без текста
                'likes' => 5, // Мало лайков
            ]),
        ];

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
                $this->createWallGetResponse($posts), 
                200
            ),
        ]);

        // Получение постов с фильтрацией: только с текстом и минимум 10 лайков
        $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--with-text-only' => true,
            '--min-likes' => 10,
            '--db' => true,
        ])->assertExitCode(0);

        // Должен сохраниться только один пост (125), второй отфильтрован
        $this->assertDatabaseCount('vk_posts', 1);
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 125,
            'text' => 'Пост с текстом',
            'likes' => 20,
        ]);
    }

    /**
     * Тест полного цикла с несколькими группами
     */
    public function test_full_cycle_with_multiple_groups()
    {
        $this->createTestCsvFile(['group1', 'group2']);

        $post1 = $this->createMockPost([
            'id' => 201,
            'date' => 1672531200,
            'text' => 'Пост группы 1',
        ]);

        $post2 = $this->createMockPost([
            'id' => 202,
            'date' => 1672531200,
            'text' => 'Пост группы 2',
        ]);

        Http::fake([
            'https://api.vk.com/method/utils.resolveScreenName*' => Http::sequence()
                ->push($this->createResolveResponse(11111111), 200)
                ->push($this->createResolveResponse(22222222), 200),
            'https://api.vk.com/method/groups.getById*' => Http::sequence()
                ->push($this->createGroupByIdResponse('Group 1', 11111111), 200)
                ->push($this->createGroupByIdResponse('Group 2', 22222222), 200),
            'https://api.vk.com/method/wall.get*' => Http::sequence()
                ->push($this->createWallGetResponse([$post1]), 200)
                ->push($this->createWallGetResponse([$post2]), 200),
        ]);

        // Получение информации о группах
        $this->artisan('vk:groups-info')
            ->assertExitCode(0);

        // Сохранение постов первой группы
        $this->artisan('vk:posts-get', [
            '--owner' => '-11111111',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Сохранение постов второй группы
        $this->artisan('vk:posts-get', [
            '--owner' => '-22222222',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--db' => true,
        ])->assertExitCode(0);

        // Проверяем, что оба поста сохранены
        $this->assertDatabaseCount('vk_posts', 2);
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 201,
            'owner_id' => '-11111111',
        ]);
        $this->assertDatabaseHas('vk_posts', [
            'post_id' => 202,
            'owner_id' => '-22222222',
        ]);
    }
}


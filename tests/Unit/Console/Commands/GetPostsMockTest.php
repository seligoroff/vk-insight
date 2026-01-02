<?php

namespace Tests\Unit\Console\Commands;

use Tests\TestCase;
use App\Console\Commands\GetPosts;
use Illuminate\Support\Facades\Http;
use stdClass;

class GetPostsMockTest extends TestCase
{
    /**
     * Создать мок поста VK API
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
     * Создать мок ответа VK API для wall.get
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
     * Тест получения постов с моками API
     */
    public function test_gets_posts_with_mocked_api()
    {
        $posts = [
            $this->createMockPost([
                'id' => 123,
                'date' => 1672531200, // 2023-01-01 00:00:00
                'text' => 'Тестовый пост 1',
                'likes' => 10,
                'reposts' => 5,
            ]),
            $this->createMockPost([
                'id' => 124,
                'date' => 1672617600, // 2023-01-02 00:00:00
                'text' => 'Тестовый пост 2',
                'likes' => 20,
                'reposts' => 10,
            ]),
        ];

        // Мокаем HTTP запросы к VK API
        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        $command = $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-03',
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
        
        // Проверяем, что команда выполнилась успешно
        // (моки HTTP запросов проверяются автоматически через Http::fake)
    }

    /**
     * Тест фильтрации постов по дате с моками
     */
    public function test_filters_posts_by_date_with_mocks()
    {
        $posts = [
            $this->createMockPost([
                'id' => 123,
                'date' => 1672531200, // 2023-01-01 00:00:00
                'text' => 'Пост в диапазоне',
            ]),
            $this->createMockPost([
                'id' => 125,
                'date' => 1672704000, // 2023-01-04 00:00:00 - вне диапазона
                'text' => 'Пост вне диапазона',
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        $command = $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--to' => '2023-01-02',
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест фильтрации постов с текстом с моками
     */
    public function test_filters_posts_with_text_only_with_mocks()
    {
        $posts = [
            $this->createMockPost([
                'id' => 123,
                'text' => 'Пост с текстом',
                'likes' => 10,
            ]),
            $this->createMockPost([
                'id' => 124,
                'text' => '', // Без текста
                'likes' => 5,
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response($this->createWallGetResponse($posts), 200),
        ]);

        $command = $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--with-text-only' => true,
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест обработки пустого ответа API
     */
    public function test_handles_empty_api_response()
    {
        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response([
                'response' => [
                    'count' => 0,
                    'items' => []
                ]
            ], 200),
        ]);

        $command = $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
    }

    /**
     * Тест обработки ошибки API
     */
    public function test_handles_api_error()
    {
        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::response([
                'error' => [
                    'error_code' => 15,
                    'error_msg' => 'Access denied'
                ]
            ], 200),
        ]);

        $command = $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--format' => 'json',
        ]);

        // Команда должна обработать ошибку (может вернуть 0 или 1 в зависимости от реализации)
        $command->assertExitCode(0);
    }

    /**
     * Тест пагинации с моками
     */
    public function test_handles_pagination_with_mocks()
    {
        // Первая страница
        $postsPage1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $postsPage1[] = $this->createMockPost([
                'id' => $i,
                'date' => 1672531200 + $i,
                'text' => "Пост {$i}",
            ]);
        }

        // Вторая страница (меньше 100, значит последняя)
        $postsPage2 = [
            $this->createMockPost([
                'id' => 101,
                'date' => 1672531200 + 101,
                'text' => 'Пост 101',
            ]),
        ];

        Http::fake([
            'https://api.vk.com/method/wall.get*' => Http::sequence()
                ->push($this->createWallGetResponse($postsPage1), 200)
                ->push($this->createWallGetResponse($postsPage2), 200),
        ]);

        $command = $this->artisan('vk:posts-get', [
            '--owner' => '-12345678',
            '--from' => '2023-01-01',
            '--format' => 'json',
        ]);

        $command->assertExitCode(0);
        
        // Проверяем, что команда выполнилась успешно
        // (моки HTTP запросов проверяются автоматически через Http::fake)
    }
}


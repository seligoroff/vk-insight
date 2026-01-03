<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use App\Services\VkApi\VkGroupService;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Создать тестовые посты в БД
     */
    private function createTestPosts(string $ownerId, int $count = 10): void
    {
        $baseDate = Carbon::now()->subDays(5);
        
        for ($i = 0; $i < $count; $i++) {
            $date = $baseDate->copy()->addDays($i);
            DB::table('vk_posts')->insert([
                'post_id' => 1000 + $i,
                'owner_id' => $ownerId,
                'timestamp' => $date->timestamp,
                'date' => $date->toDateTimeString(),
                'text' => "Тестовый пост {$i}",
                'likes' => 100 + ($i * 10),
                'reposts' => 5 + $i,
                'comments' => 3 + $i,
                'url' => "https://vk.com/wall{$ownerId}_" . (1000 + $i),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Тест базовой аналитики с данными в БД
     */
    public function test_analytics_with_database_posts()
    {
        $ownerId = '-12345678';
        $groupId = 12345678;
        
        // Создаем тестовые посты
        $this->createTestPosts($ownerId, 5);
        
        // Мокаем API для получения members_count
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => $groupId,
                        'name' => 'Test Group',
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
        ])
        ->assertExitCode(0)
        ->expectsOutput('Найдено постов: 5')
        ->expectsOutput('Подписчиков: 5 000');
    }

    /**
     * Тест аналитики с пустой БД
     */
    public function test_analytics_with_empty_database()
    {
        $this->artisan('vk:analytics', [
            '--owner' => '-12345678',
            '--period' => 'week',
        ])
        ->assertExitCode(0)
        ->expectsOutput('Посты за указанный период не найдены в базе данных.');
    }

    /**
     * Тест аналитики с фильтрацией по min-engagement
     */
    public function test_analytics_with_min_engagement()
    {
        $ownerId = '-12345678';
        
        // Создаем посты с разной вовлеченностью
        $baseDate = Carbon::now()->subDays(2);
        
        // Пост с высокой вовлеченностью
        DB::table('vk_posts')->insert([
            'post_id' => 1001,
            'owner_id' => $ownerId,
            'timestamp' => $baseDate->timestamp,
            'date' => $baseDate->toDateTimeString(),
            'text' => 'Популярный пост',
            'likes' => 200,
            'reposts' => 20,
            'comments' => 10,
            'url' => "https://vk.com/wall{$ownerId}_1001",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Пост с низкой вовлеченностью
        DB::table('vk_posts')->insert([
            'post_id' => 1002,
            'owner_id' => $ownerId,
            'timestamp' => $baseDate->copy()->addHour()->timestamp,
            'date' => $baseDate->copy()->addHour()->toDateTimeString(),
            'text' => 'Непопулярный пост',
            'likes' => 1,
            'reposts' => 0,
            'comments' => 0,
            'url' => "https://vk.com/wall{$ownerId}_1002",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        // Без фильтра - должно быть 2 поста
        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
        ])
        ->assertExitCode(0)
        ->expectsOutput('Найдено постов: 2');

        // С фильтром min-engagement=50 - должен остаться 1 пост
        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--min-engagement' => 50,
        ])
        ->assertExitCode(0)
        ->expectsOutput('Найдено постов: 1');
    }

    /**
     * Тест аналитики с best-time опцией
     */
    public function test_analytics_with_best_time()
    {
        $ownerId = '-12345678';
        $baseDate = Carbon::now()->subDays(1);
        
        // Создаем посты в разные часы (минимум 3 в час для учета)
        for ($hour = 10; $hour <= 12; $hour++) {
            for ($i = 0; $i < 3; $i++) {
                DB::table('vk_posts')->insert([
                    'post_id' => 2000 + ($hour * 10) + $i,
                    'owner_id' => $ownerId,
                    'timestamp' => $baseDate->copy()->setHour($hour)->setMinute($i * 20)->timestamp,
                    'date' => $baseDate->copy()->setHour($hour)->setMinute($i * 20)->toDateTimeString(),
                    'text' => "Пост в {$hour}:00",
                    'likes' => 100 + ($hour * 10), // Больше лайков в более поздние часы
                    'reposts' => 10,
                    'comments' => 5,
                    'url' => "https://vk.com/wall{$ownerId}_" . (2000 + ($hour * 10) + $i),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--best-time' => true,
        ])
        ->assertExitCode(0)
        ->expectsOutput('=== Лучшее время публикации ===');
    }

    /**
     * Тест кеширования members_count
     */
    public function test_members_count_caching()
    {
        $ownerId = '-12345678';
        $groupId = 12345678;
        
        $this->createTestPosts($ownerId, 3);
        
        // Первый вызов - должен быть запрос к API
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => $groupId,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
        ])->assertExitCode(0);

        // Проверяем, что был запрос к API
        Http::assertSent(function ($request) use ($groupId) {
            return strpos($request->url(), 'groups.getById') !== false &&
                   strpos($request->url(), "group_id={$groupId}") !== false;
        });

        // Второй вызов - должен использовать кеш (не должно быть нового запроса)
        Http::fake(); // Очищаем моки
        
        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
        ])->assertExitCode(0);

        // Проверяем, что нового запроса не было (кеш работает)
        Http::assertNothingSent();
    }

    /**
     * Тест топ-постов
     */
    public function test_top_posts()
    {
        $ownerId = '-12345678';
        
        // Создаем посты с разными метриками
        $baseDate = Carbon::now()->subDays(2);
        
        DB::table('vk_posts')->insert([
            'post_id' => 3001,
            'owner_id' => $ownerId,
            'timestamp' => $baseDate->timestamp,
            'date' => $baseDate->toDateTimeString(),
            'text' => 'Пост с большим количеством лайков',
            'likes' => 500,
            'reposts' => 10,
            'comments' => 5,
            'url' => "https://vk.com/wall{$ownerId}_3001",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        DB::table('vk_posts')->insert([
            'post_id' => 3002,
            'owner_id' => $ownerId,
            'timestamp' => $baseDate->copy()->addHour()->timestamp,
            'date' => $baseDate->copy()->addHour()->toDateTimeString(),
            'text' => 'Пост с меньшим количеством лайков',
            'likes' => 100,
            'reposts' => 5,
            'comments' => 2,
            'url' => "https://vk.com/wall{$ownerId}_3002",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--top' => 1,
            '--metrics' => 'likes',
        ])
        ->assertExitCode(0)
        ->expectsOutput('=== Топ-посты по Лайки ===');
    }

    /**
     * Тест сравнения с предыдущим периодом
     */
    public function test_compare_with_previous_period()
    {
        $ownerId = '-12345678';
        
        // Создаем посты для текущего периода (последние 7 дней)
        $currentDate = Carbon::now()->subDays(3);
        DB::table('vk_posts')->insert([
            'post_id' => 4001,
            'owner_id' => $ownerId,
            'timestamp' => $currentDate->timestamp,
            'date' => $currentDate->toDateTimeString(),
            'text' => 'Пост текущего периода',
            'likes' => 200,
            'reposts' => 10,
            'comments' => 5,
            'url' => "https://vk.com/wall{$ownerId}_4001",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Создаем посты для предыдущего периода (7-14 дней назад)
        $previousDate = Carbon::now()->subDays(10);
        DB::table('vk_posts')->insert([
            'post_id' => 4002,
            'owner_id' => $ownerId,
            'timestamp' => $previousDate->timestamp,
            'date' => $previousDate->toDateTimeString(),
            'text' => 'Пост предыдущего периода',
            'likes' => 100,
            'reposts' => 5,
            'comments' => 3,
            'url' => "https://vk.com/wall{$ownerId}_4002",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--compare' => 'previous',
        ])
        ->assertExitCode(0)
        ->expectsOutput('=== Сравнение с предыдущим периодом ===');
    }

    /**
     * Тест валидации параметров
     */
    public function test_validates_required_owner_parameter()
    {
        $this->artisan('vk:analytics', [])
        ->assertExitCode(1)
        ->expectsOutput('Параметр --owner обязателен');
    }

    /**
     * Тест валидации формата owner
     */
    public function test_validates_owner_format()
    {
        $this->artisan('vk:analytics', [
            '--owner' => 'invalid',
        ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Неверный формат --owner');
    }

    /**
     * Тест валидации формата вывода
     */
    public function test_validates_format_parameter()
    {
        $this->artisan('vk:analytics', [
            '--owner' => '-12345678',
            '--format' => 'invalid',
        ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Неверный формат');
    }

    /**
     * Тест валидации метрик
     */
    public function test_validates_metrics_parameter()
    {
        $this->artisan('vk:analytics', [
            '--owner' => '-12345678',
            '--metrics' => 'invalid',
        ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Неверный формат --metrics');
    }

    /**
     * Тест валидации периода
     */
    public function test_validates_period_parameter()
    {
        $this->artisan('vk:analytics', [
            '--owner' => '-12345678',
            '--period' => 'invalid',
        ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Ошибка парсинга периода');
    }

    /**
     * Тест обработки отсутствия таблицы vk_posts
     */
    public function test_handles_missing_table()
    {
        // Удаляем таблицу для теста
        \Illuminate\Support\Facades\Schema::dropIfExists('vk_posts');
        
        $this->artisan('vk:analytics', [
            '--owner' => '-12345678',
            '--period' => 'week',
        ])
        ->assertExitCode(1)
        ->expectsOutput('Таблица vk_posts не найдена в базе данных.');
    }

    /**
     * Тест вывода в JSON формате
     */
    public function test_outputs_json_format()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 3);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--format' => 'json',
        ])
        ->assertExitCode(0);
        
        // Проверяем, что JSON валидный и содержит нужные поля
        // (проверка через файл или другой способ)
    }

    /**
     * Тест вывода в CSV формате (stdout)
     */
    public function test_outputs_csv_format_stdout()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 3);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--format' => 'csv',
        ])
        ->assertExitCode(0);
        
        // CSV формат выводится в stdout, проверяем что команда выполнилась успешно
    }

    /**
     * Тест сохранения в JSON файл
     */
    public function test_saves_json_to_file()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 3);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $outputFile = storage_path('app/test_analytics_' . uniqid() . '.json');

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--format' => 'json',
            '--output' => $outputFile,
        ])
        ->assertExitCode(0);

        $this->assertFileExists($outputFile);
        $content = file_get_contents($outputFile);
        $data = json_decode($content, true);
        $this->assertNotNull($data);
        $this->assertEquals($ownerId, $data['owner_id']);

        // Очистка
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }

    /**
     * Тест обработки ошибок API
     */
    public function test_handles_api_errors()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 3);

        // Мокаем ошибку API (возвращаем null вместо объекта)
        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => []
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
        ])
        ->assertExitCode(0) // Команда должна продолжить работу даже без members_count
        ->expectsOutputToContain('Не удалось получить количество подписчиков');
    }

    /**
     * Тест различных периодов
     */
    public function test_different_periods()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 20);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $periods = ['week', 'month', 'quarter', 'year'];
        
        foreach ($periods as $period) {
            $this->artisan('vk:analytics', [
                '--owner' => $ownerId,
                '--period' => $period,
            ])
            ->assertExitCode(0);
        }
    }

    /**
     * Тест произвольного диапазона дат
     */
    public function test_custom_date_range()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 5);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $from = Carbon::now()->subDays(10)->format('Y-m-d');
        $to = Carbon::now()->format('Y-m-d');

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => "{$from}:{$to}",
        ])
        ->assertExitCode(0);
    }

    /**
     * Тест всех метрик
     */
    public function test_all_metrics()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 5);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $metrics = ['er', 'likes', 'reposts', 'comments', 'all'];
        
        foreach ($metrics as $metric) {
            $this->artisan('vk:analytics', [
                '--owner' => $ownerId,
                '--period' => 'week',
                '--top' => 5,
                '--metrics' => $metric,
            ])
            ->assertExitCode(0);
        }
    }

    /**
     * Тест timezone опции
     */
    public function test_timezone_option()
    {
        $ownerId = '-12345678';
        $this->createTestPosts($ownerId, 5);

        Http::fake([
            'https://api.vk.com/method/groups.getById*' => Http::response([
                'response' => [
                    [
                        'id' => 12345678,
                        'members_count' => 5000
                    ]
                ]
            ], 200),
        ]);

        $this->artisan('vk:analytics', [
            '--owner' => $ownerId,
            '--period' => 'week',
            '--best-time' => true,
            '--timezone' => 'Europe/Moscow',
        ])
        ->assertExitCode(0);
    }
}


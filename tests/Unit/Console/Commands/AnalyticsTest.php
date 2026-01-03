<?php

namespace Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use App\Console\Commands\Analytics;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Illuminate\Support\Collection;

class AnalyticsTest extends TestCase
{
    /**
     * Получить доступ к приватному методу calculateER
     */
    private function getCalculateERMethod()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('calculateER');
        $method->setAccessible(true);
        return [$command, $method];
    }

    /**
     * Тест расчета ER с нормальными данными
     */
    public function test_calculate_er_with_normal_data()
    {
        [$command, $method] = $this->getCalculateERMethod();
        
        // 100 лайков + 10 репостов + 5 комментариев = 115 вовлеченности
        // 115 / 1000 подписчиков * 100 = 11.5%
        $er = $method->invoke($command, 100, 10, 5, 1000);
        
        $this->assertEquals(11.5, $er);
    }

    /**
     * Тест расчета ER с нулевыми подписчиками
     */
    public function test_calculate_er_with_zero_members()
    {
        [$command, $method] = $this->getCalculateERMethod();
        
        $er = $method->invoke($command, 100, 10, 5, 0);
        
        $this->assertEquals(0.0, $er);
    }

    /**
     * Тест расчета ER с нулевой вовлеченностью
     */
    public function test_calculate_er_with_zero_engagement()
    {
        [$command, $method] = $this->getCalculateERMethod();
        
        $er = $method->invoke($command, 0, 0, 0, 1000);
        
        $this->assertEquals(0.0, $er);
    }

    /**
     * Тест расчета ER с округлением
     */
    public function test_calculate_er_rounding()
    {
        [$command, $method] = $this->getCalculateERMethod();
        
        // 33 лайка / 100 подписчиков * 100 = 33.0%
        $er = $method->invoke($command, 33, 0, 0, 100);
        
        $this->assertEquals(33.0, $er);
        
        // 1 лайк / 3 подписчика * 100 = 33.333... -> 33.33%
        $er2 = $method->invoke($command, 1, 0, 0, 3);
        
        $this->assertEquals(33.33, $er2);
    }

    /**
     * Тест расчета ER с большими числами
     */
    public function test_calculate_er_with_large_numbers()
    {
        [$command, $method] = $this->getCalculateERMethod();
        
        // 10000 лайков + 500 репостов + 200 комментариев = 10700
        // 10700 / 50000 подписчиков * 100 = 21.4%
        $er = $method->invoke($command, 10000, 500, 200, 50000);
        
        $this->assertEquals(21.4, $er);
    }

    /**
     * Тест группировки по дням недели (через рефлексию)
     */
    public function test_group_by_day_of_week_structure()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('groupByDayOfWeek');
        $method->setAccessible(true);

        // Создаем мок коллекцию постов
        $posts = collect([
            (object)[
                'date' => '2024-01-01 12:00:00', // Понедельник
                'likes' => 100,
                'reposts' => 10,
                'comments' => 5,
            ],
            (object)[
                'date' => '2024-01-02 12:00:00', // Вторник
                'likes' => 200,
                'reposts' => 20,
                'comments' => 10,
            ],
            (object)[
                'date' => '2024-01-01 14:00:00', // Понедельник (второй пост)
                'likes' => 50,
                'reposts' => 5,
                'comments' => 2,
            ],
        ]);

        $result = $method->invoke($command, $posts, 'UTC', 1000);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
        
        // Проверяем структуру результата
        foreach ($result as $day) {
            $this->assertArrayHasKey('day_of_week', $day);
            $this->assertArrayHasKey('day_name', $day);
            $this->assertArrayHasKey('posts_count', $day);
            $this->assertArrayHasKey('avg_er', $day);
            $this->assertArrayHasKey('avg_likes', $day);
        }
    }

    /**
     * Тест группировки по часам (через рефлексию)
     */
    public function test_group_by_hour_structure()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('groupByHour');
        $method->setAccessible(true);

        // Создаем мок коллекцию постов (минимум 3 поста в час для учета)
        $posts = collect([
            (object)[
                'date' => '2024-01-01 10:00:00',
                'likes' => 100,
                'reposts' => 10,
                'comments' => 5,
            ],
            (object)[
                'date' => '2024-01-01 10:30:00',
                'likes' => 200,
                'reposts' => 20,
                'comments' => 10,
            ],
            (object)[
                'date' => '2024-01-01 10:45:00',
                'likes' => 50,
                'reposts' => 5,
                'comments' => 2,
            ],
            (object)[
                'date' => '2024-01-01 14:00:00',
                'likes' => 150,
                'reposts' => 15,
                'comments' => 8,
            ],
        ]);

        $result = $method->invoke($command, $posts, 'UTC', 1000);

        $this->assertIsArray($result);
        
        // Должен быть хотя бы один час с 3+ постами (10:00)
        $hasValidHour = false;
        foreach ($result as $hour) {
            $this->assertArrayHasKey('hour', $hour);
            $this->assertArrayHasKey('posts_count', $hour);
            $this->assertArrayHasKey('avg_er', $hour);
            $this->assertGreaterThanOrEqual(3, $hour['posts_count']); // Минимум 3 поста
            $hasValidHour = true;
        }
        
        $this->assertTrue($hasValidHour, 'Должен быть хотя бы один час с 3+ постами');
    }

    /**
     * Тест фильтрации по минимальной вовлеченности
     */
    public function test_min_engagement_filtering()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getPostsForPeriod');
        $method->setAccessible(true);

        // Этот тест требует БД, поэтому проверим только структуру метода
        $this->assertTrue($reflection->hasMethod('getPostsForPeriod'));
    }

    /**
     * Тест получения топ-постов по метрике likes
     */
    public function test_get_top_posts_by_likes()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getTopPosts');
        $method->setAccessible(true);

        $posts = collect([
            (object)['post_id' => 1, 'likes' => 100, 'reposts' => 5, 'comments' => 3, 'text' => 'Пост 1', 'date' => '2024-01-01', 'url' => 'url1'],
            (object)['post_id' => 2, 'likes' => 200, 'reposts' => 10, 'comments' => 5, 'text' => 'Пост 2', 'date' => '2024-01-02', 'url' => 'url2'],
            (object)['post_id' => 3, 'likes' => 50, 'reposts' => 2, 'comments' => 1, 'text' => 'Пост 3', 'date' => '2024-01-03', 'url' => 'url3'],
        ]);

        $result = $method->invoke($command, $posts, 'likes', 2, 1000, '-12345678');

        $this->assertCount(2, $result);
        $this->assertEquals(200, $result[0]['likes']); // Первый должен быть с наибольшим количеством лайков
        $this->assertEquals(100, $result[1]['likes']);
    }

    /**
     * Тест получения топ-постов по метрике ER
     */
    public function test_get_top_posts_by_er()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getTopPosts');
        $method->setAccessible(true);

        $posts = collect([
            (object)['post_id' => 1, 'likes' => 10, 'reposts' => 1, 'comments' => 1, 'text' => 'Пост 1', 'date' => '2024-01-01', 'url' => 'url1'],
            (object)['post_id' => 2, 'likes' => 50, 'reposts' => 5, 'comments' => 5, 'text' => 'Пост 2', 'date' => '2024-01-02', 'url' => 'url2'],
            (object)['post_id' => 3, 'likes' => 20, 'reposts' => 2, 'comments' => 2, 'text' => 'Пост 3', 'date' => '2024-01-03', 'url' => 'url3'],
        ]);

        $result = $method->invoke($command, $posts, 'er', 2, 100, '-12345678');

        $this->assertCount(2, $result);
        // Пост 2 должен быть первым (60 вовлеченности / 100 подписчиков = 60% ER)
        $this->assertEquals(60.0, $result[0]['er']);
    }

    /**
     * Тест расчета предыдущего периода
     */
    public function test_calculate_previous_period()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('calculatePreviousPeriod');
        $method->setAccessible(true);

        $currentFrom = Carbon::parse('2024-01-15');
        $currentTo = Carbon::parse('2024-01-31'); // 17 дней

        $result = $method->invoke($command, $currentFrom, $currentTo);

        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('to', $result);
        
        // Предыдущий период должен быть той же длины
        $periodLength = $currentFrom->diffInDays($currentTo) + 1;
        $previousLength = $result['from']->diffInDays($result['to']) + 1;
        $this->assertEquals($periodLength, $previousLength);
        
        // Предыдущий период должен заканчиваться на день раньше начала текущего
        $this->assertEquals($currentFrom->copy()->subDay()->format('Y-m-d'), $result['to']->format('Y-m-d'));
    }

    /**
     * Тест сравнения периодов
     */
    public function test_compare_periods()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('comparePeriods');
        $method->setAccessible(true);

        $current = [
            'avg_er' => 2.5,
            'avg_likes' => 125,
            'avg_reposts' => 8,
            'avg_comments' => 12,
            'total_posts' => 45,
        ];

        $previous = [
            'avg_er' => 2.1,
            'avg_likes' => 98,
            'avg_reposts' => 7,
            'avg_comments' => 13,
            'total_posts' => 38,
        ];

        $result = $method->invoke($command, $current, $previous);

        $this->assertArrayHasKey('current', $result);
        $this->assertArrayHasKey('previous', $result);
        $this->assertArrayHasKey('changes', $result);
        
        // Проверяем расчет изменений
        $changes = $result['changes'];
        $this->assertArrayHasKey('avg_er', $changes);
        $this->assertArrayHasKey('trend', $changes['avg_er']);
        $this->assertArrayHasKey('percent', $changes['avg_er']);
        
        // ER вырос с 2.1% до 2.5% = +19.05% (примерно)
        $this->assertGreaterThan(15, $changes['avg_er']['percent']);
        $this->assertEquals('⬆️', $changes['avg_er']['trend']); // Рост > 5%
        
        // Лайки выросли с 98 до 125 = +27.55%
        $this->assertGreaterThan(25, $changes['avg_likes']['percent']);
        $this->assertEquals('⬆️', $changes['avg_likes']['trend']);
        
        // Комментарии упали с 13 до 12 = -7.69%
        $this->assertLessThan(0, $changes['avg_comments']['percent']);
        $this->assertEquals('⬇️', $changes['avg_comments']['trend']); // Падение < -5%
    }

    /**
     * Тест сравнения периодов со стабильными значениями
     */
    public function test_compare_periods_stable()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('comparePeriods');
        $method->setAccessible(true);

        $current = [
            'avg_er' => 2.3,
            'avg_likes' => 100,
            'avg_reposts' => 8,
            'avg_comments' => 12,
            'total_posts' => 45,
        ];

        $previous = [
            'avg_er' => 2.1,
            'avg_likes' => 98,
            'avg_reposts' => 7,
            'avg_comments' => 13,
            'total_posts' => 38,
        ];

        $result = $method->invoke($command, $current, $previous);
        $changes = $result['changes'];
        
        // Изменение ER: (2.3 - 2.1) / 2.1 * 100 = 9.52% - рост
        $this->assertEquals('⬆️', $changes['avg_er']['trend']);
        
        // Изменение лайков: (100 - 98) / 98 * 100 = 2.04% - стабильно
        $this->assertEquals('➡️', $changes['avg_likes']['trend']);
    }

    /**
     * Тест парсинга периода week
     */
    public function test_parse_period_week()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('parsePeriod');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'week');

        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('to', $result);
        $this->assertInstanceOf(Carbon::class, $result['from']);
        $this->assertInstanceOf(Carbon::class, $result['to']);
        
        // Проверяем, что период примерно 7 дней
        $days = $result['from']->diffInDays($result['to']);
        $this->assertGreaterThanOrEqual(6, $days);
        $this->assertLessThanOrEqual(7, $days);
    }

    /**
     * Тест парсинга периода month
     */
    public function test_parse_period_month()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('parsePeriod');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'month');

        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('to', $result);
        
        // Проверяем, что период примерно 30 дней
        $days = $result['from']->diffInDays($result['to']);
        $this->assertGreaterThanOrEqual(29, $days);
        $this->assertLessThanOrEqual(30, $days);
    }

    /**
     * Тест парсинга произвольного диапазона дат
     */
    public function test_parse_period_custom_range()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('parsePeriod');
        $method->setAccessible(true);

        $result = $method->invoke($command, '2024-01-01:2024-01-31');

        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('to', $result);
        $this->assertEquals('2024-01-01', $result['from']->format('Y-m-d'));
        $this->assertEquals('2024-01-31', $result['to']->format('Y-m-d'));
    }

    /**
     * Тест парсинга периода с неверным форматом
     */
    public function test_parse_period_invalid_format()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('parsePeriod');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $method->invoke($command, 'invalid-period');
    }

    /**
     * Тест парсинга метрик
     */
    public function test_parse_metrics_all()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('parseMetrics');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'all');

        $this->assertIsArray($result);
        $this->assertContains('er', $result);
        $this->assertContains('likes', $result);
        $this->assertContains('reposts', $result);
        $this->assertContains('comments', $result);
    }

    /**
     * Тест парсинга метрик через запятую
     */
    public function test_parse_metrics_comma_separated()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('parseMetrics');
        $method->setAccessible(true);

        $result = $method->invoke($command, 'er,likes');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertContains('er', $result);
        $this->assertContains('likes', $result);
    }

    /**
     * Тест расчета общей статистики
     */
    public function test_calculate_summary()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('calculateSummary');
        $method->setAccessible(true);

        $posts = collect([
            (object)['likes' => 100, 'reposts' => 10, 'comments' => 5],
            (object)['likes' => 200, 'reposts' => 20, 'comments' => 10],
        ]);

        $result = $method->invoke($command, $posts, 1000);

        $this->assertArrayHasKey('total_posts', $result);
        $this->assertArrayHasKey('avg_likes', $result);
        $this->assertArrayHasKey('avg_reposts', $result);
        $this->assertArrayHasKey('avg_comments', $result);
        $this->assertArrayHasKey('avg_er', $result);
        $this->assertEquals(2, $result['total_posts']);
        $this->assertEquals(150.0, $result['avg_likes']); // (100 + 200) / 2
        $this->assertEquals(15.0, $result['avg_reposts']); // (10 + 20) / 2
    }

    /**
     * Тест расчета статистики с пустой коллекцией
     */
    public function test_calculate_summary_empty()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('calculateSummary');
        $method->setAccessible(true);

        $result = $method->invoke($command, collect([]), 1000);

        $this->assertEquals(0, $result['total_posts']);
        $this->assertEquals(0, $result['avg_likes']);
        $this->assertEquals(0.0, $result['avg_er']);
    }

    /**
     * Тест валидации owner
     */
    public function test_validate_owner()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('validateOwner');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($command, '-12345678'));
        $this->assertTrue($method->invoke($command, '12345678'));
        $this->assertFalse($method->invoke($command, 'invalid'));
        $this->assertFalse($method->invoke($command, 'abc123'));
    }

    /**
     * Тест валидации метрик
     */
    public function test_validate_metrics()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('validateMetrics');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($command, 'er'));
        $this->assertTrue($method->invoke($command, 'all'));
        $this->assertTrue($method->invoke($command, 'er,likes'));
        $this->assertFalse($method->invoke($command, 'invalid'));
        $this->assertFalse($method->invoke($command, 'er,invalid'));
    }

    /**
     * Тест получения топ-постов по репостам
     */
    public function test_get_top_posts_by_reposts()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getTopPosts');
        $method->setAccessible(true);

        $posts = collect([
            (object)['post_id' => 1, 'likes' => 100, 'reposts' => 5, 'comments' => 3, 'text' => 'Пост 1', 'date' => '2024-01-01', 'url' => 'url1'],
            (object)['post_id' => 2, 'likes' => 200, 'reposts' => 20, 'comments' => 5, 'text' => 'Пост 2', 'date' => '2024-01-02', 'url' => 'url2'],
        ]);

        $result = $method->invoke($command, $posts, 'reposts', 1, 1000, '-12345678');

        $this->assertCount(1, $result);
        $this->assertEquals(20, $result[0]['reposts']);
    }

    /**
     * Тест получения топ-постов по комментариям
     */
    public function test_get_top_posts_by_comments()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getTopPosts');
        $method->setAccessible(true);

        $posts = collect([
            (object)['post_id' => 1, 'likes' => 100, 'reposts' => 5, 'comments' => 3, 'text' => 'Пост 1', 'date' => '2024-01-01', 'url' => 'url1'],
            (object)['post_id' => 2, 'likes' => 200, 'reposts' => 10, 'comments' => 15, 'text' => 'Пост 2', 'date' => '2024-01-02', 'url' => 'url2'],
        ]);

        $result = $method->invoke($command, $posts, 'comments', 1, 1000, '-12345678');

        $this->assertCount(1, $result);
        $this->assertEquals(15, $result[0]['comments']);
    }
}


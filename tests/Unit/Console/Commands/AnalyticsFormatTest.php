<?php

namespace Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use App\Console\Commands\Analytics;
use ReflectionClass;
use Illuminate\Support\Carbon;

class AnalyticsFormatTest extends TestCase
{
    /**
     * Тест форматирования JSON
     */
    public function test_format_json()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('formatJson');
        $method->setAccessible(true);

        $data = [
            'owner_id' => '-12345678',
            'period' => ['from' => '2024-01-01', 'to' => '2024-01-31'],
            'members_count' => 5000,
            'summary' => [
                'total_posts' => 45,
                'avg_likes' => 125.5,
                'avg_reposts' => 8.2,
                'avg_comments' => 12.3,
                'avg_er' => 2.5,
                'total_engagement' => 6565,
            ],
            'er_by_day' => [
                [
                    'day_of_week' => 1,
                    'day_name' => 'Понедельник',
                    'posts_count' => 10,
                    'avg_er' => 2.8,
                ],
            ],
            'best_time' => [],
            'top_posts' => [],
        ];

        $result = $method->invoke($command, $data);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertEquals('-12345678', $decoded['owner_id']);
        $this->assertEquals(45, $decoded['summary']['total_posts']);
        $this->assertArrayHasKey('er_by_day', $decoded);
    }

    /**
     * Тест форматирования CSV summary для stdout
     */
    public function test_format_csv_summary()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('formatCsvSummary');
        $method->setAccessible(true);

        $summary = [
            'total_posts' => 45,
            'avg_likes' => 125.5,
            'avg_reposts' => 8.2,
            'avg_comments' => 12.3,
            'avg_er' => 2.5,
            'total_engagement' => 6565,
        ];

        $result = $method->invoke($command, $summary);

        $this->assertIsString($result);
        $this->assertStringContainsString('Метрика', $result);
        $this->assertStringContainsString('Всего постов', $result);
        $this->assertStringContainsString('45', $result);
    }

    /**
     * Тест записи CSV файла
     */
    public function test_write_csv_file()
    {
        $command = new Analytics();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('writeCsvFile');
        $method->setAccessible(true);

        $testFile = sys_get_temp_dir() . '/test_analytics_' . uniqid() . '.csv';
        $rows = [
            ['Метрика', 'Значение'],
            ['Всего постов', 45],
            ['Средний ER', 2.5],
        ];

        $method->invoke($command, $testFile, $rows);

        $this->assertFileExists($testFile);
        $content = file_get_contents($testFile);
        $this->assertStringContainsString('Метрика', $content);
        $this->assertStringContainsString('45', $content);

        // Очистка
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

}


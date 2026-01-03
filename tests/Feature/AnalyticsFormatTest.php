<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Console\Commands\Analytics;
use ReflectionClass;
use Illuminate\Support\Facades\File;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class AnalyticsFormatTest extends TestCase
{
    /**
     * Создать команду с мокированным output
     */
    private function createCommandWithOutput(): Analytics
    {
        $command = new Analytics();
        $output = new BufferedOutput();
        $outputStyle = new OutputStyle(new ArrayInput([]), $output);
        $command->setOutput($outputStyle);
        return $command;
    }

    /**
     * Тест сохранения в файл
     */
    public function test_save_to_file()
    {
        $command = $this->createCommandWithOutput();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('saveToFile');
        $method->setAccessible(true);

        $testFile = storage_path('app/test_save_' . uniqid() . '.txt');
        $content = 'Test content';

        $method->invoke($command, $content, $testFile, 'txt');

        $this->assertFileExists($testFile);
        $this->assertEquals($content, file_get_contents($testFile));

        // Очистка
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    /**
     * Тест форматирования CSV с несколькими файлами
     */
    public function test_format_csv_multiple_files()
    {
        $command = $this->createCommandWithOutput();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('formatCsv');
        $method->setAccessible(true);

        $testDir = storage_path('app/test_csv_' . uniqid());
        $testFile = $testDir . '/analytics.csv';

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
                    'avg_likes' => 150,
                    'avg_reposts' => 10,
                    'avg_comments' => 15,
                ],
            ],
            'best_time' => [
                [
                    'hour' => 14,
                    'posts_count' => 5,
                    'avg_er' => 3.2,
                    'avg_likes' => 200,
                    'avg_reposts' => 12,
                    'avg_comments' => 18,
                ],
            ],
            'top_posts' => [
                'likes' => [
                    [
                        'post_id' => 1001,
                        'date' => '2024-01-15 12:00:00',
                        'text' => 'Test post',
                        'likes' => 500,
                        'reposts' => 20,
                        'comments' => 10,
                        'er' => 10.6,
                        'url' => 'https://vk.com/wall-12345678_1001',
                    ],
                ],
            ],
            'comparison' => null,
        ];

        $method->invoke($command, $data, $testFile);

        // Проверяем, что созданы файлы
        $this->assertFileExists($testDir . '/analytics_summary.csv');
        $this->assertFileExists($testDir . '/analytics_by_day.csv');
        $this->assertFileExists($testDir . '/analytics_best_time.csv');
        $this->assertFileExists($testDir . '/analytics_top_posts_likes.csv');

        // Проверяем содержимое summary
        $summaryContent = file_get_contents($testDir . '/analytics_summary.csv');
        $this->assertStringContainsString('Всего постов', $summaryContent);
        $this->assertStringContainsString('45', $summaryContent);

        // Очистка
        if (File::isDirectory($testDir)) {
            File::deleteDirectory($testDir);
        }
    }

    /**
     * Тест форматирования CSV с сравнением периодов
     */
    public function test_format_csv_with_comparison()
    {
        $command = $this->createCommandWithOutput();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('formatCsv');
        $method->setAccessible(true);

        $testDir = storage_path('app/test_csv_comp_' . uniqid());
        $testFile = $testDir . '/analytics.csv';

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
            'er_by_day' => [],
            'best_time' => [],
            'top_posts' => [],
            'comparison' => [
                'current' => [
                    'avg_er' => 2.5,
                    'avg_likes' => 125.5,
                    'avg_reposts' => 8.2,
                    'avg_comments' => 12.3,
                    'total_posts' => 45,
                ],
                'previous' => [
                    'avg_er' => 2.1,
                    'avg_likes' => 98.0,
                    'avg_reposts' => 7.0,
                    'avg_comments' => 13.0,
                    'total_posts' => 38,
                ],
                'changes' => [
                    'avg_er' => ['percent' => 19.05, 'trend' => '⬆️'],
                    'avg_likes' => ['percent' => 28.06, 'trend' => '⬆️'],
                    'avg_reposts' => ['percent' => 17.14, 'trend' => '⬆️'],
                    'avg_comments' => ['percent' => -5.38, 'trend' => '⬇️'],
                    'total_posts' => ['percent' => 18.42, 'trend' => '⬆️'],
                ],
                'periods' => [
                    'current' => ['from' => '2024-01-01', 'to' => '2024-01-31'],
                    'previous' => ['from' => '2023-12-01', 'to' => '2023-12-31'],
                ],
            ],
        ];

        $method->invoke($command, $data, $testFile);

        // Проверяем, что создан файл сравнения
        $this->assertFileExists($testDir . '/analytics_comparison.csv');

        $comparisonContent = file_get_contents($testDir . '/analytics_comparison.csv');
        $this->assertStringContainsString('Метрика', $comparisonContent);
        $this->assertStringContainsString('Средний ER', $comparisonContent);

        // Очистка
        if (File::isDirectory($testDir)) {
            File::deleteDirectory($testDir);
        }
    }
}


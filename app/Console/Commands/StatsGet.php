<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use VK\Client\VKApiClient;
use VK\Enums\StatsInterval;

class StatsGet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:stats-get
                            {--group-id= : ID группы (обязательный, положительное число)}
                            {--from= : Дата начала периода (обязательный, формат: YYYY-MM-DD)}
                            {--to= : Дата окончания периода (опциональный, по умолчанию текущая дата, формат: YYYY-MM-DD)}
                            {--interval=day : Интервал группировки: day, week, month, year, all}
                            {--format=table : Формат вывода: table, json, csv, markdown}
                            {--output= : Путь к файлу для сохранения результатов (опциональный)}
                            {--extended : Расширенная статистика}
                            {--verbose-raw : Показать сырой ответ VK API в JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение статистики сообщества через VK API stats.get';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Валидация обязательных параметров
        if (!$this->option('group-id')) {
            $this->error('Параметр --group-id обязателен');
            return 1;
        }

        if (!$this->option('from')) {
            $this->error('Параметр --from обязателен');
            return 1;
        }

        $groupId = (int)$this->option('group-id');
        if ($groupId <= 0) {
            $this->error('group-id должен быть положительным числом');
            return 1;
        }

        // Парсинг дат
        try {
            $fromTimestamp = $this->parseDate($this->option('from'), false);
            $toTimestamp = $this->option('to') 
                ? $this->parseDate($this->option('to'), true)
                : time();
        } catch (\Exception $e) {
            $this->error('Ошибка парсинга даты: ' . $e->getMessage());
            return 1;
        }

        if ($fromTimestamp > $toTimestamp) {
            $this->error('Дата начала периода не может быть больше даты окончания');
            return 1;
        }

        // Валидация interval
        $interval = $this->option('interval');
        $intervalMap = [
            'day' => StatsInterval::DAY,
            'week' => StatsInterval::WEEK,
            'month' => StatsInterval::MONTH,
            'year' => StatsInterval::YEAR,
            'all' => StatsInterval::ALL,
        ];
        
        if (!isset($intervalMap[$interval])) {
            $this->error('Неверный интервал. Допустимые значения: day, week, month, year, all');
            return 1;
        }
        
        $statsInterval = $intervalMap[$interval];

        // Валидация формата
        $format = $this->option('format');
        if (!in_array($format, ['table', 'json', 'csv', 'markdown'])) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv, markdown');
            return 1;
        }

        $token = config('vk.token');
        if (empty($token)) {
            $this->error('Токен VK API не настроен. Укажите VK_TOKEN в .env');
            return 1;
        }

        try {
            $this->info("Получение статистики для группы: {$groupId}");
            $this->info("Период: " . date('Y-m-d', $fromTimestamp) . " - " . date('Y-m-d', $toTimestamp));

            // Создаем клиент VK API
            $vk = new VKApiClient(config('vk.version', '5.131'));

            // Параметры запроса
            $params = [
                'group_id' => $groupId,
                'timestamp_from' => $fromTimestamp,
                'timestamp_to' => $toTimestamp,
                'interval' => $statsInterval,
            ];

            if ($this->option('extended')) {
                $params['extended'] = true;
            }

            // Получаем статистику
            $this->info('Запрос к VK API...');
            $response = $vk->stats()->get($token, $params);

            if (empty($response)) {
                $this->warn('Статистика не найдена для указанного периода');
                return 0;
            }

            // VK API иногда возвращает интервалы за пределами запрошенного диапазона
            // (особенно для interval=month/year). Жестко ограничиваем результат рамками --from/--to.
            $originalCount = count($response);
            $response = $this->filterStatsByDateRange($response, $fromTimestamp, $toTimestamp);
            if ($originalCount !== count($response)) {
                $this->warn("VK API вернул {$originalCount} периодов, отфильтровано до " . count($response) . " по диапазону дат.");
            }

            if ($this->option('verbose-raw')) {
                $this->newLine();
                $this->info('=== Сырой ответ VK API (stats.get) ===');
                $this->line($this->formatJson($response));
                $this->newLine();
            }

            // Форматируем и выводим результаты
            $outputPath = $this->option('output');
            
            if ($outputPath) {
                $saveFormat = $format;
                $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));

                if ($extension === 'json') {
                    $saveFormat = 'json';
                } elseif ($extension === 'csv') {
                    $saveFormat = 'csv';
                } elseif (in_array($extension, ['md', 'markdown'])) {
                    $saveFormat = 'markdown';
                }

                $output = $this->formatOutputForFile($response, $saveFormat);
                $this->saveToFile($output, $outputPath, $saveFormat);
            } else {
                $output = $this->formatOutput($response, $format);
                if ($format === 'table') {
                    $this->line($output);
                } else {
                    $this->line($output);
                }
            }

            return 0;
        } catch (\VK\Exceptions\VKApiException $e) {
            $this->error('Ошибка VK API: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error('Код ошибки: ' . $e->getCode());
            }
            return 1;
        } catch (\VK\Exceptions\VKClientException $e) {
            $this->error('Ошибка клиента VK: ' . $e->getMessage());
            return 1;
        } catch (\Exception $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Парсинг даты из различных форматов
     *
     * @param string $dateString
     * @return int Unix timestamp
     * @throws \Exception
     */
    private function parseDate(string $dateString, bool $endOfDay = false): int
    {
        $dateString = trim($dateString);

        // Попытка парсинга формата YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            $date = Carbon::createFromFormat('Y-m-d', $dateString);
            return $endOfDay ? $date->endOfDay()->timestamp : $date->startOfDay()->timestamp;
        }

        throw new \Exception("Неверный формат даты: {$dateString}. Используйте формат YYYY-MM-DD");
    }

    /**
     * Форматирование вывода в зависимости от формата
     *
     * @param array $stats
     * @param string $format
     * @return string
     */
    private function formatOutput(array $stats, string $format): string
    {
        switch ($format) {
            case 'json':
                return $this->formatJson($stats);
            case 'csv':
                return $this->formatCsv($stats);
            case 'markdown':
                return $this->formatMarkdown($stats);
            case 'table':
            default:
                return $this->formatTable($stats);
        }
    }

    /**
     * Форматирование вывода для сохранения в файл.
     * Для table-формата в файл сохраняется текстовый отчет.
     */
    private function formatOutputForFile(array $stats, string $format): string
    {
        if ($format === 'table') {
            return $this->formatText($stats);
        }

        return $this->formatOutput($stats, $format);
    }

    /**
     * Форматирование в таблицу
     *
     * @param array $stats
     * @return string
     */
    private function formatTable(array $stats): string
    {
        if (empty($stats)) {
            $this->warn('Нет данных для отображения');
            return '';
        }

        // Выводим общую информацию
        $this->info('=== Статистика сообщества ===');
        $this->newLine();

        foreach ($stats as $period) {
            $periodFrom = isset($period['period_from']) ? date('Y-m-d H:i', $period['period_from']) : 'N/A';
            $periodTo = isset($period['period_to']) ? date('Y-m-d H:i', $period['period_to']) : 'N/A';
            
            $this->line("Период: {$periodFrom} - {$periodTo}");
            $this->newLine();

            // Активность
            if (isset($period['activity'])) {
                $activity = $period['activity'];
                $this->line('Активность:');
                $activityData = [
                    ['Лайки', $activity['likes'] ?? 0],
                    ['Комментарии', $activity['comments'] ?? 0],
                    ['Репосты', $activity['copies'] ?? 0],
                    ['Скрыли', $activity['hidden'] ?? 0],
                    ['Подписались', $activity['subscribed'] ?? 0],
                    ['Отписались', $activity['unsubscribed'] ?? 0],
                ];
                $this->table(['Метрика', 'Значение'], $activityData);
                $this->newLine();
            }

            // Посетители
            if (isset($period['visitors'])) {
                $visitors = $period['visitors'];
                $this->line('Посетители:');
                $visitorsData = [
                    ['Просмотры', $visitors['views'] ?? 0],
                    ['Посетители', $visitors['visitors'] ?? 0],
                ];
                $this->table(['Метрика', 'Значение'], $visitorsData);
                $this->newLine();
            }

            // Охват
            if (isset($period['reach'])) {
                $reach = $period['reach'];
                $this->line('Охват:');
                $reachData = [
                    ['Полный охват', $reach['reach'] ?? 0],
                    ['Охват подписчиков', $reach['reach_subscribers'] ?? 0],
                    ['Мобильный охват', $reach['mobile_reach'] ?? 0],
                ];
                $this->table(['Метрика', 'Значение'], $reachData);
                $this->newLine();
            }

            // Демография (если расширенная статистика)
            if (isset($period['reach']['sex']) || isset($period['reach']['age']) || isset($period['reach']['cities'])) {
                $this->line('Демография:');
                
                // По полу
                if (isset($period['reach']['sex'])) {
                    $sexData = [];
                    foreach ($period['reach']['sex'] as $sex) {
                        $sexLabel = $sex['value'] === 'm' ? 'Мужской' : ($sex['value'] === 'f' ? 'Женский' : $sex['value']);
                        $sexData[] = [$sexLabel, $sex['count'] ?? 0];
                    }
                    if (!empty($sexData)) {
                        $this->line('По полу:');
                        $this->table(['Пол', 'Количество'], $sexData);
                        $this->newLine();
                    }
                }

                // По возрасту
                if (isset($period['reach']['age'])) {
                    $ageData = [];
                    foreach ($period['reach']['age'] as $age) {
                        $ageData[] = [$age['value'] ?? 'N/A', $age['count'] ?? 0];
                    }
                    if (!empty($ageData)) {
                        $this->line('По возрасту:');
                        $this->table(['Возраст', 'Количество'], $ageData);
                        $this->newLine();
                    }
                }

                // По городам (топ-10)
                if (isset($period['reach']['cities'])) {
                    $citiesData = [];
                    $cities = array_slice($period['reach']['cities'], 0, 10);
                    foreach ($cities as $city) {
                        $citiesData[] = [$city['name'] ?? 'N/A', $city['count'] ?? 0];
                    }
                    if (!empty($citiesData)) {
                        $this->line('По городам (топ-10):');
                        $this->table(['Город', 'Количество'], $citiesData);
                        $this->newLine();
                    }
                }
            }

            $this->line('---');
            $this->newLine();
        }

        return ''; // Таблица уже выведена через $this->table()
    }

    /**
     * Форматирование в JSON
     *
     * @param array $stats
     * @return string
     */
    private function formatJson(array $stats): string
    {
        return json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Форматирование в CSV
     *
     * @param array $stats
     * @return string
     */
    private function formatCsv(array $stats): string
    {
        if (empty($stats)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        
        // Заголовок
        fputcsv($output, [
            'Период от',
            'Период до',
            'Лайки',
            'Комментарии',
            'Репосты',
            'Скрыли',
            'Подписались',
            'Отписались',
            'Просмотры',
            'Посетители',
            'Полный охват',
            'Охват подписчиков',
            'Мобильный охват',
        ]);

        // Данные
        foreach ($stats as $period) {
            $activity = $period['activity'] ?? [];
            $visitors = $period['visitors'] ?? [];
            $reach = $period['reach'] ?? [];

            fputcsv($output, [
                isset($period['period_from']) ? date('Y-m-d H:i:s', $period['period_from']) : '',
                isset($period['period_to']) ? date('Y-m-d H:i:s', $period['period_to']) : '',
                $activity['likes'] ?? 0,
                $activity['comments'] ?? 0,
                $activity['copies'] ?? 0,
                $activity['hidden'] ?? 0,
                $activity['subscribed'] ?? 0,
                $activity['unsubscribed'] ?? 0,
                $visitors['views'] ?? 0,
                $visitors['visitors'] ?? 0,
                $reach['reach'] ?? 0,
                $reach['reach_subscribers'] ?? 0,
                $reach['mobile_reach'] ?? 0,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Форматирование в Markdown
     */
    private function formatMarkdown(array $stats): string
    {
        if (empty($stats)) {
            return "# Статистика сообщества\n\nНет данных для отображения.\n";
        }

        $output = "# Статистика сообщества\n\n";

        foreach ($stats as $period) {
            $periodFrom = isset($period['period_from']) ? date('Y-m-d H:i', $period['period_from']) : 'N/A';
            $periodTo = isset($period['period_to']) ? date('Y-m-d H:i', $period['period_to']) : 'N/A';

            $output .= "## Период: {$periodFrom} - {$periodTo}\n\n";

            if (isset($period['visitors'])) {
                $visitors = $period['visitors'];
                $output .= "### Посетители\n\n";
                $output .= "| Метрика | Значение |\n";
                $output .= "|---|---:|\n";
                $output .= "| Просмотры | " . ($visitors['views'] ?? 0) . " |\n";
                $output .= "| Посетители | " . ($visitors['visitors'] ?? 0) . " |\n\n";
            }

            if (isset($period['reach'])) {
                $reach = $period['reach'];
                $output .= "### Охват\n\n";
                $output .= "| Метрика | Значение |\n";
                $output .= "|---|---:|\n";
                $output .= "| Полный охват | " . ($reach['reach'] ?? 0) . " |\n";
                $output .= "| Охват подписчиков | " . ($reach['reach_subscribers'] ?? 0) . " |\n";
                $output .= "| Мобильный охват | " . ($reach['mobile_reach'] ?? 0) . " |\n\n";
            }

            if (isset($period['activity'])) {
                $activity = $period['activity'];
                $output .= "### Активность\n\n";
                $output .= "| Метрика | Значение |\n";
                $output .= "|---|---:|\n";
                $output .= "| Лайки | " . ($activity['likes'] ?? 0) . " |\n";
                $output .= "| Комментарии | " . ($activity['comments'] ?? 0) . " |\n";
                $output .= "| Репосты | " . ($activity['copies'] ?? 0) . " |\n";
                $output .= "| Подписались | " . ($activity['subscribed'] ?? 0) . " |\n";
                $output .= "| Отписались | " . ($activity['unsubscribed'] ?? 0) . " |\n\n";
            }
        }

        return $output;
    }

    /**
     * Форматирование в простой текст
     */
    private function formatText(array $stats): string
    {
        if (empty($stats)) {
            return "Статистика сообщества\n\nНет данных для отображения.\n";
        }

        $output = "Статистика сообщества\n";
        $output .= str_repeat('=', 24) . "\n\n";

        foreach ($stats as $period) {
            $periodFrom = isset($period['period_from']) ? date('Y-m-d H:i', $period['period_from']) : 'N/A';
            $periodTo = isset($period['period_to']) ? date('Y-m-d H:i', $period['period_to']) : 'N/A';
            $output .= "Период: {$periodFrom} - {$periodTo}\n";

            if (isset($period['visitors'])) {
                $visitors = $period['visitors'];
                $output .= "  Посетители: views=" . ($visitors['views'] ?? 0) . ", visitors=" . ($visitors['visitors'] ?? 0) . "\n";
            }

            if (isset($period['reach'])) {
                $reach = $period['reach'];
                $output .= "  Охват: reach=" . ($reach['reach'] ?? 0) . ", reach_subscribers=" . ($reach['reach_subscribers'] ?? 0) . ", mobile_reach=" . ($reach['mobile_reach'] ?? 0) . "\n";
            }

            if (isset($period['activity'])) {
                $activity = $period['activity'];
                $output .= "  Активность: likes=" . ($activity['likes'] ?? 0)
                    . ", comments=" . ($activity['comments'] ?? 0)
                    . ", copies=" . ($activity['copies'] ?? 0)
                    . ", subscribed=" . ($activity['subscribed'] ?? 0)
                    . ", unsubscribed=" . ($activity['unsubscribed'] ?? 0) . "\n";
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * Сохранение в файл
     *
     * @param string $content
     * @param string $path
     * @param string $format
     * @return void
     */
    private function saveToFile(string $content, string $path, string $format): void
    {
        // Если путь относительный, создаем от base_path
        $finalPath = $path;
        if (strpos($finalPath, '/') !== 0 && strpos($finalPath, 'storage/') !== 0) {
            $finalPath = base_path($finalPath);
        } elseif (strpos($finalPath, '/') !== 0) {
            $finalPath = storage_path('app/' . $finalPath);
        }

        // Создаем директории, если их нет
        $directory = dirname($finalPath);
        if (!is_dir($directory)) {
            if (!File::makeDirectory($directory, 0755, true)) {
                $this->error("Не удалось создать директорию: {$directory}");
                return;
            }
        }

        $bytesWritten = file_put_contents($finalPath, $content);
        if ($bytesWritten === false) {
            $this->error("Ошибка при сохранении файла: {$finalPath}");
            return;
        }

        $this->info("Результаты сохранены в файл: {$finalPath} ({$bytesWritten} байт)");
    }

    /**
     * Фильтрация ответа stats.get по запрошенному диапазону дат.
     *
     * @param array $stats
     * @param int $fromTimestamp
     * @param int $toTimestamp
     * @return array
     */
    private function filterStatsByDateRange(array $stats, int $fromTimestamp, int $toTimestamp): array
    {
        return array_values(array_filter($stats, function ($period) use ($fromTimestamp, $toTimestamp) {
            if (!isset($period['period_from'], $period['period_to'])) {
                return false;
            }

            $periodFrom = (int) $period['period_from'];
            $periodTo = (int) $period['period_to'];

            // Оставляем периоды, которые пересекаются с диапазоном [from, to]
            return $periodFrom <= $toTimestamp && $periodTo >= $fromTimestamp;
        }));
    }
}


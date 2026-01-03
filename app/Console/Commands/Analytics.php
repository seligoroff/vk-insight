<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use App\Services\VkApi\VkGroupService;
use App\Services\VkApi\VkUrlBuilder;

class Analytics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:analytics 
                            {--owner= : ID владельца стены (обязательный, отрицательное число для групп)}
                            {--period=month : Период анализа: week, month, quarter, year или диапазон YYYY-MM-DD:YYYY-MM-DD}
                            {--metrics=all : Метрики для отображения: er, likes, reposts, comments, all}
                            {--compare=none : Сравнить с предыдущим периодом: previous, none}
                            {--best-time : Показать лучшее время публикации}
                            {--top=10 : Количество топ-постов для отображения}
                            {--min-engagement=0 : Минимальная вовлеченность для учета поста}
                            {--timezone= : Таймзона для анализа времени публикации (по умолчанию из config/app.php)}
                            {--format=table : Формат вывода: table, json, csv}
                            {--output= : Путь к файлу для сохранения результатов (опциональный)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Углубленный анализ эффективности постов группы/пользователя VK';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Валидация обязательных параметров
        if (!$this->option('owner')) {
            $this->error('Параметр --owner обязателен');
            return 1;
        }

        // Валидация owner_id
        if (!$this->validateOwner($this->option('owner'))) {
            $this->error('Неверный формат --owner. Используйте отрицательное число для групп (например, -12345678)');
            return 1;
        }

        // Валидация метрик
        if (!$this->validateMetrics($this->option('metrics'))) {
            $this->error('Неверный формат --metrics. Допустимые значения: er, likes, reposts, comments, all');
            return 1;
        }

        // Валидация формата
        $format = $this->option('format');
        if (!in_array($format, ['table', 'json', 'csv'])) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv');
            return 1;
        }

        // Парсинг периода
        try {
            $period = $this->parsePeriod($this->option('period'));
        } catch (\Exception $e) {
            $this->error('Ошибка парсинга периода: ' . $e->getMessage());
            return 1;
        }

        $ownerId = $this->option('owner');
        // Приоритет: опция команды > конфиг VK > конфиг приложения > UTC
        $timezone = $this->option('timezone') 
            ?: config('vk.analytics_timezone') 
            ?: config('app.timezone', 'UTC');
        $minEngagement = (int)$this->option('min-engagement');

        // Проверка наличия таблицы vk_posts
        if (!Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не найдена в базе данных.');
            $this->info('Выполните миграции: php artisan migrate');
            return 1;
        }

        $this->info("Аналитика для владельца: {$ownerId}");
        $this->info("Период: {$period['from']->format('Y-m-d')} - {$period['to']->format('Y-m-d')}");

        // Получение постов из БД
        try {
            $posts = $this->getPostsForPeriod($ownerId, $period['from'], $period['to'], $minEngagement);
        } catch (\Exception $e) {
            $this->error('Ошибка при получении постов из базы данных: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
        
        if ($posts->isEmpty()) {
            $this->warn('Посты за указанный период не найдены в базе данных.');
            $this->info('Используйте команду: php artisan vk:posts-get --owner=' . $ownerId . ' --from=' . $period['from']->format('Y-m-d') . ' --db');
            return 0;
        }

        $this->info("Найдено постов: " . $posts->count());

        // Progress bar для больших периодов
        $showProgress = in_array($this->option('period'), ['year', 'quarter']) || $posts->count() > 1000;
        $progressBar = null;
        if ($showProgress && $format === 'table') {
            $progressBar = $this->output->createProgressBar($posts->count());
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
            $progressBar->setMessage('Обработка постов...');
            $progressBar->start();
        }

        // Получение информации о группе/пользователе с кешированием
        $groupId = abs((int)$ownerId); // Убираем минус для получения ID группы
        $groupInfo = null;
        try {
            $groupInfo = $this->getGroupInfo($groupId);
        } catch (\Exception $e) {
            $this->warn('Не удалось получить информацию о группе: ' . $e->getMessage());
        }
        
        $membersCount = $groupInfo['members_count'] ?? 0;
        $ownerName = $groupInfo['name'] ?? $groupInfo['screen_name'] ?? null;
        
        if ($membersCount === 0) {
            $this->warn('Не удалось получить количество подписчиков. ER будет рассчитан без учета подписчиков.');
        } else {
            if ($format === 'table') {
                $this->info("Подписчиков: " . number_format($membersCount, 0, ',', ' '));
            }
        }

        // Расчет общей статистики
        $summary = $this->calculateSummary($posts, $membersCount);
        
        // Группировка по дням недели
        $erByDay = $this->groupByDayOfWeek($posts, $timezone, $membersCount);
        
        // Группировка по часам (если нужно)
        $bestTime = [];
        if ($this->option('best-time')) {
            // Предупреждение, если используется UTC по умолчанию (может быть неверно для российских групп)
            if ($timezone === 'UTC' && $format === 'table' && !$this->option('timezone')) {
                $this->warn('Используется UTC для анализа времени. Для российских групп рекомендуется указать VK_ANALYTICS_TIMEZONE=Europe/Moscow в .env или --timezone=Europe/Moscow');
            }
            $bestTime = $this->groupByHour($posts, $timezone, $membersCount);
        }

        // Топ-посты по метрикам
        $topPostsData = [];
        $topLimit = (int)$this->option('top');
        if ($topLimit > 0) {
            $metrics = $this->parseMetrics($this->option('metrics'));
            foreach ($metrics as $metric) {
                $topPosts = $this->getTopPosts($posts, $metric, $topLimit, $membersCount, $ownerId);
                if (!empty($topPosts)) {
                    $topPostsData[$metric] = $topPosts;
                }
            }
        }

        // Сравнение с предыдущим периодом
        $comparison = null;
        if ($this->option('compare') === 'previous') {
            $previousPeriod = $this->calculatePreviousPeriod($period['from'], $period['to']);
            $previousPosts = $this->getPostsForPeriod($ownerId, $previousPeriod['from'], $previousPeriod['to'], $minEngagement);
            
            if (!$previousPosts->isEmpty()) {
                $previousSummary = $this->calculateSummary($previousPosts, $membersCount);
                $comparison = $this->comparePeriods($summary, $previousSummary);
                $comparison['periods'] = [
                    'current' => [
                        'from' => $period['from']->toDateString(),
                        'to' => $period['to']->toDateString(),
                    ],
                    'previous' => [
                        'from' => $previousPeriod['from']->toDateString(),
                        'to' => $previousPeriod['to']->toDateString(),
                    ],
                ];
            } else {
                if ($format === 'table') {
                    $this->warn('Нет данных для предыдущего периода для сравнения.');
                }
            }
        }

        if ($progressBar) {
            $progressBar->setMessage('Форматирование данных...');
            $progressBar->finish();
            $this->newLine();
        }

        // Сбор всех данных в структурированный массив
        $data = [
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'period' => [
                'from' => $period['from']->toDateString(),
                'to' => $period['to']->toDateString(),
            ],
            'members_count' => $membersCount,
            'summary' => $summary,
            'er_by_day' => $erByDay,
            'best_time' => $bestTime,
            'top_posts' => $topPostsData,
            'comparison' => $comparison,
        ];

        // Вывод в зависимости от формата
        $outputPath = $this->option('output');
        
        // Если указан --output, определяем формат по расширению файла
        if ($outputPath && $format === 'table') {
            $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
            if ($extension === 'md' || $extension === 'markdown') {
                $format = 'markdown';
            }
        }
        
        try {
            switch ($format) {
                case 'json':
                    $output = $this->formatJson($data);
                    if ($outputPath) {
                        $this->saveToFile($output, $outputPath, 'json');
                    } else {
                        $this->line($output);
                    }
                    break;
                    
                case 'csv':
                    if ($outputPath) {
                        $this->formatCsv($data, $outputPath);
                    } else {
                        // Если --output не указан, выводим только summary в stdout
                        $summaryCsv = $this->formatCsvSummary($data['summary']);
                        $this->line($summaryCsv);
                    }
                    break;
                    
                case 'markdown':
                    if ($outputPath) {
                        $output = $this->formatMarkdown($data);
                        $this->saveToFile($output, $outputPath, 'markdown');
                        // Также выводим в консоль для удобства
                        $this->formatTable($data);
                    } else {
                        $this->formatTable($data);
                    }
                    break;
                    
                case 'table':
                default:
                    $this->formatTable($data);
                    break;
            }
        } catch (\Exception $e) {
            $this->error('Ошибка при форматировании вывода: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }

        return 0;
    }

    /**
     * Валидация owner_id
     *
     * @param string $owner
     * @return bool
     */
    private function validateOwner(string $owner): bool
    {
        // Проверка, что это число (может быть отрицательным)
        if (!preg_match('/^-?\d+$/', $owner)) {
            return false;
        }

        // Для групп должно быть отрицательное число
        // Для пользователей - положительное
        return true;
    }

    /**
     * Валидация метрик
     *
     * @param string $metrics
     * @return bool
     */
    private function validateMetrics(string $metrics): bool
    {
        $validMetrics = ['er', 'likes', 'reposts', 'comments', 'all'];
        
        // Если указано несколько метрик через запятую
        if (strpos($metrics, ',') !== false) {
            $metricsArray = array_map('trim', explode(',', $metrics));
            foreach ($metricsArray as $metric) {
                if (!in_array($metric, $validMetrics)) {
                    return false;
                }
            }
            return true;
        }

        return in_array($metrics, $validMetrics);
    }

    /**
     * Парсинг периода анализа
     *
     * @param string $periodString
     * @return array ['from' => Carbon, 'to' => Carbon]
     * @throws \Exception
     */
    private function parsePeriod(string $periodString): array
    {
        $periodString = trim($periodString);

        // Произвольный диапазон: YYYY-MM-DD:YYYY-MM-DD
        if (strpos($periodString, ':') !== false) {
            $parts = explode(':', $periodString, 2);
            if (count($parts) !== 2) {
                throw new \Exception('Неверный формат диапазона. Используйте: YYYY-MM-DD:YYYY-MM-DD');
            }

            try {
                $from = Carbon::parse(trim($parts[0]))->startOfDay();
                $to = Carbon::parse(trim($parts[1]))->endOfDay();
            } catch (\Exception $e) {
                throw new \Exception('Неверный формат даты в диапазоне: ' . $e->getMessage());
            }

            if ($from->gt($to)) {
                throw new \Exception('Дата начала периода не может быть больше даты окончания');
            }

            return ['from' => $from, 'to' => $to];
        }

        // Предопределенные периоды
        $now = Carbon::now();
        
        switch (strtolower($periodString)) {
            case 'week':
                return [
                    'from' => $now->copy()->subDays(7)->startOfDay(),
                    'to' => $now->copy()->endOfDay()
                ];
            
            case 'month':
                return [
                    'from' => $now->copy()->subDays(30)->startOfDay(),
                    'to' => $now->copy()->endOfDay()
                ];
            
            case 'quarter':
                return [
                    'from' => $now->copy()->subDays(90)->startOfDay(),
                    'to' => $now->copy()->endOfDay()
                ];
            
            case 'year':
                return [
                    'from' => $now->copy()->subDays(365)->startOfDay(),
                    'to' => $now->copy()->endOfDay()
                ];
            
            default:
                throw new \Exception("Неизвестный период: {$periodString}. Допустимые значения: week, month, quarter, year или диапазон YYYY-MM-DD:YYYY-MM-DD");
        }
    }

    /**
     * Получение и кеширование информации о группе
     *
     * @param int $groupId ID группы (без минуса)
     * @return array|null Массив с полями: name, screen_name, members_count
     */
    private function getGroupInfo(int $groupId): ?array
    {
        $cacheKey = "vk_group_{$groupId}_info";
        
        return Cache::remember($cacheKey, 3600, function() use ($groupId) {
            try {
                $group = VkGroupService::getById($groupId, ['members_count', 'name', 'screen_name']);
                if (!$group) {
                    return null;
                }
                
                // Обрабатываем как объект или массив
                $name = is_object($group) ? ($group->name ?? null) : ($group['name'] ?? null);
                $screenName = is_object($group) ? ($group->screen_name ?? null) : ($group['screen_name'] ?? null);
                $membersCount = is_object($group) ? ($group->members_count ?? 0) : ($group['members_count'] ?? 0);
                
                return [
                    'name' => $name,
                    'screen_name' => $screenName,
                    'members_count' => $membersCount,
                ];
            } catch (\Exception $e) {
                if ($this->option('verbose')) {
                    $this->warn("Ошибка при получении информации о группе: " . $e->getMessage());
                }
                return null;
            }
        });
    }

    /**
     * Расчет Engagement Rate для одного поста
     *
     * @param int $likes
     * @param int $reposts
     * @param int $comments
     * @param int $membersCount
     * @return float
     */
    private function calculateER(int $likes, int $reposts, int $comments, int $membersCount): float
    {
        if ($membersCount == 0) {
            return 0.0;
        }
        
        $engagement = $likes + $reposts + $comments;
        return round(($engagement / $membersCount) * 100, 2);
    }

    /**
     * Получение постов из БД за период
     *
     * @param string $ownerId
     * @param Carbon $from
     * @param Carbon $to
     * @param int $minEngagement
     * @return Collection
     */
    private function getPostsForPeriod(string $ownerId, Carbon $from, Carbon $to, int $minEngagement = 0): Collection
    {
        if (!Schema::hasTable('vk_posts')) {
            return collect([]);
        }

        $query = DB::table('vk_posts')
            ->where('owner_id', $ownerId)
            ->whereBetween('date', [$from->toDateTimeString(), $to->toDateTimeString()]);

        // Фильтрация по минимальной вовлеченности
        if ($minEngagement > 0) {
            $query->whereRaw('(likes + reposts + comments) >= ?', [$minEngagement]);
        }

        return $query->orderBy('date', 'desc')->get();
    }

    /**
     * Расчет общей статистики
     *
     * @param Collection $posts
     * @param int $membersCount
     * @return array
     */
    private function calculateSummary(Collection $posts, int $membersCount): array
    {
        if ($posts->isEmpty()) {
            return [
                'total_posts' => 0,
                'avg_likes' => 0,
                'avg_reposts' => 0,
                'avg_comments' => 0,
                'avg_er' => 0.0,
                'total_engagement' => 0,
            ];
        }

        $totalLikes = $posts->sum('likes');
        $totalReposts = $posts->sum('reposts');
        $totalComments = $posts->sum('comments');
        $totalEngagement = $totalLikes + $totalReposts + $totalComments;
        
        $count = $posts->count();
        
        // Расчет среднего ER: среднее значение ER для каждого поста
        $totalER = 0;
        foreach ($posts as $post) {
            $totalER += $this->calculateER($post->likes, $post->reposts, $post->comments, $membersCount);
        }
        $avgER = $count > 0 ? round($totalER / $count, 2) : 0.0;
        
        return [
            'total_posts' => $count,
            'avg_likes' => round($totalLikes / $count, 2),
            'avg_reposts' => round($totalReposts / $count, 2),
            'avg_comments' => round($totalComments / $count, 2),
            'avg_er' => $avgER,
            'total_engagement' => $totalEngagement,
            'members_count' => $membersCount,
        ];
    }

    /**
     * Группировка постов по дням недели
     *
     * @param Collection $posts
     * @param string $timezone
     * @param int $membersCount
     * @return array
     */
    private function groupByDayOfWeek(Collection $posts, string $timezone, int $membersCount): array
    {
        $dayNames = [
            0 => 'Воскресенье',
            1 => 'Понедельник',
            2 => 'Вторник',
            3 => 'Среда',
            4 => 'Четверг',
            5 => 'Пятница',
            6 => 'Суббота',
        ];

        // Группировка по дням недели с учетом timezone
        $grouped = $posts->groupBy(function($post) use ($timezone) {
            return Carbon::parse($post->date)
                ->setTimezone($timezone)
                ->dayOfWeek; // 0-6 (0 = воскресенье)
        });

        $result = [];
        foreach ($grouped as $dayOfWeek => $dayPosts) {
            $dayLikes = $dayPosts->sum('likes');
            $dayReposts = $dayPosts->sum('reposts');
            $dayComments = $dayPosts->sum('comments');
            
            // Расчет среднего ER для дня: среднее значение ER для каждого поста
            $dayTotalER = 0;
            foreach ($dayPosts as $post) {
                $dayTotalER += $this->calculateER($post->likes, $post->reposts, $post->comments, $membersCount);
            }
            $dayAvgER = $dayPosts->count() > 0 ? round($dayTotalER / $dayPosts->count(), 2) : 0.0;
            
            $result[] = [
                'day_of_week' => $dayOfWeek,
                'day_name' => $dayNames[$dayOfWeek] ?? 'Неизвестно',
                'posts_count' => $dayPosts->count(),
                'avg_likes' => round($dayLikes / $dayPosts->count(), 2),
                'avg_reposts' => round($dayReposts / $dayPosts->count(), 2),
                'avg_comments' => round($dayComments / $dayPosts->count(), 2),
                'avg_er' => $dayAvgER,
            ];
        }

        // Сортировка по дню недели (понедельник = 1)
        usort($result, function($a, $b) {
            // Преобразуем: воскресенье (0) становится 7 для правильной сортировки
            $dayA = $a['day_of_week'] == 0 ? 7 : $a['day_of_week'];
            $dayB = $b['day_of_week'] == 0 ? 7 : $b['day_of_week'];
            return $dayA <=> $dayB;
        });

        return $result;
    }

    /**
     * Группировка постов по часам
     *
     * @param Collection $posts
     * @param string $timezone
     * @param int $membersCount
     * @return array
     */
    private function groupByHour(Collection $posts, string $timezone, int $membersCount): array
    {
        // Группировка по часам с учетом timezone
        $grouped = $posts->groupBy(function($post) use ($timezone) {
            return Carbon::parse($post->date)
                ->setTimezone($timezone)
                ->hour; // 0-23
        });

        $result = [];
        foreach ($grouped as $hour => $hourPosts) {
            // Минимум 3 поста для статистической значимости
            if ($hourPosts->count() < 3) {
                continue;
            }

            $hourLikes = $hourPosts->sum('likes');
            $hourReposts = $hourPosts->sum('reposts');
            $hourComments = $hourPosts->sum('comments');
            
            // Расчет среднего ER для часа: среднее значение ER для каждого поста
            $hourTotalER = 0;
            foreach ($hourPosts as $post) {
                $hourTotalER += $this->calculateER($post->likes, $post->reposts, $post->comments, $membersCount);
            }
            $hourAvgER = $hourPosts->count() > 0 ? round($hourTotalER / $hourPosts->count(), 2) : 0.0;
            
            // Расчет взвешенного показателя: учитываем и ER, и количество постов
            // Используем формулу: weighted_score = avg_er * (1 + log10(posts_count))
            // Это дает преимущество часам с большим количеством постов при одинаковом ER
            $postsCount = $hourPosts->count();
            $weightedScore = $hourAvgER * (1 + log10(max(1, $postsCount)));
            
            $result[] = [
                'hour' => $hour,
                'posts_count' => $postsCount,
                'avg_likes' => round($hourLikes / $postsCount, 2),
                'avg_reposts' => round($hourReposts / $postsCount, 2),
                'avg_comments' => round($hourComments / $postsCount, 2),
                'avg_er' => $hourAvgER,
                'weighted_score' => round($weightedScore, 4), // Для внутренней сортировки
            ];
        }

        // Сортировка по взвешенному показателю (от большего к меньшему)
        // Это учитывает и ER, и количество постов
        usort($result, function($a, $b) {
            // Сначала по взвешенному показателю
            $scoreDiff = $b['weighted_score'] <=> $a['weighted_score'];
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }
            // Если взвешенный показатель одинаковый, сортируем по количеству постов
            return $b['posts_count'] <=> $a['posts_count'];
        });

        // Удаляем временное поле weighted_score из результата
        foreach ($result as &$item) {
            unset($item['weighted_score']);
        }

        return $result;
    }

    /**
     * Вывод общей статистики
     *
     * @param array $summary
     * @return void
     */
    private function displaySummary(array $summary): void
    {
        $this->newLine();
        $this->info('=== Общая статистика ===');
        $this->line("Всего постов: {$summary['total_posts']}");
        $this->line("Средние лайки: {$summary['avg_likes']}");
        $this->line("Средние репосты: {$summary['avg_reposts']}");
        $this->line("Средние комментарии: {$summary['avg_comments']}");
        
        if ($summary['members_count'] > 0) {
            $this->line("Средний ER: {$summary['avg_er']}%");
        } else {
            $this->line("Средний ER: не рассчитан (нет данных о подписчиках)");
        }
    }

    /**
     * Вывод ER по дням недели
     *
     * @param array $erByDay
     * @return void
     */
    private function displayERByDay(array $erByDay): void
    {
        $this->newLine();
        $this->info('=== ER по дням недели ===');
        
        $tableData = [];
        foreach ($erByDay as $day) {
            $erDisplay = $day['avg_er'] > 0 ? $day['avg_er'] . '%' : 'N/A';
            $tableData[] = [
                $day['day_name'],
                $day['posts_count'],
                $erDisplay,
                $day['avg_likes'],
                $day['avg_reposts'],
                $day['avg_comments'],
            ];
        }
        
        $this->table(
            ['День недели', 'Постов', 'Средний ER', 'Средние лайки', 'Средние репосты', 'Средние комментарии'],
            $tableData
        );
    }

    /**
     * Вывод лучшего времени публикации
     *
     * @param array $bestTime
     * @return void
     */
    private function displayBestTime(array $bestTime): void
    {
        $this->newLine();
        $this->info('=== Лучшее время публикации ===');
        
        $tableData = [];
        foreach ($bestTime as $index => $hour) {
            $recommendation = '⚠️ Среднее';
            if ($index === 0) {
                $recommendation = '⭐ Лучшее';
            } elseif ($index < 3) {
                $recommendation = '⭐ Хорошее';
            }
            
            $erDisplay = $hour['avg_er'] > 0 ? $hour['avg_er'] . '%' : 'N/A';
            $tableData[] = [
                $hour['hour'] . ':00',
                $hour['posts_count'],
                $erDisplay,
                $recommendation,
            ];
        }
        
        $this->table(
            ['Час', 'Постов', 'Средний ER', 'Рекомендация'],
            $tableData
        );
    }

    /**
     * Парсинг метрик из строки
     *
     * @param string $metricsString
     * @return array
     */
    private function parseMetrics(string $metricsString): array
    {
        if ($metricsString === 'all') {
            return ['er', 'likes', 'reposts', 'comments'];
        }
        
        if (strpos($metricsString, ',') !== false) {
            return array_map('trim', explode(',', $metricsString));
        }
        
        return [$metricsString];
    }

    /**
     * Получение топ-постов по метрике
     *
     * @param Collection $posts
     * @param string $metric
     * @param int $limit
     * @param int $membersCount
     * @param string $ownerId
     * @return array
     */
    private function getTopPosts(Collection $posts, string $metric, int $limit, int $membersCount, string $ownerId): array
    {
        // Создаем массив постов с расчетом ER
        $postsWithMetrics = [];
        foreach ($posts as $post) {
            $er = $this->calculateER($post->likes, $post->reposts, $post->comments, $membersCount);
            
            $postsWithMetrics[] = [
                'post_id' => $post->post_id,
                'date' => $post->date,
                'text' => $post->text ?? '',
                'likes' => $post->likes,
                'reposts' => $post->reposts,
                'comments' => $post->comments,
                'er' => $er,
                'url' => $post->url,
            ];
        }

        // Сортировка по выбранной метрике
        usort($postsWithMetrics, function($a, $b) use ($metric) {
            switch ($metric) {
                case 'likes':
                    return $b['likes'] <=> $a['likes'];
                case 'reposts':
                    return $b['reposts'] <=> $a['reposts'];
                case 'comments':
                    return $b['comments'] <=> $a['comments'];
                case 'er':
                default:
                    return $b['er'] <=> $a['er'];
            }
        });

        // Ограничение количества
        return array_slice($postsWithMetrics, 0, $limit);
    }

    /**
     * Вывод топ-постов
     *
     * @param array $topPosts
     * @param string $metric
     * @return void
     */
    private function displayTopPosts(array $topPosts, string $metric): void
    {
        $metricNames = [
            'er' => 'ER',
            'likes' => 'Лайки',
            'reposts' => 'Репосты',
            'comments' => 'Комментарии',
        ];
        
        $metricName = $metricNames[$metric] ?? strtoupper($metric);
        
        $this->newLine();
        $this->info("=== Топ-посты по {$metricName} ===");
        
        $tableData = [];
        foreach ($topPosts as $index => $post) {
            $text = $post['text'] ?: '(без текста)';
            $text = \Illuminate\Support\Str::limit($text, 50);
            
            $metricValue = '';
            switch ($metric) {
                case 'likes':
                    $metricValue = $post['likes'];
                    break;
                case 'reposts':
                    $metricValue = $post['reposts'];
                    break;
                case 'comments':
                    $metricValue = $post['comments'];
                    break;
                case 'er':
                default:
                    $metricValue = $post['er'] > 0 ? $post['er'] . '%' : 'N/A';
                    break;
            }
            
            $tableData[] = [
                $index + 1,
                $metricValue,
                $post['likes'],
                $post['reposts'],
                $post['comments'],
                Carbon::parse($post['date'])->format('Y-m-d H:i'),
                $text,
            ];
        }
        
        $headers = ['Ранг', $metricName, 'Лайки', 'Репосты', 'Комментарии', 'Дата', 'Текст'];
        $this->table($headers, $tableData);
    }

    /**
     * Расчет предыдущего периода
     *
     * @param Carbon $currentFrom
     * @param Carbon $currentTo
     * @return array ['from' => Carbon, 'to' => Carbon]
     */
    private function calculatePreviousPeriod(Carbon $currentFrom, Carbon $currentTo): array
    {
        // Вычисляем длину текущего периода в днях
        $periodLength = $currentFrom->diffInDays($currentTo) + 1;
        
        // Предыдущий период той же длины, сдвинутый назад
        $previousTo = $currentFrom->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($periodLength - 1)->startOfDay();
        
        return [
            'from' => $previousFrom,
            'to' => $previousTo,
        ];
    }

    /**
     * Сравнение периодов
     *
     * @param array $current
     * @param array $previous
     * @return array
     */
    private function comparePeriods(array $current, array $previous): array
    {
        $compare = function($currentValue, $previousValue) {
            if ($previousValue == 0) {
                return $currentValue > 0 ? 100.0 : 0.0; // Если предыдущее было 0, а текущее > 0, то рост 100%
            }
            return round((($currentValue - $previousValue) / $previousValue) * 100, 2);
        };

        $getTrend = function($changePercent) {
            if ($changePercent > 5) {
                return '⬆️';
            } elseif ($changePercent < -5) {
                return '⬇️';
            } else {
                return '➡️';
            }
        };

        $erChange = $compare($current['avg_er'], $previous['avg_er']);
        $likesChange = $compare($current['avg_likes'], $previous['avg_likes']);
        $repostsChange = $compare($current['avg_reposts'], $previous['avg_reposts']);
        $commentsChange = $compare($current['avg_comments'], $previous['avg_comments']);
        $postsChange = $compare($current['total_posts'], $previous['total_posts']);

        return [
            'current' => $current,
            'previous' => $previous,
            'changes' => [
                'avg_er' => [
                    'percent' => $erChange,
                    'trend' => $getTrend($erChange),
                ],
                'avg_likes' => [
                    'percent' => $likesChange,
                    'trend' => $getTrend($likesChange),
                ],
                'avg_reposts' => [
                    'percent' => $repostsChange,
                    'trend' => $getTrend($repostsChange),
                ],
                'avg_comments' => [
                    'percent' => $commentsChange,
                    'trend' => $getTrend($commentsChange),
                ],
                'total_posts' => [
                    'percent' => $postsChange,
                    'trend' => $getTrend($postsChange),
                ],
            ],
        ];
    }

    /**
     * Вывод сравнения периодов
     *
     * @param array $comparison
     * @param array $currentPeriod
     * @param array $previousPeriod
     * @return void
     */
    private function displayComparison(array $comparison, array $currentPeriod, array $previousPeriod): void
    {
        $this->newLine();
        $this->info('=== Сравнение с предыдущим периодом ===');
        $this->line("Текущий период: {$currentPeriod['from']->format('Y-m-d')} - {$currentPeriod['to']->format('Y-m-d')}");
        $this->line("Предыдущий период: {$previousPeriod['from']->format('Y-m-d')} - {$previousPeriod['to']->format('Y-m-d')}");
        
        $tableData = [];
        $changes = $comparison['changes'];
        $current = $comparison['current'];
        $previous = $comparison['previous'];
        
        // Форматирование значений
        $formatValue = function($value, $isPercent = false) {
            if ($isPercent) {
                return number_format($value, 2, '.', '') . '%';
            }
            return number_format($value, 2, '.', ' ');
        };
        
        $tableData[] = [
            'Средний ER',
            $formatValue($current['avg_er'], true),
            $formatValue($previous['avg_er'], true),
            $formatValue($changes['avg_er']['percent'], true) . ' ' . $changes['avg_er']['trend'],
        ];
        
        $tableData[] = [
            'Средние лайки',
            $formatValue($current['avg_likes']),
            $formatValue($previous['avg_likes']),
            $formatValue($changes['avg_likes']['percent'], true) . ' ' . $changes['avg_likes']['trend'],
        ];
        
        $tableData[] = [
            'Средние репосты',
            $formatValue($current['avg_reposts']),
            $formatValue($previous['avg_reposts']),
            $formatValue($changes['avg_reposts']['percent'], true) . ' ' . $changes['avg_reposts']['trend'],
        ];
        
        $tableData[] = [
            'Средние комментарии',
            $formatValue($current['avg_comments']),
            $formatValue($previous['avg_comments']),
            $formatValue($changes['avg_comments']['percent'], true) . ' ' . $changes['avg_comments']['trend'],
        ];
        
        $tableData[] = [
            'Количество постов',
            $current['total_posts'],
            $previous['total_posts'],
            $formatValue($changes['total_posts']['percent'], true) . ' ' . $changes['total_posts']['trend'],
        ];
        
        $this->table(
            ['Метрика', 'Текущий период', 'Предыдущий период', 'Изменение'],
            $tableData
        );
    }

    /**
     * Форматирование данных в табличный формат
     *
     * @param array $data
     * @return void
     */
    private function formatTable(array $data): void
    {
        // Общая статистика
        $this->displaySummary($data['summary']);

        // ER по дням недели
        if (!empty($data['er_by_day'])) {
            $this->displayERByDay($data['er_by_day']);
        }

        // Лучшее время публикации
        if (!empty($data['best_time'])) {
            $this->displayBestTime($data['best_time']);
        }

        // Топ-посты
        if (!empty($data['top_posts'])) {
            foreach ($data['top_posts'] as $metric => $topPosts) {
                $this->displayTopPosts($topPosts, $metric);
            }
        }

        // Сравнение периодов
        if (!empty($data['comparison'])) {
            $this->displayComparison(
                $data['comparison'],
                [
                    'from' => Carbon::parse($data['comparison']['periods']['current']['from']),
                    'to' => Carbon::parse($data['comparison']['periods']['current']['to']),
                ],
                [
                    'from' => Carbon::parse($data['comparison']['periods']['previous']['from']),
                    'to' => Carbon::parse($data['comparison']['periods']['previous']['to']),
                ]
            );
        }
    }

    /**
     * Форматирование данных в JSON формат
     *
     * @param array $data
     * @return string
     */
    private function formatJson(array $data): string
    {
        // Очистка данных для JSON (удаление Carbon объектов, если есть)
        $jsonData = [
            'owner_id' => $data['owner_id'],
            'period' => $data['period'],
            'members_count' => $data['members_count'],
            'summary' => $data['summary'],
            'er_by_day' => $data['er_by_day'],
            'best_time' => $data['best_time'],
            'top_posts' => $data['top_posts'],
        ];

        if (!empty($data['comparison'])) {
            $jsonData['comparison'] = $data['comparison'];
        }

        return json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Форматирование данных в CSV формат
     *
     * @param array $data
     * @param string $outputPath
     * @return void
     */
    private function formatCsv(array $data, string $outputPath): void
    {
        // Определяем базовую директорию и имя файла
        $baseDir = dirname($outputPath);
        $baseName = pathinfo($outputPath, PATHINFO_FILENAME);
        $baseDir = $baseDir === '.' ? getcwd() : $baseDir;

        // Создаем директорию, если не существует
        if (!File::isDirectory($baseDir)) {
            File::makeDirectory($baseDir, 0755, true);
        }

        // 1. Сводная статистика
        $summaryPath = $baseDir . '/' . $baseName . '_summary.csv';
        $this->writeCsvFile($summaryPath, [
            ['Метрика', 'Значение'],
            ['Всего постов', $data['summary']['total_posts']],
            ['Средние лайки', $data['summary']['avg_likes']],
            ['Средние репосты', $data['summary']['avg_reposts']],
            ['Средние комментарии', $data['summary']['avg_comments']],
            ['Средний ER (%)', $data['summary']['avg_er']],
            ['Общая вовлеченность', $data['summary']['total_engagement']],
            ['Подписчиков', $data['members_count']],
        ]);

        // 2. ER по дням недели
        if (!empty($data['er_by_day'])) {
            $byDayPath = $baseDir . '/' . $baseName . '_by_day.csv';
            $rows = [['День недели', 'Постов', 'Средний ER (%)', 'Средние лайки', 'Средние репосты', 'Средние комментарии']];
            foreach ($data['er_by_day'] as $day) {
                $rows[] = [
                    $day['day_name'],
                    $day['posts_count'],
                    $day['avg_er'],
                    $day['avg_likes'],
                    $day['avg_reposts'],
                    $day['avg_comments'],
                ];
            }
            $this->writeCsvFile($byDayPath, $rows);
        }

        // 3. Лучшее время публикации
        if (!empty($data['best_time'])) {
            $bestTimePath = $baseDir . '/' . $baseName . '_best_time.csv';
            $rows = [['Час', 'Постов', 'Средний ER (%)', 'Средние лайки', 'Средние репосты', 'Средние комментарии']];
            foreach ($data['best_time'] as $hour) {
                $rows[] = [
                    $hour['hour'] . ':00',
                    $hour['posts_count'],
                    $hour['avg_er'],
                    $hour['avg_likes'],
                    $hour['avg_reposts'],
                    $hour['avg_comments'],
                ];
            }
            $this->writeCsvFile($bestTimePath, $rows);
        }

        // 4. Топ-посты
        if (!empty($data['top_posts'])) {
            foreach ($data['top_posts'] as $metric => $topPosts) {
                $metricNames = [
                    'er' => 'ER',
                    'likes' => 'likes',
                    'reposts' => 'reposts',
                    'comments' => 'comments',
                ];
                $metricName = $metricNames[$metric] ?? $metric;
                $topPostsPath = $baseDir . '/' . $baseName . '_top_posts_' . $metricName . '.csv';
                
                $rows = [['Ранг', 'Post ID', 'Дата', 'Текст', 'Лайки', 'Репосты', 'Комментарии', 'ER (%)', 'URL']];
                foreach ($topPosts as $index => $post) {
                    $rows[] = [
                        $index + 1,
                        $post['post_id'],
                        $post['date'],
                        $post['text'],
                        $post['likes'],
                        $post['reposts'],
                        $post['comments'],
                        $post['er'],
                        $post['url'] ?? '',
                    ];
                }
                $this->writeCsvFile($topPostsPath, $rows);
            }
        }

        // 5. Сравнение периодов
        if (!empty($data['comparison'])) {
            $comparisonPath = $baseDir . '/' . $baseName . '_comparison.csv';
            $comp = $data['comparison'];
            $rows = [
                ['Метрика', 'Текущий период', 'Предыдущий период', 'Изменение (%)', 'Тренд'],
                ['Средний ER (%)', $comp['current']['avg_er'], $comp['previous']['avg_er'], $comp['changes']['avg_er']['percent'], $comp['changes']['avg_er']['trend']],
                ['Средние лайки', $comp['current']['avg_likes'], $comp['previous']['avg_likes'], $comp['changes']['avg_likes']['percent'], $comp['changes']['avg_likes']['trend']],
                ['Средние репосты', $comp['current']['avg_reposts'], $comp['previous']['avg_reposts'], $comp['changes']['avg_reposts']['percent'], $comp['changes']['avg_reposts']['trend']],
                ['Средние комментарии', $comp['current']['avg_comments'], $comp['previous']['avg_comments'], $comp['changes']['avg_comments']['percent'], $comp['changes']['avg_comments']['trend']],
                ['Количество постов', $comp['current']['total_posts'], $comp['previous']['total_posts'], $comp['changes']['total_posts']['percent'], $comp['changes']['total_posts']['trend']],
            ];
            $this->writeCsvFile($comparisonPath, $rows);
        }

        $this->info("CSV файлы сохранены в: {$baseDir}");
    }

    /**
     * Форматирование только summary в CSV для stdout
     *
     * @param array $summary
     * @return string
     */
    private function formatCsvSummary(array $summary): string
    {
        $rows = [
            ['Метрика', 'Значение'],
            ['Всего постов', $summary['total_posts']],
            ['Средние лайки', $summary['avg_likes']],
            ['Средние репосты', $summary['avg_reposts']],
            ['Средние комментарии', $summary['avg_comments']],
            ['Средний ER (%)', $summary['avg_er']],
            ['Общая вовлеченность', $summary['total_engagement']],
        ];

        $output = '';
        foreach ($rows as $row) {
            $output .= implode(',', array_map(function($field) {
                // Экранирование кавычек и запятых в CSV
                if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                    $field = '"' . str_replace('"', '""', $field) . '"';
                }
                return $field;
            }, $row)) . "\n";
        }

        return $output;
    }

    /**
     * Запись CSV файла
     *
     * @param string $path
     * @param array $rows
     * @return void
     */
    private function writeCsvFile(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл для записи: {$path}");
        }

        // Добавляем BOM для корректного отображения кириллицы в Excel
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"');
        }

        fclose($handle);
    }

    /**
     * Сохранение данных в файл
     *
     * @param string $content
     * @param string $path
     * @param string $format
     * @return void
     */
    private function saveToFile(string $content, string $path, string $format): void
    {
        // Обработка относительных путей
        if (strpos($path, '/') !== 0 && strpos($path, '\\') !== 0) {
            // Относительный путь - используем базовую директорию проекта
            $path = base_path($path);
        }
        
        $dir = dirname($path);
        if ($dir !== '.' && !File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        try {
            File::put($path, $content);
            $this->info("Данные сохранены в файл: {$path}");
        } catch (\Exception $e) {
            $this->error("Ошибка при сохранении файла: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Форматирование данных в Markdown формат
     *
     * @param array $data
     * @return string
     */
    private function formatMarkdown(array $data): string
    {
        $output = "# Аналитика эффективности постов VK\n\n";
        
        // Определяем человекочитаемый идентификатор владельца
        $ownerDisplay = $data['owner_name'] ?? null;
        if (!$ownerDisplay && isset($data['owner_id'])) {
            // Если нет названия, используем ID с пояснением
            $ownerDisplay = "ID: {$data['owner_id']}";
        }
        
        $output .= "**Владелец:** {$ownerDisplay}";
        if (isset($data['owner_id']) && $ownerDisplay !== $data['owner_id']) {
            $output .= " ({$data['owner_id']})";
        }
        $output .= "\n\n";
        $output .= "**Период:** {$data['period']['from']} - {$data['period']['to']}\n\n";
        $output .= "**Подписчиков:** " . number_format($data['members_count'], 0, ',', ' ') . "\n\n";
        
        // Общая статистика
        $summary = $data['summary'];
        $output .= "## Общая статистика\n\n";
        $output .= "| Метрика | Значение |\n";
        $output .= "|---------|----------|\n";
        $output .= "| Всего постов | {$summary['total_posts']} |\n";
        $output .= "| Средние лайки | " . number_format($summary['avg_likes'], 2, '.', ' ') . " |\n";
        $output .= "| Средние репосты | " . number_format($summary['avg_reposts'], 2, '.', ' ') . " |\n";
        $output .= "| Средние комментарии | " . number_format($summary['avg_comments'], 2, '.', ' ') . " |\n";
        $output .= "| Средний ER | {$summary['avg_er']}% |\n";
        $output .= "| Общая вовлеченность | " . number_format($summary['total_engagement'], 0, ',', ' ') . " |\n\n";
        
        // ER по дням недели
        if (!empty($data['er_by_day'])) {
            $output .= "## ER по дням недели\n\n";
            $output .= "| День недели | Постов | Средний ER | Средние лайки | Средние репосты | Средние комментарии |\n";
            $output .= "|-------------|--------|------------|---------------|-----------------|---------------------|\n";
            foreach ($data['er_by_day'] as $day) {
                $erDisplay = $day['avg_er'] > 0 ? $day['avg_er'] . '%' : 'N/A';
                $output .= "| {$day['day_name']} | {$day['posts_count']} | {$erDisplay} | ";
                $output .= number_format($day['avg_likes'], 2, '.', ' ') . " | ";
                $output .= number_format($day['avg_reposts'], 2, '.', ' ') . " | ";
                $output .= number_format($day['avg_comments'], 2, '.', ' ') . " |\n";
            }
            $output .= "\n";
        }
        
        // Лучшее время публикации
        if (!empty($data['best_time'])) {
            $output .= "## Лучшее время публикации\n\n";
            
            // Предупреждение о малом количестве постов для рекомендаций
            $totalPosts = array_sum(array_column($data['best_time'], 'posts_count'));
            $minPostsForReliability = max(10, (int)($totalPosts * 0.05)); // Минимум 10 постов или 5% от общего количества
            
            $output .= "| Час | Постов | Средний ER | Рекомендация |\n";
            $output .= "|-----|--------|------------|--------------|\n";
            foreach ($data['best_time'] as $index => $hour) {
                $postsCount = $hour['posts_count'];
                
                // Определяем рекомендацию с учетом количества постов
                $recommendation = '⚠️ Среднее';
                if ($index === 0 && $postsCount >= $minPostsForReliability) {
                    $recommendation = '⭐ Лучшее';
                } elseif ($index < 3 && $postsCount >= $minPostsForReliability) {
                    $recommendation = '⭐ Хорошее';
                } elseif ($index < 3 && $postsCount < $minPostsForReliability) {
                    $recommendation = '⚠️ Мало данных';
                }
                
                $erDisplay = $hour['avg_er'] > 0 ? $hour['avg_er'] . '%' : 'N/A';
                $output .= "| {$hour['hour']}:00 | {$postsCount} | {$erDisplay} | {$recommendation} |\n";
            }
            $output .= "\n";
            
            // Добавляем примечание о статистической значимости
            if (!empty($data['best_time']) && $data['best_time'][0]['posts_count'] < $minPostsForReliability) {
                $output .= "> ⚠️ **Примечание:** Для надежных рекомендаций рекомендуется минимум {$minPostsForReliability} постов в час. Текущие данные могут быть недостаточно репрезентативными.\n\n";
            }
        }
        
        // Топ-посты
        if (!empty($data['top_posts'])) {
            $metricNames = [
                'er' => 'ER',
                'likes' => 'Лайки',
                'reposts' => 'Репосты',
                'comments' => 'Комментарии',
            ];
            
            foreach ($data['top_posts'] as $metric => $topPosts) {
                $metricName = $metricNames[$metric] ?? strtoupper($metric);
                $output .= "## Топ-посты по {$metricName}\n\n";
                
                // Формируем заголовок таблицы: метрика на втором месте, остальные колонки без дублирования
                $headerCols = ['Ранг', $metricName];
                
                // Добавляем остальные колонки, пропуская дубликат метрики
                $otherCols = ['Лайки', 'Репосты', 'Комментарии', 'Дата', 'Ссылка', 'Текст'];
                foreach ($otherCols as $col) {
                    if ($col !== $metricName) {
                        $headerCols[] = $col;
                    }
                }
                
                $header = '| ' . implode(' | ', $headerCols) . ' |';
                $output .= $header . "\n";
                
                // Формируем разделитель
                $separator = '|';
                foreach ($headerCols as $col) {
                    $separator .= str_repeat('-', max(3, mb_strlen($col))) . '|';
                }
                $output .= $separator . "\n";
                
                foreach ($topPosts as $index => $post) {
                    $text = $post['text'] ?: '(без текста)';
                    // Убираем переносы строк и лишние пробелы для корректного отображения в таблице
                    $text = preg_replace('/\s+/', ' ', $text); // Заменяем все пробельные символы на один пробел
                    $text = trim($text);
                    $text = \Illuminate\Support\Str::limit($text, 50);
                    $text = str_replace('|', '\\|', $text); // Экранирование для markdown
                    
                    // Формируем ссылку в Markdown формате
                    $url = $post['url'] ?? '';
                    $linkText = '🔗';
                    if ($url) {
                        $link = "[{$linkText}]({$url})";
                    } else {
                        $link = '-';
                    }
                    
                    $metricValue = '';
                    switch ($metric) {
                        case 'likes':
                            $metricValue = $post['likes'];
                            break;
                        case 'reposts':
                            $metricValue = $post['reposts'];
                            break;
                        case 'comments':
                            $metricValue = $post['comments'];
                            break;
                        case 'er':
                        default:
                            $metricValue = $post['er'] > 0 ? $post['er'] . '%' : 'N/A';
                            break;
                    }
                    
                    $date = Carbon::parse($post['date'])->format('Y-m-d H:i');
                    
                    // Формируем строку данных в том же порядке, что и заголовок
                    $rowData = [
                        ($index + 1),
                        $metricValue,
                    ];
                    
                    // Добавляем остальные данные в том же порядке, что и заголовок
                    if ($metricName !== 'Лайки') {
                        $rowData[] = $post['likes'];
                    }
                    if ($metricName !== 'Репосты') {
                        $rowData[] = $post['reposts'];
                    }
                    if ($metricName !== 'Комментарии') {
                        $rowData[] = $post['comments'];
                    }
                    $rowData[] = $date;
                    $rowData[] = $link;
                    $rowData[] = $text;
                    
                    $output .= '| ' . implode(' | ', $rowData) . " |\n";
                }
                $output .= "\n";
            }
        }
        
        // Сравнение периодов
        if (!empty($data['comparison'])) {
            $comp = $data['comparison'];
            $output .= "## Сравнение с предыдущим периодом\n\n";
            $output .= "**Текущий период:** {$comp['periods']['current']['from']} - {$comp['periods']['current']['to']}\n";
            $output .= "**Предыдущий период:** {$comp['periods']['previous']['from']} - {$comp['periods']['previous']['to']}\n\n";
            $output .= "| Метрика | Текущий период | Предыдущий период | Изменение |\n";
            $output .= "|---------|----------------|-------------------|-----------|\n";
            
            $formatValue = function($value, $isPercent = false) {
                if ($isPercent) {
                    return number_format($value, 2, '.', '') . '%';
                }
                return number_format($value, 2, '.', ' ');
            };
            
            $output .= "| Средний ER | {$formatValue($comp['current']['avg_er'], true)} | {$formatValue($comp['previous']['avg_er'], true)} | ";
            $output .= $formatValue($comp['changes']['avg_er']['percent'], true) . ' ' . $comp['changes']['avg_er']['trend'] . " |\n";
            
            $output .= "| Средние лайки | {$formatValue($comp['current']['avg_likes'])} | {$formatValue($comp['previous']['avg_likes'])} | ";
            $output .= $formatValue($comp['changes']['avg_likes']['percent'], true) . ' ' . $comp['changes']['avg_likes']['trend'] . " |\n";
            
            $output .= "| Средние репосты | {$formatValue($comp['current']['avg_reposts'])} | {$formatValue($comp['previous']['avg_reposts'])} | ";
            $output .= $formatValue($comp['changes']['avg_reposts']['percent'], true) . ' ' . $comp['changes']['avg_reposts']['trend'] . " |\n";
            
            $output .= "| Средние комментарии | {$formatValue($comp['current']['avg_comments'])} | {$formatValue($comp['previous']['avg_comments'])} | ";
            $output .= $formatValue($comp['changes']['avg_comments']['percent'], true) . ' ' . $comp['changes']['avg_comments']['trend'] . " |\n";
            
            $output .= "| Количество постов | {$comp['current']['total_posts']} | {$comp['previous']['total_posts']} | ";
            $output .= $formatValue($comp['changes']['total_posts']['percent'], true) . ' ' . $comp['changes']['total_posts']['trend'] . " |\n\n";
        }
        
        return $output;
    }
}


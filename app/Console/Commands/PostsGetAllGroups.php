<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkGroupService;
use App\Services\VkApi\VkUrlBuilder;
use App\Models\Resource;

class PostsGetAllGroups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:posts-get-all
                            {--from= : Дата начала периода (обязательный)}
                            {--to= : Дата окончания периода (опциональный, по умолчанию текущая дата)}
                            {--delay=0.3 : Задержка между запросами к группам в секундах (по умолчанию 0.3)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение постов для всех групп из vk-groups.csv с сохранением в БД (аналог vk:posts-get --db --clear для каждой группы)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Валидация обязательных параметров
        if (!$this->option('from')) {
            $this->error('Параметр --from обязателен');
            return 1;
        }

        // Парсинг дат
        try {
            $fromTimestamp = $this->parseDate($this->option('from'));
            $toTimestamp = $this->option('to') 
                ? $this->parseDate($this->option('to'))
                : time();
        } catch (\Exception $e) {
            $this->error('Ошибка парсинга даты: ' . $e->getMessage());
            return 1;
        }

        if ($fromTimestamp > $toTimestamp) {
            $this->error('Дата начала периода не может быть больше даты окончания');
            return 1;
        }

        // Загрузка списка групп
        try {
            $groupList = Resource::getList();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if (empty($groupList)) {
            $this->warn('Список групп пуст. Убедитесь, что файл resources/vk-groups.csv содержит данные.');
            return 1;
        }

        // Проверяем, существует ли таблица
        if (!Schema::hasTable('vk_posts')) {
            $this->error('Таблица vk_posts не существует. Запустите миграцию: php artisan migrate');
            return 1;
        }

        $this->info("Обработка " . count($groupList) . " групп...");
        $this->info("Период: с {$this->formatDate($fromTimestamp)} по {$this->formatDate($toTimestamp)}");
        $this->newLine();

        $wallService = new VkWallService();
        $totalProcessed = 0;
        $totalSaved = 0;
        $errors = 0;
        $delay = (float) $this->option('delay');

        $progressBar = $this->output->createProgressBar(count($groupList));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->start();

        foreach ($groupList as $groupName) {
            $progressBar->setMessage("Обработка: {$groupName}");
            
            try {
                // Резолвим группу в ID
                $meta = VkGroupService::resolveName($groupName);
                
                if (!$meta || !isset($meta->object_id)) {
                    $progressBar->setMessage("⚠ Ошибка резолвинга: {$groupName}");
                    $errors++;
                    $progressBar->advance();
                    continue;
                }

                $ownerId = "-{$meta->object_id}";

                // Очищаем БД для этой группы (аналог --clear)
                $this->clearDatabaseForOwner($ownerId);

                // Получаем посты за период
                $wallService->setOwner($ownerId);
                $allPosts = $this->getPostsForPeriod($wallService, $fromTimestamp, $toTimestamp);

                if (empty($allPosts)) {
                    $progressBar->setMessage("✓ Нет постов: {$groupName}");
                    $progressBar->advance();
                    $totalProcessed++;
                    
                    if ($delay > 0) {
                        usleep(1000000 * $delay);
                    }
                    continue;
                }

                // Сохраняем посты в БД
                $saved = $this->savePostsToDatabase($allPosts, $ownerId);
                $totalSaved += $saved;
                $totalProcessed++;
                
                $progressBar->setMessage("✓ Сохранено {$saved} постов: {$groupName}");

            } catch (\Throwable $e) {
                $errors++;
                $progressBar->setMessage("✗ Ошибка: {$groupName} - " . $e->getMessage());
                if ($this->option('verbose')) {
                    $this->newLine();
                    $this->error("Ошибка при обработке группы {$groupName}: " . $e->getMessage());
                }
            }

            $progressBar->advance();
            
            // Задержка между запросами
            if ($delay > 0) {
                usleep(1000000 * $delay);
            }
        }

        $progressBar->setMessage('');
        $progressBar->finish();
        $this->newLine(2);

        // Выводим итоговую статистику
        $this->info("=== Результаты ===");
        $this->line("Обработано групп: {$totalProcessed}");
        $this->line("Всего сохранено постов: {$totalSaved}");
        if ($errors > 0) {
            $this->warn("Ошибок: {$errors}");
        }

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Получить все посты за период
     *
     * @param VkWallService $wallService
     * @param int $fromTimestamp
     * @param int $toTimestamp
     * @return array
     */
    private function getPostsForPeriod(VkWallService $wallService, int $fromTimestamp, int $toTimestamp): array
    {
        $allPosts = [];
        $offset = 0;
        $count = 100;

        while (true) {
            try {
                $posts = $wallService->getPosts($count, $offset);
                
                if (empty($posts) || !is_array($posts)) {
                    break;
                }

                // Фильтруем посты по дате
                foreach ($posts as $post) {
                    $postDate = $post->date ?? 0;
                    
                    if ($postDate >= $fromTimestamp && $postDate <= $toTimestamp) {
                        $allPosts[] = $post;
                    } elseif ($postDate < $fromTimestamp) {
                        // Если пост старше начала периода, прекращаем поиск
                        return $allPosts;
                    }
                }

                // Если получили меньше постов чем запрашивали, значит это последняя страница
                if (count($posts) < $count) {
                    break;
                }

                $offset += $count;

                // Небольшая задержка между запросами к API
                usleep(300000); // 0.3 секунды

            } catch (\Exception $e) {
                $this->warn("Ошибка при получении постов: " . $e->getMessage());
                break;
            }
        }

        return $allPosts;
    }

    /**
     * Очистить БД для конкретного владельца
     *
     * @param string $ownerId
     * @return void
     */
    private function clearDatabaseForOwner(string $ownerId): void
    {
        try {
            $deleted = DB::table('vk_posts')
                ->where('owner_id', $ownerId)
                ->delete();
        } catch (\Exception $e) {
            // Игнорируем ошибки очистки, но логируем если verbose
            if ($this->option('verbose')) {
                $this->warn("Ошибка при очистке БД для {$ownerId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Сохранить посты в базу данных
     *
     * @param array $posts
     * @param string $ownerId
     * @return int Количество сохраненных постов
     */
    private function savePostsToDatabase(array $posts, string $ownerId): int
    {
        if (empty($posts)) {
            return 0;
        }

        $saved = 0;

        try {
            foreach ($posts as $post) {
                try {
                    $postData = [
                        'post_id' => $post->id ?? null,
                        'owner_id' => $ownerId,
                        'timestamp' => $post->date ?? 0,
                        'date' => Carbon::createFromTimestamp($post->date ?? 0)->toDateTimeString(),
                        'text' => $post->text ?? null,
                        'likes' => $post->likes->count ?? 0,
                        'reposts' => $post->reposts->count ?? 0,
                        'comments' => $post->comments->count ?? 0,
                        'url' => VkUrlBuilder::wallPost($ownerId, $post->id),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];

                    // Проверяем на дубликаты перед вставкой
                    $exists = DB::table('vk_posts')
                        ->where('owner_id', $ownerId)
                        ->where('post_id', $post->id ?? null)
                        ->exists();

                    if (!$exists) {
                        DB::table('vk_posts')->insert($postData);
                        $saved++;
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки отдельных постов
                    if ($this->option('verbose')) {
                        $this->warn("Ошибка при сохранении поста ID {$post->id}: " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error('Ошибка при сохранении в базу данных: ' . $e->getMessage());
        }

        return $saved;
    }

    /**
     * Парсинг даты в timestamp
     *
     * @param string $dateString
     * @return int
     */
    private function parseDate(string $dateString): int
    {
        // Поддержка относительных дат
        $relativeDates = [
            'today' => 'today',
            'yesterday' => 'yesterday',
            'last week' => '-1 week',
            'last month' => '-1 month',
            'last year' => '-1 year',
        ];

        if (isset($relativeDates[strtolower($dateString)])) {
            $dateString = $relativeDates[strtolower($dateString)];
        }

        try {
            $carbon = Carbon::parse($dateString);
            return $carbon->timestamp;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Неверный формат даты: {$dateString}");
        }
    }

    /**
     * Форматирование даты для вывода
     *
     * @param int $timestamp
     * @return string
     */
    private function formatDate(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
    }
}


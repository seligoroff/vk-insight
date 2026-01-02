<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkGroupService;
use App\Models\Resource;


class CheckReaction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:check {--cached} {--delay=0.3}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check last posts in group list';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            $list = Resource::getList();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }        
        $wallService = new VkWallService();
        $data = [];
        
        // Проверяем, нужно ли использовать кэш из БД
        $useCache = $this->option('cached') && $this->hasCacheInDatabase();
        
        if (!$useCache) {
            // Очищаем старый кэш перед созданием нового
            $this->clearCache();
            
            $progressbar = $this->output->createProgressBar(count($list));
            $progressbar->start();
            
            foreach ($list as $name) {
                try {
                    $meta  = VkGroupService::resolveName($name);
                    $wallService->setOwner("-{$meta->object_id}");
                    $group = VkGroupService::getById($meta->object_id);
                    $posts = $wallService->getPosts();
                } catch (\Throwable $e) {
                    $this->alert($e->getMessage());
                    continue;  
                }
                if (empty($posts)) {
                    continue;
                }
                foreach ($posts as $post) {
                    if (!empty($post->text)) {
                        break;
                    }
                }
                
                $postText = Str::limit($post->text, 40);
                $groupName = $group->name;
                $groupId = $meta->object_id;
                $likes = $post->likes->count ?? 0;
                $reposts = $post->reposts->count ?? 0;
                
                // Сохраняем в БД
                $this->saveToCache($groupName, $groupId, $postText, $likes, $reposts);
                
                // Добавляем в массив для вывода
                $data[] = [
                    $postText,
                    $groupName,
                    $groupId,
                    $likes,
                    $reposts
                ];             
                $progressbar->advance();
                if ($this->option('delay')) {
                    usleep(1000000 * $this->option('delay'));    
                }
            }
            $progressbar->finish();
        } else {
            // Загружаем данные из БД
            $data = $this->loadFromCache();
        }
        
        $this->table(['Post', 'Group name', 'Group ID', 'Likes', 'Reposts'], $data);
        
        return 0;
    }

    /**
     * Проверить, есть ли кэш в БД
     *
     * @return bool
     */
    private function hasCacheInDatabase(): bool
    {
        if (!Schema::hasTable('vk_check_cache')) {
            return false;
        }
        
        return DB::table('vk_check_cache')->exists();
    }

    /**
     * Очистить кэш в БД
     *
     * @return void
     */
    private function clearCache(): void
    {
        if (Schema::hasTable('vk_check_cache')) {
            DB::table('vk_check_cache')->truncate();
        }
    }

    /**
     * Сохранить данные в кэш БД
     *
     * @param string $groupName
     * @param int $groupId
     * @param string $postText
     * @param int $likes
     * @param int $reposts
     * @return void
     */
    private function saveToCache(string $groupName, int $groupId, string $postText, int $likes, int $reposts): void
    {
        if (!Schema::hasTable('vk_check_cache')) {
            $this->warn('Таблица vk_check_cache не существует. Запустите миграцию: php artisan migrate');
            return;
        }

        DB::table('vk_check_cache')->insert([
            'group_name' => $groupName,
            'group_id' => $groupId,
            'post_text' => $postText,
            'likes' => $likes,
            'reposts' => $reposts,
            'cached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Загрузить данные из кэша БД
     *
     * @return array
     */
    private function loadFromCache(): array
    {
        if (!Schema::hasTable('vk_check_cache')) {
            return [];
        }

        $cacheData = DB::table('vk_check_cache')
            ->orderBy('cached_at', 'desc')
            ->get();

        $data = [];
        foreach ($cacheData as $row) {
            $data[] = [
                $row->post_text,
                $row->group_name,
                $row->group_id,
                $row->likes,
                $row->reposts
            ];
        }

        return $data;
    }
}

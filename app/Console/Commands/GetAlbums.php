<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Services\VkApi\VkPhotoService;

class GetAlbums extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:albums-get 
                            {--owner= : ID владельца альбомов (обязательный, отрицательное число для групп)}
                            {--format=table : Формат вывода: table, json, csv}
                            {--output= : Путь к файлу для сохранения результатов (опциональный)}
                            {--need-system : Включить системные альбомы (с отрицательными ID)}
                            {--need-covers : Получить обложки альбомов (thumb_src)}
                            {--min-size= : Минимальное количество фотографий в альбоме для фильтрации}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение списка фотоальбомов пользователя или сообщества VK';

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

        // Валидация формата
        $format = $this->option('format');
        if (!in_array($format, ['table', 'json', 'csv'])) {
            $this->error('Неверный формат. Допустимые значения: table, json, csv');
            return 1;
        }

        // Получение альбомов
        $this->info("Получение альбомов для владельца {$this->option('owner')}...");
        
        $photoService = new VkPhotoService();
        
        $params = [];
        if ($this->option('need-system')) {
            $params['need_system'] = 1;
        }
        if ($this->option('need-covers')) {
            $params['need_covers'] = 1;
        }
        
        try {
            $albums = $photoService->getAllAlbums($this->option('owner'), $params);
        } catch (\Throwable $e) {
            $this->error('Ошибка при получении альбомов: ' . $e->getMessage());
            return 1;
        }
        
        if (empty($albums)) {
            $this->warn('Альбомы не найдены');
            return 0;
        }
        
        // Фильтрация по минимальному размеру
        if ($this->option('min-size')) {
            $minSize = (int) $this->option('min-size');
            $albums = array_filter($albums, function ($album) use ($minSize) {
                return isset($album->size) && $album->size >= $minSize;
            });
            $albums = array_values($albums); // Переиндексация массива
        }
        
        if (empty($albums)) {
            $this->warn('Альбомы не найдены после фильтрации');
            return 0;
        }
        
        // Форматирование и вывод
        $outputPath = $this->option('output');
        
        if ($outputPath) {
            // Определяем формат сохранения по расширению файла
            $saveFormat = $format;
            $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
            if ($extension === 'json') {
                $saveFormat = 'json';
            } elseif ($extension === 'csv') {
                $saveFormat = 'csv';
            }
            
            // Форматируем данные для сохранения
            $fileOutput = $this->formatOutput($albums, $saveFormat);
            
            // Проверяем, что данные не пустые
            if (empty($fileOutput) && !empty($albums)) {
                $this->error('Ошибка: не удалось отформатировать данные для сохранения');
                return 1;
            }
            
            if (empty($fileOutput)) {
                $this->warn('Предупреждение: данные для сохранения пусты');
            }
            
            // Если путь относительный и не начинается с storage/, считаем его абсолютным от корня проекта
            $finalPath = $outputPath;
            if (strpos($finalPath, '/') !== 0 && strpos($finalPath, 'storage/') !== 0) {
                $finalPath = base_path($finalPath);
            } elseif (strpos($finalPath, '/') !== 0) {
                // Относительный путь от storage/app/
                $finalPath = storage_path('app/' . $finalPath);
            }
            
            // Создаем директории, если их нет
            $directory = dirname($finalPath);
            if (!is_dir($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    $this->error("Не удалось создать директорию: {$directory}");
                    return 1;
                }
            }
            
            $bytesWritten = file_put_contents($finalPath, $fileOutput);
            if ($bytesWritten === false) {
                $this->error("Ошибка при сохранении файла: {$finalPath}");
                return 1;
            }
            
            $this->info("Результаты сохранены в файл: {$finalPath} ({$bytesWritten} байт)");
            
            // Для формата table всегда выводим таблицу в консоль, даже если сохраняем в файл
            if ($format === 'table') {
                $this->formatTable($albums);
            }
        } else {
            // Вывод в консоль
            $output = $this->formatOutput($albums, $format);
            
            // Для формата table всегда выводим таблицу в консоль
            if ($format === 'table') {
                $this->line($output);
            } else {
                // Вывод в stdout для json и csv
                $this->line($output);
            }
        }
        
        // Статистика
        $this->displayStatistics($albums);

        return 0;
    }

    /**
     * Форматирование вывода в зависимости от формата
     *
     * @param array $albums
     * @param string $format
     * @return string
     */
    private function formatOutput(array $albums, string $format): string
    {
        switch ($format) {
            case 'json':
                return $this->formatJson($albums);
            case 'csv':
                return $this->formatCsv($albums);
            case 'table':
            default:
                return $this->formatTable($albums);
        }
    }

    /**
     * Форматирование в таблицу
     *
     * @param array $albums
     * @return string
     */
    private function formatTable(array $albums): string
    {
        $data = [];
        foreach ($albums as $album) {
            $data[] = [
                $album->id ?? 'N/A',
                $album->title ?? 'N/A',
                $this->formatDescription($album->description ?? null),
                $album->size ?? 0,
                $this->formatDate($album->created ?? null),
                $this->formatDate($album->updated ?? null),
                $album->owner_id ?? 'N/A',
            ];
        }

        $this->table(
            ['ID', 'Название', 'Описание', 'Фото', 'Создан', 'Обновлен', 'Владелец'],
            $data
        );

        return ''; // Таблица уже выведена через $this->table()
    }

    /**
     * Форматирование в JSON
     *
     * @param array $albums
     * @return string
     */
    private function formatJson(array $albums): string
    {
        $result = [];
        foreach ($albums as $album) {
            $albumData = [
                'id' => $album->id ?? null,
                'title' => $album->title ?? '',
                'description' => $album->description ?? null,
                'size' => $album->size ?? 0,
                'created' => $album->created ?? null,
                'updated' => $album->updated ?? null,
                'owner_id' => $album->owner_id ?? null,
                'thumb_id' => $album->thumb_id ?? 0,
            ];
            
            // Добавляем thumb_src только если он есть
            if (isset($album->thumb_src)) {
                $albumData['thumb_src'] = $album->thumb_src;
            }
            
            // Опциональные поля (могут отсутствовать)
            if (isset($album->can_upload)) {
                $albumData['can_upload'] = (bool) $album->can_upload;
            }
            if (isset($album->privacy_view)) {
                $albumData['privacy_view'] = $album->privacy_view;
            }
            if (isset($album->privacy_comment)) {
                $albumData['privacy_comment'] = $album->privacy_comment;
            }
            if (isset($album->upload_by_admins_only)) {
                $albumData['upload_by_admins_only'] = (bool) $album->upload_by_admins_only;
            }
            if (isset($album->comments_disabled)) {
                $albumData['comments_disabled'] = (bool) $album->comments_disabled;
            }
            
            $result[] = $albumData;
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        if ($json === false) {
            $error = json_last_error_msg();
            throw new \RuntimeException("Ошибка JSON encoding: {$error}");
        }
        
        return $json;
    }

    /**
     * Форматирование в CSV
     *
     * @param array $albums
     * @return string
     */
    private function formatCsv(array $albums): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Заголовки
        fputcsv($output, ['id', 'title', 'description', 'size', 'created', 'updated', 'owner_id']);

        // Данные
        foreach ($albums as $album) {
            fputcsv($output, [
                $album->id ?? '',
                $album->title ?? '',
                $album->description ?? '',
                $album->size ?? 0,
                $album->created ? $this->formatDate($album->created) : '',
                $album->updated ? $this->formatDate($album->updated) : '',
                $album->owner_id ?? '',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Форматирование даты из Unix timestamp
     *
     * @param int|null $timestamp
     * @return string
     */
    private function formatDate(?int $timestamp): string
    {
        if ($timestamp === null) {
            return 'N/A';
        }
        
        return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
    }

    /**
     * Форматирование описания
     *
     * @param string|null $description
     * @return string
     */
    private function formatDescription(?string $description): string
    {
        if ($description === null || $description === '') {
            return 'N/A';
        }
        
        // Ограничиваем длину описания для таблицы
        return \Illuminate\Support\Str::limit($description, 40);
    }

    /**
     * Отображение статистики
     *
     * @param array $albums
     * @return void
     */
    private function displayStatistics(array $albums): void
    {
        $totalAlbums = count($albums);
        $totalPhotos = array_sum(array_map(function ($album) {
            return $album->size ?? 0;
        }, $albums));
        
        $this->info("Найдено альбомов: {$totalAlbums}");
        $this->info("Всего фотографий: {$totalPhotos}");
    }
}


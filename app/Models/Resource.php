<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Resource extends Model
{
    use HasFactory;
    
    
    public static function getList()
    {
        $filePath = resource_path('vk-groups.csv');
        
        // Проверяем существование файла
        if (!file_exists($filePath)) {
            throw new \RuntimeException(
                "Файл resources/vk-groups.csv не найден.\n" .
                "Создайте файл и добавьте в него URL групп VK, по одному на строку.\n" .
                "Пример содержимого:\n" .
                "https://vk.com/groupname1\n" .
                "https://vk.com/groupname2"
            );
        }
        
        $stream = fopen($filePath, 'r');
        
        if ($stream === false) {
            throw new \RuntimeException("Не удалось открыть файл resources/vk-groups.csv для чтения.");
        }
        
        $resources = [];
        while (($row = fgetcsv($stream)) !== false) {
            if (!empty($row[0])) {
                $line = trim($row[0]);
                // Пропускаем пустые строки и комментарии
                if (!empty($line) && strpos($line, '#') !== 0) {
                    $resources[] = trim(parse_url($line, PHP_URL_PATH), '/ ');            
                }
            }
        }
        
        fclose($stream);
        
        return $resources;
    }
}

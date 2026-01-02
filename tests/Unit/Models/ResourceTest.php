<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\Resource;

class ResourceTest extends TestCase
{
    private $originalFile;
    private $backupFile;
    private $targetFile;
    private $resourcesDir;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Определяем путь к resources/ директории через resource_path()
        $this->resourcesDir = resource_path();
        $this->targetFile = resource_path('vk-groups.csv');
        $this->backupFile = resource_path('vk-groups.csv.backup');
        
        // Сохраняем оригинальный файл, если он существует
        if (file_exists($this->targetFile)) {
            copy($this->targetFile, $this->backupFile);
            $this->originalFile = true;
        } else {
            $this->originalFile = false;
        }
    }

    protected function tearDown(): void
    {
        // Восстанавливаем оригинальный файл
        if ($this->targetFile && $this->backupFile) {
            if ($this->originalFile && file_exists($this->backupFile)) {
                copy($this->backupFile, $this->targetFile);
                unlink($this->backupFile);
            } elseif (!$this->originalFile && file_exists($this->targetFile)) {
                // Удаляем тестовый файл, если оригинального не было
                unlink($this->targetFile);
            }
        }
        
        parent::tearDown();
    }

    /**
     * Создать тестовый CSV файл
     */
    private function createTestCsvFile(array $lines): void
    {
        file_put_contents($this->targetFile, implode("\n", $lines));
    }

    /**
     * Тест чтения CSV с валидными URL
     */
    public function test_reads_valid_urls()
    {
        $this->createTestCsvFile([
            'https://vk.com/group1',
            'https://vk.com/group2',
            'https://vk.com/public123456',
        ]);
        
        $result = Resource::getList();
        
        $this->assertCount(3, $result);
        $this->assertEquals('group1', $result[0]);
        $this->assertEquals('group2', $result[1]);
        $this->assertEquals('public123456', $result[2]);
    }

    /**
     * Тест пропуска пустых строк
     */
    public function test_skips_empty_lines()
    {
        $this->createTestCsvFile([
            'https://vk.com/group1',
            '',
            'https://vk.com/group2',
            '   ',
            'https://vk.com/group3',
        ]);
        
        $result = Resource::getList();
        
        $this->assertCount(3, $result);
        $this->assertEquals('group1', $result[0]);
        $this->assertEquals('group2', $result[1]);
        $this->assertEquals('group3', $result[2]);
    }

    /**
     * Тест пропуска комментариев (строки начинающиеся с #)
     */
    public function test_skips_comments()
    {
        $this->createTestCsvFile([
            'https://vk.com/group1',
            '# Это комментарий',
            'https://vk.com/group2',
            '# Еще один комментарий',
            'https://vk.com/group3',
        ]);
        
        $result = Resource::getList();
        
        $this->assertCount(3, $result);
        $this->assertEquals('group1', $result[0]);
        $this->assertEquals('group2', $result[1]);
        $this->assertEquals('group3', $result[2]);
    }

    /**
     * Тест парсинга различных форматов URL
     */
    public function test_parses_different_url_formats()
    {
        $this->createTestCsvFile([
            'https://vk.com/group1',
            'https://vk.com/public123456',
            'https://vk.com/club789012',
            'https://vk.com/t.mayakovskogo',
        ]);
        
        $result = Resource::getList();
        
        $this->assertCount(4, $result);
        $this->assertEquals('group1', $result[0]);
        $this->assertEquals('public123456', $result[1]);
        $this->assertEquals('club789012', $result[2]);
        $this->assertEquals('t.mayakovskogo', $result[3]);
    }

    /**
     * Тест обработки URL с пробелами
     */
    public function test_trims_whitespace()
    {
        $this->createTestCsvFile([
            '  https://vk.com/group1  ',
            'https://vk.com/group2',
        ]);
        
        $result = Resource::getList();
        
        $this->assertCount(2, $result);
        $this->assertEquals('group1', $result[0]);
        $this->assertEquals('group2', $result[1]);
    }

    /**
     * Тест обработки ошибки при отсутствии файла
     */
    public function test_throws_exception_when_file_not_found()
    {
        // Удаляем файл, если он существует
        if (file_exists($this->targetFile)) {
            unlink($this->targetFile);
        }
        
        // Временно переименовываем директорию resources, чтобы симулировать отсутствие файла
        // Но это может быть проблематично, поэтому просто проверим, что исключение выбрасывается
        // Для этого нужно, чтобы файл действительно не существовал
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Файл resources/vk-groups.csv не найден');
        
        Resource::getList();
    }
}


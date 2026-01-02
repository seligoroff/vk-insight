<?php

namespace Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use App\Console\Commands\GetPosts;
use Carbon\Carbon;
use ReflectionClass;

class GetPostsTest extends TestCase
{
    /**
     * Получить доступ к приватному методу parseDate
     */
    private function getParseDateMethod()
    {
        $command = new GetPosts();
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('parseDate');
        $method->setAccessible(true);
        return [$command, $method];
    }

    /**
     * Тест парсинга даты в формате YYYY-MM-DD
     */
    public function test_parse_date_iso_format()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp = $method->invoke($command, '2024-01-15');
        $expected = Carbon::createFromFormat('Y-m-d', '2024-01-15')->startOfDay()->timestamp;
        
        $this->assertEquals($expected, $timestamp);
    }

    /**
     * Тест парсинга даты в формате YYYY-MM-DD HH:MM:SS
     */
    public function test_parse_date_datetime_format()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp = $method->invoke($command, '2024-01-15 14:30:00');
        $expected = Carbon::createFromFormat('Y-m-d H:i:s', '2024-01-15 14:30:00')->timestamp;
        
        $this->assertEquals($expected, $timestamp);
    }

    /**
     * Тест парсинга относительной даты "today"
     */
    public function test_parse_date_relative_today()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp = $method->invoke($command, 'today');
        $expected = Carbon::today()->timestamp;
        
        $this->assertEquals($expected, $timestamp);
    }

    /**
     * Тест парсинга относительной даты "yesterday"
     */
    public function test_parse_date_relative_yesterday()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp = $method->invoke($command, 'yesterday');
        $expected = Carbon::yesterday()->timestamp;
        
        $this->assertEquals($expected, $timestamp);
    }

    /**
     * Тест парсинга относительной даты "last week"
     */
    public function test_parse_date_relative_last_week()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp = $method->invoke($command, 'last week');
        $expected = Carbon::now()->subWeek()->timestamp;
        
        // Проверяем, что разница не более 1 секунды (так как now() может немного отличаться)
        $this->assertLessThanOrEqual(1, abs($timestamp - $expected));
    }

    /**
     * Тест парсинга относительной даты "last month"
     */
    public function test_parse_date_relative_last_month()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp = $method->invoke($command, 'last month');
        $expected = Carbon::now()->subMonth()->timestamp;
        
        // Проверяем, что разница не более 1 секунды
        $this->assertLessThanOrEqual(1, abs($timestamp - $expected));
    }

    /**
     * Тест парсинга даты в регистронезависимом формате
     */
    public function test_parse_date_case_insensitive()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp1 = $method->invoke($command, 'TODAY');
        $timestamp2 = $method->invoke($command, 'today');
        
        $this->assertEquals($timestamp1, $timestamp2);
    }

    /**
     * Тест парсинга даты с пробелами
     */
    public function test_parse_date_with_whitespace()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $timestamp1 = $method->invoke($command, '  2024-01-15  ');
        $timestamp2 = $method->invoke($command, '2024-01-15');
        
        $this->assertEquals($timestamp1, $timestamp2);
    }

    /**
     * Тест обработки некорректного формата даты
     */
    public function test_parse_date_invalid_format_throws_exception()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Не удалось распарсить дату');
        
        $method->invoke($command, 'invalid-date-format');
    }

    /**
     * Тест парсинга даты через автоматический парсинг Carbon
     */
    public function test_parse_date_auto_parse()
    {
        [$command, $method] = $this->getParseDateMethod();
        
        // Carbon может распарсить многие форматы автоматически
        $timestamp = $method->invoke($command, '15.01.2024');
        $expected = Carbon::parse('15.01.2024')->timestamp;
        
        $this->assertEquals($expected, $timestamp);
    }
}


<?php

namespace Tests\Unit\Services\VkApi;

use PHPUnit\Framework\TestCase;
use App\Services\VkApi\VkUrlBuilder;

class VkUrlBuilderTest extends TestCase
{
    /**
     * Тест построения URL поста с отрицательным ownerId (группа)
     */
    public function test_wall_post_url_with_negative_owner()
    {
        $url = VkUrlBuilder::wallPost(-12345678, 12345, 'https://vk.com');
        $this->assertEquals('https://vk.com?w=wall-12345678_12345', $url);
    }

    /**
     * Тест построения URL поста с положительным ownerId (пользователь)
     */
    public function test_wall_post_url_with_positive_owner()
    {
        $url = VkUrlBuilder::wallPost(12345678, 12345, 'https://vk.com');
        $this->assertEquals('https://vk.com?w=wall12345678_12345', $url);
    }

    /**
     * Тест построения URL поста со строковым ownerId
     */
    public function test_wall_post_url_with_string_owner()
    {
        $url = VkUrlBuilder::wallPost('-12345678', 12345, 'https://vk.com');
        $this->assertEquals('https://vk.com?w=wall-12345678_12345', $url);
    }

    /**
     * Тест построения URL поста с кастомным base URL
     */
    public function test_wall_post_url_with_custom_base_url()
    {
        $url = VkUrlBuilder::wallPost(-12345678, 12345, 'https://vk.com/seligoroff');
        $this->assertEquals('https://vk.com/seligoroff?w=wall-12345678_12345', $url);
    }

    /**
     * Тест построения URL комментария
     */
    public function test_wall_comment_url()
    {
        $url = VkUrlBuilder::wallComment(-12345678, 12345, 67890, 'https://vk.com');
        $this->assertEquals('https://vk.com/wall-12345678_12345?reply=67890', $url);
    }

    /**
     * Тест построения URL комментария с кастомным base URL
     */
    public function test_wall_comment_url_with_custom_base_url()
    {
        $url = VkUrlBuilder::wallComment(-12345678, 12345, 67890, 'https://vk.com/seligoroff');
        $this->assertEquals('https://vk.com/seligoroff/wall-12345678_12345?reply=67890', $url);
    }

    /**
     * Тест построения URL комментария для пользователя
     */
    public function test_wall_comment_url_with_positive_owner()
    {
        $url = VkUrlBuilder::wallComment(12345678, 12345, 67890, 'https://vk.com');
        $this->assertEquals('https://vk.com/wall12345678_12345?reply=67890', $url);
    }
}


<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkAudioService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;

class VkAudioServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
    /**
     * Создать мок аудио attachment
     */
    private function createMockAudioAttach(int $audioId = 1, int $ownerId = 12345678): object
    {
        $audio = new \stdClass();
        $audio->id = $audioId;
        $audio->owner_id = $ownerId;
        $audio->title = 'Test Audio';
        $audio->artist = 'Test Artist';

        $audioObj = new \stdClass();
        $audioObj->audio = $audio;

        return $audioObj;
    }

    /**
     * Тест добавления аудио без group_id
     */
    public function test_adds_audio_without_group()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(1);

        $service = new VkAudioService();
        $service->setAdapter($mockAdapter);
        $audioAttach = $this->createMockAudioAttach();
        $result = $service->add($audioAttach);

        $this->assertEquals(1, $result);
    }

    /**
     * Тест добавления аудио с group_id
     */
    public function test_adds_audio_with_group()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(1);

        $service = new VkAudioService();
        $service->setAdapter($mockAdapter);
        $audioAttach = $this->createMockAudioAttach(1, 12345678);
        $result = $service->add($audioAttach, '-12345678');

        $this->assertEquals(1, $result);
    }

    /**
     * Тест обработки ошибки API
     */
    public function test_handles_api_error()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andThrow(new \Exception('VK API Error: Access denied', 15));

        $service = new VkAudioService();
        $service->setAdapter($mockAdapter);
        $audioAttach = $this->createMockAudioAttach();
        $result = $service->add($audioAttach);

        // При ошибке API метод вернет null
        $this->assertNull($result);
    }

    /**
     * Тест обработки пустого ответа
     */
    public function test_handles_empty_response()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(null);

        $service = new VkAudioService();
        $service->setAdapter($mockAdapter);
        $audioAttach = $this->createMockAudioAttach();
        $result = $service->add($audioAttach);

        $this->assertNull($result);
    }
}


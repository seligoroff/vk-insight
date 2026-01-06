<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkPhotoService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;
use stdClass;

class VkPhotoServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
    /**
     * Создать мок альбома VK API
     */
    private function createMockAlbum(array $data): stdClass
    {
        $album = new stdClass();
        $album->id = $data['id'] ?? 1;
        $album->title = $data['title'] ?? 'Альбом';
        $album->description = $data['description'] ?? null;
        $album->size = $data['size'] ?? 0;
        $album->created = $data['created'] ?? null;
        $album->updated = $data['updated'] ?? null;
        $album->owner_id = $data['owner_id'] ?? -12345678;
        $album->thumb_id = $data['thumb_id'] ?? 0;
        
        if (isset($data['thumb_src'])) {
            $album->thumb_src = $data['thumb_src'];
        }
        
        return $album;
    }

    /**
     * Создать мок ответа VK API для photos.getAlbums
     */
    private function createGetAlbumsResponse(array $albums): array
    {
        return [
            'response' => [
                'count' => count($albums),
                'items' => $albums
            ]
        ];
    }

    /**
     * Тест получения альбомов
     */
    public function test_gets_albums()
    {
        $albums = [
            ['id' => 1, 'title' => 'Альбом 1', 'size' => 10, 'owner_id' => -12345678],
            ['id' => 2, 'title' => 'Альбом 2', 'size' => 20, 'owner_id' => -12345678],
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $albums]);

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('Альбом 1', $result[0]->title);
    }

    /**
     * Тест получения альбомов с параметрами
     */
    public function test_gets_albums_with_params()
    {
        $albums = [
            ['id' => -6, 'title' => 'Фотографии со мной', 'size' => 5, 'owner_id' => -12345678], // Системный альбом
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $albums]);

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAlbums(-12345678, [
            'need_system' => 1,
            'need_covers' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(-6, $result[0]->id);
        $this->assertEquals('Фотографии со мной', $result[0]->title);
    }

    /**
     * Тест пагинации - получение всех альбомов
     */
    public function test_get_all_albums_with_pagination()
    {
        // Первая страница (100 альбомов)
        $albumsPage1 = [];
        for ($i = 1; $i <= 100; $i++) {
            $albumsPage1[] = ['id' => $i, 'title' => "Альбом {$i}", 'size' => $i, 'owner_id' => -12345678];
        }

        // Вторая страница (меньше 100, значит последняя)
        $albumsPage2 = [
            ['id' => 101, 'title' => 'Альбом 101', 'size' => 101, 'owner_id' => -12345678],
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function() use (&$albumsPage1, &$albumsPage2) {
                static $callCount = 0;
                $callCount++;
                if ($callCount === 1) {
                    return ['items' => $albumsPage1];
                } else {
                    return ['items' => $albumsPage2];
                }
            });

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAllAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertCount(101, $result); // 100 + 1
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals(101, $result[100]->id);
    }

    /**
     * Тест пагинации - одна страница
     */
    public function test_get_all_albums_single_page()
    {
        $albums = [
            ['id' => 1, 'title' => 'Альбом 1', 'owner_id' => -12345678],
            ['id' => 2, 'title' => 'Альбом 2', 'owner_id' => -12345678],
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $albums]);

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAllAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
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
            ->andReturn(['items' => []]);

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAllAlbums(-12345678);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Тест обработки null ответа
     */
    public function test_handles_null_response()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(null);

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAlbums(-12345678);

        $this->assertNull($result);
    }

    /**
     * Тест получения альбомов с обложками
     */
    public function test_gets_albums_with_covers()
    {
        $albums = [
            [
                'id' => 1,
                'title' => 'Альбом с обложкой',
                'thumb_id' => 12345,
                'thumb_src' => 'https://example.com/thumb.jpg',
                'owner_id' => -12345678
            ],
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $albums]);

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAlbums(-12345678, ['need_covers' => 1]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertTrue(isset($result[0]->thumb_src));
        $this->assertEquals('https://example.com/thumb.jpg', $result[0]->thumb_src);
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

        $service = new VkPhotoService();
        $service->setAdapter($mockAdapter);
        $result = $service->getAlbums(-12345678);

        $this->assertNull($result);
    }
}


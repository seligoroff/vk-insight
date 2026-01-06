<?php

namespace Tests\Unit\Services\VkApi;

use Tests\TestCase;
use App\Services\VkApi\VkWallService;
use App\Services\VkApi\VkSdkAdapter;
use Mockery;

class VkWallServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
    /**
     * Создать мок поста VK API
     */
    private function createMockPost(array $data): \stdClass
    {
        $post = new \stdClass();
        $post->id = $data['id'] ?? 1;
        $post->text = $data['text'] ?? 'Test post';
        $post->date = $data['date'] ?? time();
        $post->likes = (object)['count' => $data['likes'] ?? 0];
        $post->reposts = (object)['count' => $data['reposts'] ?? 0];
        $post->comments = (object)['count' => $data['comments'] ?? 0];
        return $post;
    }

    /**
     * Создать мок ответа VK API для wall.get
     */
    private function createWallGetResponse(array $posts): array
    {
        return [
            'response' => (object)[
                'count' => count($posts),
                'items' => $posts
            ]
        ];
    }

    /**
     * Тест получения постов
     */
    public function test_gets_posts()
    {
        $posts = [
            ['id' => 1, 'text' => 'Post 1', 'date' => time(), 'likes' => 0, 'reposts' => 0, 'comments' => 0],
            ['id' => 2, 'text' => 'Post 2', 'date' => time(), 'likes' => 0, 'reposts' => 0, 'comments' => 0],
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $posts]);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->getPosts(100, 0);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('Post 1', $result[0]->text);
    }

    /**
     * Тест получения постов с пагинацией
     */
    public function test_gets_posts_with_pagination()
    {
        $posts = [
            ['id' => 3, 'text' => 'Post 3', 'date' => time(), 'likes' => 0, 'reposts' => 0, 'comments' => 0],
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $posts]);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->getPosts(50, 100);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Тест получения комментариев
     */
    public function test_gets_comments()
    {
        $comments = [
            ['id' => 1, 'text' => 'Comment 1'],
            ['id' => 2, 'text' => 'Comment 2'],
        ];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(['items' => $comments]);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->getComments(123, 100, 0);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->id);
    }

    /**
     * Тест получения одного комментария
     */
    public function test_gets_single_comment()
    {
        $comment = ['id' => 123, 'text' => 'Single comment'];

        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn([$comment]);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->getComment(123);

        $this->assertNotNull($result);
        $this->assertEquals(123, $result->id);
        $this->assertEquals('Single comment', $result->text);
    }

    /**
     * Тест закрепления поста
     */
    public function test_pins_post()
    {
        $mockAdapter = Mockery::mock(VkSdkAdapter::class);
        $mockAdapter->shouldReceive('getToken')->andReturn('test_token');
        $mockAdapter->shouldReceive('execute')
            ->once()
            ->andReturn(1);

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->pinPost(123);

        $this->assertEquals(1, $result);
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

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->getPosts();

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

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->getPosts();

        $this->assertNull($result);
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

        $service = new VkWallService();
        $service->setAdapter($mockAdapter);
        $service->setOwner(-12345678);
        $result = $service->getPosts();

        $this->assertNull($result);
    }

    /**
     * Тест установки owner ID
     */
    public function test_sets_owner_id()
    {
        $service = new VkWallService();
        $result = $service->setOwner(-12345678);

        $this->assertInstanceOf(VkWallService::class, $result);
        $this->assertSame($service, $result); // Method chaining
    }
}


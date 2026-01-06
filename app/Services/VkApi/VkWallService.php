<?php

namespace App\Services\VkApi;

/**
 * VK Wall API Service
 * Handles operations with wall posts and comments
 * 
 * Migrated to use vkcom/vk-php-sdk via VkSdkAdapter
 */
class VkWallService
{
    private $ownerId;
    private ?VkSdkAdapter $adapter = null;

    /**
     * Set SDK adapter instance (for testing)
     * 
     * @param VkSdkAdapter|null $adapter
     * @return void
     */
    public function setAdapter(?VkSdkAdapter $adapter): void
    {
        $this->adapter = $adapter;
    }

    /**
     * Get SDK adapter instance
     * 
     * @return VkSdkAdapter
     */
    private function getAdapter(): VkSdkAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = new VkSdkAdapter();
        }
        return $this->adapter;
    }
    
    /**
     * Set owner ID for wall operations
     * 
     * @param string|int $ownerId Owner ID (use negative for communities)
     * @return self
     */
    public function setOwner($ownerId): self
    {
        $this->ownerId = $ownerId;
        return $this;
    }
    
    /**
     * Get wall posts
     * 
     * @param int $count Number of posts to return
     * @param int $offset Offset for pagination
     * @return array|null Returns array of post objects, or null on error
     */
    public function getPosts(int $count = 100, int $offset = 0): ?array
    {
        $adapter = $this->getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $count, $offset) {
                return $adapter->wall()->get(
                    $adapter->getToken(),
                    [
                        'owner_id' => $this->ownerId,
                        'offset' => $offset,
                        'count' => $count
                    ]
                );
            }, "getting wall posts");
            
            // SDK returns array with 'items' key
            if (is_array($result) && isset($result['items'])) {
                // Convert array items to objects for backward compatibility
                // Recursively convert nested arrays to objects (e.g., likes, reposts, comments)
                $items = $result['items'];
                return array_map(function($item) {
                    return $this->arrayToObject($item);
                }, $items);
            }
            
            return null;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
    
    /**
     * Recursively convert array to object
     * This ensures nested arrays (like likes, reposts, comments) are also converted to objects
     * 
     * @param mixed $data
     * @return mixed
     */
    private function arrayToObject($data)
    {
        if (is_array($data)) {
            return (object) array_map([$this, 'arrayToObject'], $data);
        }
        return $data;
    }
    
    /**
     * Get comments for a post
     * 
     * @param int $postId Post ID
     * @param int $count Number of comments to return
     * @param int $offset Offset for pagination
     * @return array|null Returns array of comment objects, or null on error
     */
    public function getComments(int $postId, int $count = 100, int $offset = 0): ?array
    {
        $adapter = $this->getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $postId, $count, $offset) {
                return $adapter->wall()->getComments(
                    $adapter->getToken(),
                    [
                        'owner_id' => $this->ownerId,
                        'post_id' => $postId,
                        'offset' => $offset,
                        'count' => $count
                    ]
                );
            }, "getting comments for post {$postId}");
            
            // SDK returns array with 'items' key
            if (is_array($result) && isset($result['items'])) {
                // Convert array items to objects for backward compatibility
                // Recursively convert nested arrays to objects
                $items = $result['items'];
                return array_map(function($item) {
                    return $this->arrayToObject($item);
                }, $items);
            }
            
            return null;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
    
    /**
     * Get single comment
     * 
     * @param int $commentId Comment ID
     * @return object|array|null Returns comment object/array, or null on error
     */
    public function getComment(int $commentId)
    {
        $adapter = $this->getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $commentId) {
                return $adapter->wall()->getComment(
                    $adapter->getToken(),
                    [
                        'owner_id' => $this->ownerId,
                        'comment_id' => $commentId
                    ]
                );
            }, "getting comment {$commentId}");
            
            // SDK returns array of comments
            if (is_array($result) && !empty($result)) {
                $comment = $result[0];
                // Convert to object for backward compatibility
                // Recursively convert nested arrays to objects
                return $this->arrayToObject($comment);
            }
            
            return null;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
    
    /**
     * Pin post on the wall
     * 
     * @param int $postId Post ID to pin
     * @return int|mixed Returns result code or result data, or null on error
     */
    public function pinPost(int $postId)
    {
        sleep(1); // Rate limiting
        
        $adapter = $this->getAdapter();
        
        try {
            $result = $adapter->execute(function() use ($adapter, $postId) {
                return $adapter->wall()->pin(
                    $adapter->getToken(),
                    [
                        'post_id' => $postId,
                        'owner_id' => $this->ownerId
                    ]
                );
            }, "pinning post {$postId}");
            
            return $result;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
}


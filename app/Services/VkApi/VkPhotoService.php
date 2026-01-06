<?php

namespace App\Services\VkApi;

/**
 * VK Photo API Service
 * Handles operations with photo albums
 * 
 * Migrated to use vkcom/vk-php-sdk via VkSdkAdapter
 */
class VkPhotoService
{
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
     * Get photo albums for owner
     * 
     * @param string|int $ownerId Owner ID (use negative for communities)
     * @param array $params Additional parameters (need_system, need_covers, count, offset, album_ids)
     * @return array|null Returns array of album objects, or null on error
     */
    public function getAlbums($ownerId, array $params = []): ?array
    {
        $adapter = $this->getAdapter();
        
        $apiParams = array_merge([
            'owner_id' => $ownerId,
        ], $params);
        
        try {
            $result = $adapter->execute(function() use ($adapter, $apiParams) {
                return $adapter->photos()->getAlbums(
                    $adapter->getToken(),
                    $apiParams
                );
            }, "getting photo albums");
            
            // SDK returns array with 'items' key
            if (is_array($result) && isset($result['items'])) {
                // Convert array items to objects for backward compatibility
                $items = $result['items'];
                return array_map(function($item) {
                    return is_array($item) ? (object)$item : $item;
                }, $items);
            }
            
            return null;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
    
    /**
     * Get all albums with pagination
     * 
     * @param string|int $ownerId Owner ID
     * @param array $params Additional parameters
     * @return array Returns all albums as array of objects
     */
    public function getAllAlbums($ownerId, array $params = []): array
    {
        $allAlbums = [];
        $offset = 0;
        $count = $params['count'] ?? 100;
        
        while (true) {
            $albums = $this->getAlbums($ownerId, array_merge($params, [
                'offset' => $offset,
                'count' => $count
            ]));
            
            if (empty($albums) || !is_array($albums)) {
                break;
            }
            
            $allAlbums = array_merge($allAlbums, $albums);
            
            // Если получили меньше запрошенного, значит это последняя страница
            if (count($albums) < $count) {
                break;
            }
            
            $offset += $count;
        }
        
        return $allAlbums;
    }
}



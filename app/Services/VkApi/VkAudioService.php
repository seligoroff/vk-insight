<?php

namespace App\Services\VkApi;

/**
 * VK Audio API Service
 * Handles operations with audio files
 * 
 * Migrated to use vkcom/vk-php-sdk via VkSdkAdapter
 */
class VkAudioService
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
     * Add audio to group or user
     * 
     * @param object $audioAttach Audio attachment object with 'audio' property containing 'id' and 'owner_id'
     * @param string|null $groupId Group ID (optional)
     * @return int|mixed Returns result code or result data, or null on error
     */
    public function add($audioAttach, ?string $groupId = null)
    {
        sleep(1); // Rate limiting
        
        $adapter = $this->getAdapter();
        
        $params = [
            'audio_id' => $audioAttach->audio->id,
            'owner_id' => $audioAttach->audio->owner_id
        ];
        
        if ($groupId) {
            $params['group_id'] = $groupId;
        }
        
        try {
            $result = $adapter->execute(function() use ($adapter, $params) {
                return $adapter->audio()->add(
                    $adapter->getToken(),
                    $params
                );
            }, "adding audio");
            
            return $result;
        } catch (\Exception $e) {
            // Return null on error to maintain backward compatibility
            return null;
        }
    }
}



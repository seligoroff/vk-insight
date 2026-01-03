<?php

namespace App\Services\VkApi;

/**
 * VK Groups API Service
 * Handles operations with groups and communities
 */
class VkGroupService extends VkApiClient
{
    /**
     * Resolve screen name to VK object
     * 
     * @param string $screenName Screen name (e.g., 'durov')
     * @return array|null
     */
    public static function resolveName(string $screenName)
    {
        $response = self::apiGet('utils.resolveScreenName', [
            'screen_name' => $screenName
        ]);
        
        return self::parseResponse($response);
    }
    
    /**
     * Get group information by ID
     * 
     * @param int|string $groupId Group ID
     * @param array $fields Additional fields to return (e.g., ['members_count', 'description'])
     * @return array|null
     */
    public static function getById($groupId, array $fields = [])
    {
        $params = ['group_id' => $groupId];
        
        if (!empty($fields)) {
            // Валидация: fields должен быть массивом строк
            $validFields = array_filter($fields, function($field) {
                return is_string($field) && !empty(trim($field));
            });
            
            if (!empty($validFields)) {
                $params['fields'] = implode(',', $validFields);
            }
        }
        
        $response = self::apiGet('groups.getById', $params);
        
        $data = self::parseResponse($response);
        return $data[0] ?? null;
    }
    
    /**
     * Get user's groups
     * 
     * @param int $userId User ID
     * @return mixed
     */
    public function getUserGroups(int $userId)
    {
        $response = self::apiGet('groups.get', [
            'user_id' => $userId
        ]);
        
        return self::parseResponse($response);
    }
}


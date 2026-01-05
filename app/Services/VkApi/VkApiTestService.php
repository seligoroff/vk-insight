<?php

namespace App\Services\VkApi;

/**
 * VK API Testing Service
 * Used for token validation and API testing
 * Provides access to raw API responses for error checking
 */
class VkApiTestService extends VkApiClient
{
    /**
     * Execute API method and return raw response (including errors)
     * 
     * @param string $method API method name (e.g., 'users.get')
     * @param array $params Method parameters
     * @return array Raw API response as array (includes 'response' and 'error' keys)
     */
    public static function executeRaw(string $method, array $params = []): array
    {
        $response = self::apiGet($method, $params);
        return json_decode($response->body(), true) ?? [];
    }
    
    /**
     * Check if token is valid
     * 
     * @return bool True if token is valid, false otherwise
     */
    public static function isTokenValid(): bool
    {
        $result = self::executeRaw('users.get', []);
        return isset($result['response']) && !empty($result['response']) && !isset($result['error']);
    }
    
    /**
     * Get current user info
     * 
     * @param array $fields Additional fields to return (e.g., ['screen_name'])
     * @return array|null User data or null if not available
     */
    public static function getCurrentUser(array $fields = []): ?array
    {
        $params = [];
        if (!empty($fields)) {
            $params['fields'] = implode(',', $fields);
        }
        
        $result = self::executeRaw('users.get', $params);
        if (!isset($result['response']) || empty($result['response'])) {
            return null;
        }
        
        $user = $result['response'][0];
        return [
            'id' => $user['id'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'screen_name' => $user['screen_name'] ?? null,
        ];
    }
    
    /**
     * Test API method and check if it succeeds
     * 
     * @param string $method API method name
     * @param array $params Method parameters
     * @return array Contains 'success' (bool), 'error' (string|null), 'has_permission' (bool)
     */
    public static function testMethod(string $method, array $params = []): array
    {
        $result = self::executeRaw($method, $params);
        
        if (isset($result['error'])) {
            $errorCode = $result['error']['error_code'] ?? 0;
            $errorMsg = $result['error']['error_msg'] ?? 'Unknown error';
            
            // Error code 15 = Access denied (no permission)
            // Error code 5 = Invalid token
            $hasPermission = !in_array($errorCode, [15, 5]);
            
            return [
                'success' => false,
                'error' => $errorMsg,
                'error_code' => $errorCode,
                'has_permission' => $hasPermission,
            ];
        }
        
        return [
            'success' => true,
            'error' => null,
            'error_code' => null,
            'has_permission' => true,
        ];
    }
    
    /**
     * Get app permissions as bitmask using account.getAppPermissions
     * 
     * @param int|null $userId User ID (optional, defaults to current user)
     * @return array Contains 'success' (bool), 'bitmask' (int|null), 'error' (string|null)
     */
    public static function getAppPermissions(?int $userId = null): array
    {
        $params = [];
        if ($userId !== null) {
            $params['user_id'] = $userId;
        }
        
        $result = self::executeRaw('account.getAppPermissions', $params);
        
        if (isset($result['error'])) {
            $errorCode = $result['error']['error_code'] ?? 0;
            $errorMsg = $result['error']['error_msg'] ?? 'Unknown error';
            
            return [
                'success' => false,
                'bitmask' => null,
                'error' => $errorMsg,
                'error_code' => $errorCode,
            ];
        }
        
        $bitmask = $result['response'] ?? null;
        
        return [
            'success' => true,
            'bitmask' => is_numeric($bitmask) ? (int)$bitmask : null,
            'error' => null,
            'error_code' => null,
        ];
    }
    
    /**
     * Check if permission exists in bitmask
     * 
     * @param int $bitmask Bitmask from getAppPermissions
     * @param int $permission Permission bit value
     * @return bool
     */
    public static function hasPermission(int $bitmask, int $permission): bool
    {
        // Проверяем, что бит установлен
        // По документации VK: если (bitmask & permission) === permission, то право есть
        // Пример: 1026 & 2 = 2, значит право есть
        $result = $bitmask & $permission;
        return $result === $permission;
    }
}


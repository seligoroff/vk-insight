<?php

namespace App\Services\VkApi;

/**
 * VK API Testing Service
 * Used for token validation and API testing
 * Provides access to raw API responses for error checking
 * 
 * Migrated to use vkcom/vk-php-sdk via VkSdkAdapter
 */
class VkApiTestService
{
    private static ?VkSdkAdapter $adapter = null;

    /**
     * Set SDK adapter instance (for testing)
     * 
     * @param VkSdkAdapter|null $adapter
     * @return void
     */
    public static function setAdapter(?VkSdkAdapter $adapter): void
    {
        self::$adapter = $adapter;
    }

    /**
     * Get SDK adapter instance
     * 
     * @return VkSdkAdapter
     */
    private static function getAdapter(): VkSdkAdapter
    {
        if (self::$adapter === null) {
            self::$adapter = new VkSdkAdapter();
        }
        return self::$adapter;
    }

    /**
     * Execute API method and return raw response (including errors)
     * 
     * This method converts SDK exceptions to error array format for backward compatibility.
     * 
     * @param string $method API method name (e.g., 'users.get')
     * @param array $params Method parameters
     * @return array Raw API response as array (includes 'response' and 'error' keys)
     */
    public static function executeRaw(string $method, array $params = []): array
    {
        $adapter = self::getAdapter();
        $token = $adapter->getToken();
        
        // Парсим метод для определения API класса SDK
        $parts = explode('.', $method);
        if (count($parts) !== 2) {
            return [
                'error' => [
                    'error_code' => 1,
                    'error_msg' => "Invalid method format: {$method}. Expected format: 'api.method'"
                ]
            ];
        }
        
        [$apiName, $apiMethod] = $parts;
        
        try {
            // Определяем API класс на основе имени метода
            $apiObject = self::getApiObject($adapter, $apiName);
            if (!$apiObject) {
                return [
                    'error' => [
                        'error_code' => 1,
                        'error_msg' => "Unknown API: {$apiName}"
                    ]
                ];
            }
            
            // Вызываем метод через SDK
            $result = call_user_func([$apiObject, $apiMethod], $token, $params);
            
            return [
                'response' => $result
            ];
        } catch (\TypeError $e) {
            // Обрабатываем ошибки типа (например, когда SDK пытается создать VKApiException с неправильными параметрами)
            // Это может произойти, если API класс не найден в SDK
            return [
                'error' => [
                    'error_code' => 1,
                    'error_msg' => "API method '{$method}' is not supported by SDK: " . $e->getMessage()
                ]
            ];
        } catch (\VK\Exceptions\VKApiException $e) {
            // Преобразуем исключение SDK в формат ошибки
            return [
                'error' => [
                    'error_code' => $e->getCode() ?: 1,
                    'error_msg' => $e->getMessage()
                ]
            ];
        } catch (\VK\Exceptions\VKClientException $e) {
            // Ошибка клиента (сеть и т.д.)
            return [
                'error' => [
                    'error_code' => 1,
                    'error_msg' => 'VK Client Error: ' . $e->getMessage()
                ]
            ];
        } catch (\Exception $e) {
            return [
                'error' => [
                    'error_code' => 1,
                    'error_msg' => 'Unexpected error: ' . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Get API object from adapter based on API name
     * 
     * @param VkSdkAdapter $adapter
     * @param string $apiName
     * @return object|null
     */
    private static function getApiObject(VkSdkAdapter $adapter, string $apiName): ?object
    {
        $apiMap = [
            'users' => 'users',
            'wall' => 'wall',
            'groups' => 'groups',
            'photos' => 'photos',
            // 'audio' => 'audio', // SDK не поддерживает audio API
            'account' => 'account',
            'utils' => 'utils',
            'stats' => 'stats',
        ];
        
        if (!isset($apiMap[$apiName])) {
            return null;
        }
        
        try {
            $method = $apiMap[$apiName];
            return $adapter->$method();
        } catch (\TypeError $e) {
            // Обрабатываем случай, когда метод не поддерживается SDK
            return null;
        } catch (\Exception $e) {
            // Обрабатываем другие исключения
            return null;
        }
    }
    
    /**
     * Check if token is valid
     * 
     * @return bool True if token is valid, false otherwise
     */
    public static function isTokenValid(): bool
    {
        try {
            $adapter = self::getAdapter();
            $result = $adapter->execute(function() use ($adapter) {
                return $adapter->users()->get(
                    $adapter->getToken(),
                    []
                );
            }, "checking token validity");
            
            return is_array($result) && !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get current user info
     * 
     * @param array $fields Additional fields to return (e.g., ['screen_name'])
     * @return array|null User data or null if not available
     */
    public static function getCurrentUser(array $fields = []): ?array
    {
        try {
            $adapter = self::getAdapter();
            $params = [];
            if (!empty($fields)) {
                // SDK ожидает массив полей
                $params['fields'] = $fields;
            }
            
            $result = $adapter->execute(function() use ($adapter, $params) {
                return $adapter->users()->get(
                    $adapter->getToken(),
                    $params
                );
            }, "getting current user");
            
            if (!is_array($result) || empty($result)) {
                return null;
            }
            
            $user = $result[0] ?? null;
            if (!$user || !is_array($user)) {
                return null;
            }
            
            return [
                'id' => $user['id'] ?? null,
                'first_name' => $user['first_name'] ?? null,
                'last_name' => $user['last_name'] ?? null,
                'screen_name' => $user['screen_name'] ?? null,
            ];
        } catch (\Exception $e) {
            return null;
        }
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


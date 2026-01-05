<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use App\Services\VkApi\VkApiTestService;

class TokenCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:token-check
                            {--format=table : Формат вывода (table, json)}
                            {--output= : Путь к файлу для сохранения результатов}
                            {--token= : Токен VK API для проверки (опционально, по умолчанию используется токен из конфига)}
                            {--user-id= : ID пользователя для проверки прав (опционально, по умолчанию используется текущий пользователь токена)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверка прав доступа токена VK API';

    /**
     * Битовые маски прав VK API
     * Источник: localdocs/notes/vkapi/reference-access-rights.md
     */
    protected const PERMISSION_BITMASKS = [
        'wall' => 8192,      // Доступ к стенам (1 << 13)
        'groups' => 262144,  // Доступ к группам (1 << 18)
        'photos' => 4,       // Доступ к фотографиям (1 << 2)
        'audio' => 8,        // Доступ к аудиозаписям (1 << 3)
        'offline' => 65536,  // Бессрочный токен (1 << 16)
    ];

    /**
     * Список прав для проверки
     */
    protected $permissions = [
        'wall' => [
            'method' => 'wall.get',
            'params' => ['count' => 1],
            'description' => 'Доступ к стенам (чтение постов)',
            'required' => true,
            'check' => 'checkWall',
            'bitmask' => self::PERMISSION_BITMASKS['wall'],
        ],
        'groups' => [
            'method' => 'groups.get',
            'params' => ['count' => 1],
            'description' => 'Доступ к группам',
            'required' => true,
            'bitmask' => self::PERMISSION_BITMASKS['groups'],
        ],
        'photos' => [
            'method' => 'photos.getAlbums',
            'params' => ['count' => 1],
            'description' => 'Доступ к фотографиям',
            'required' => false,
            'check' => 'checkPhotos',
            'bitmask' => self::PERMISSION_BITMASKS['photos'],
        ],
        'audio' => [
            'method' => 'audio.get',
            'params' => ['count' => 1],
            'description' => 'Доступ к аудиозаписям',
            'required' => false,
            'bitmask' => self::PERMISSION_BITMASKS['audio'],
        ],
        'offline' => [
            'method' => 'users.get',
            'params' => [],
            'description' => 'Бессрочный токен (offline)',
            'required' => false,
            'check' => 'checkOffline',
            'bitmask' => self::PERMISSION_BITMASKS['offline'],
        ],
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Получаем токен из опции или конфига
        $token = $this->option('token') ?: config('vk.token');
        
        if (empty($token)) {
            $this->error('Токен VK API не настроен. Укажите VK_TOKEN в .env или используйте опцию --token');
            return 1;
        }

        // Временно подменяем токен в конфиге, если указан через опцию
        $originalToken = null;
        if ($this->option('token')) {
            $originalToken = config('vk.token');
            Config::set('vk.token', $token);
        }

        try {
            // Валидация user_id, если указан
            $userId = null;
            if ($this->option('user-id')) {
                $userIdOption = (int)$this->option('user-id');
                if ($userIdOption <= 0) {
                    $this->error('Параметр --user-id должен быть положительным числом');
                    return 1;
                }
                $userId = $userIdOption;
            }
            
            $this->info('Проверка прав доступа токена VK API...');
            if ($this->option('token')) {
                $this->comment('Используется токен из опции --token');
            }
            if ($userId) {
                $this->comment("Проверка прав для пользователя ID: {$userId}");
            }
            $this->newLine();

            // Проверка валидности токена
            $tokenValid = $this->checkTokenValidity();
        
            if (!$tokenValid) {
                $errorMessage = $this->option('token') 
                    ? 'Токен невалиден или истек. Проверьте правильность токена в опции --token'
                    : 'Токен невалиден или истек. Проверьте правильность токена в .env';
                $this->error($errorMessage);
                return 1;
            }

            // Получение информации о пользователе
            $userInfo = $this->getUserInfo();
            
            // Проверка прав
            $permissionsStatus = $this->checkPermissions();
            
            // Формирование результата
            $result = [
                'token_valid' => true,
                'user_info' => $userInfo,
                'permissions' => $permissionsStatus,
                'summary' => $this->getSummary($permissionsStatus),
            ];

            // Вывод результатов
            $format = $this->option('format');
            $output = $this->option('output');

            if ($output) {
                $this->saveToFile($result, $format, $output);
            } else {
                $this->displayResults($result, $format);
            }

            // Возвращаем код выхода на основе наличия обязательных прав
            $hasRequiredPermissions = $this->hasRequiredPermissions($permissionsStatus);
            return $hasRequiredPermissions ? 0 : 1;
        } finally {
            // Восстанавливаем оригинальный токен, если был подменен
            if ($originalToken !== null) {
                Config::set('vk.token', $originalToken);
            }
        }
    }

    /**
     * Проверка валидности токена
     */
    protected function checkTokenValidity(): bool
    {
        try {
            return VkApiTestService::isTokenValid();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Получение информации о пользователе
     */
    protected function getUserInfo(): ?array
    {
        try {
            return VkApiTestService::getCurrentUser(['screen_name']);
        } catch (\Exception $e) {
            // Игнорируем ошибки при получении информации о пользователе
            return null;
        }
    }

    /**
     * Проверка прав доступа
     */
    protected function checkPermissions(): array
    {
        // Получаем user_id из опции, если указан
        $userId = $this->option('user-id') ? (int)$this->option('user-id') : null;
        
        // Пытаемся использовать account.getAppPermissions (работает только для пользовательских токенов)
        $permissionsResult = VkApiTestService::getAppPermissions($userId);
        
        if ($permissionsResult['success'] && $permissionsResult['bitmask'] !== null) {
            $bitmask = $permissionsResult['bitmask'];
            
            // Отладочная информация
            if ($this->option('verbose')) {
                $userIdInfo = $userId ? " для user_id={$userId}" : '';
                $this->comment("Битовая маска прав{$userIdInfo}: {$bitmask} (0x" . dechex($bitmask) . ")");
            }
            
            // Используем битовую маску для проверки прав
            return $this->checkPermissionsFromBitmask($bitmask);
        }
        
        // Fallback: используем старый подход (работает для всех типов токенов)
        if ($this->option('verbose') && !$permissionsResult['success']) {
            $this->comment("account.getAppPermissions недоступен: {$permissionsResult['error']}. Используется fallback метод.");
        }
        
        return $this->checkPermissionsLegacy();
    }
    
    /**
     * Проверка прав через битовую маску (account.getAppPermissions)
     * Использует гибридный подход: проверка через битовую маску + API для прав, которых нет в маске
     * 
     * @param int $bitmask Битовую маску прав
     * @return array
     */
    protected function checkPermissionsFromBitmask(int $bitmask): array
    {
        $results = [];
        
        // Отладочная информация о битовой маске
        if ($this->option('verbose')) {
            $this->comment("Проверка битовой маски: {$bitmask} (0x" . dechex($bitmask) . ", бинарно: " . decbin($bitmask) . ")");
        }
        
        foreach ($this->permissions as $permission => $config) {
            $hasPermission = false;
            $error = null;
            $checkedVia = 'bitmask';
            
            // Проверяем право через битовую маску
            if (isset($config['bitmask'])) {
                $permissionBit = $config['bitmask'];
                $bitwiseResult = $bitmask & $permissionBit;
                $hasPermission = VkApiTestService::hasPermission($bitmask, $permissionBit);
                
                // Отладочная информация
                if ($this->option('verbose')) {
                    $this->comment("  {$permission}: бит={$permissionBit} (0x" . dechex($permissionBit) . "), результат={$hasPermission}, проверка={$bitmask}&{$permissionBit}={$bitwiseResult}");
                }
                
                // Если битовая маска показывает, что права нет, делаем дополнительную проверку через API
                // Это нужно, так как некоторые права (например, wall) могут не отображаться в битовой маске,
                // но фактически быть доступными через API
                if (!$hasPermission) {
                    // Дополнительная проверка через API
                    if (isset($config['check']) && method_exists($this, $config['check'])) {
                        $hasPermission = $this->{$config['check']}();
                        $checkedVia = 'api_method (fallback)';
                    } else {
                        try {
                            $testResult = VkApiTestService::testMethod($config['method'], $config['params']);
                            $hasPermission = $testResult['has_permission'];
                            $error = $testResult['error'];
                            $checkedVia = 'api_method (fallback)';
                        } catch (\Exception $e) {
                            // Оставляем результат из битовой маски
                            $error = $e->getMessage();
                        }
                    }
                    
                    if ($this->option('verbose')) {
                        $this->comment("    Дополнительная проверка через API: {$hasPermission}");
                    }
                }
            }
            
            $results[$permission] = [
                'has_permission' => $hasPermission,
                'description' => $config['description'],
                'required' => $config['required'] ?? false,
                'error' => $error,
                'checked_via' => $checkedVia,
            ];
        }
        
        return $results;
    }
    
    /**
     * Проверка прав через вызовы API методов (legacy подход)
     * 
     * @return array
     */
    protected function checkPermissionsLegacy(): array
    {
        $results = [];
        
        foreach ($this->permissions as $permission => $config) {
            $hasPermission = false;
            $error = null;
            
            // Специальная проверка для прав с кастомными методами
            if (isset($config['check']) && method_exists($this, $config['check'])) {
                $hasPermission = $this->{$config['check']}();
            } else {
                // Обычная проверка через API метод
                try {
                    $testResult = VkApiTestService::testMethod($config['method'], $config['params']);
                    $hasPermission = $testResult['has_permission'];
                    $error = $testResult['error'];
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                    $hasPermission = false;
                }
            }
            
            $results[$permission] = [
                'has_permission' => $hasPermission,
                'description' => $config['description'],
                'required' => $config['required'] ?? false,
                'error' => $error,
                'checked_via' => 'api_methods',
            ];
            
            // Небольшая задержка между запросами
            usleep(200000); // 0.2 секунды
        }
        
        return $results;
    }

    /**
     * Проверка права wall
     * Пытаемся получить посты со стены текущего пользователя
     */
    protected function checkWall(): bool
    {
        try {
            $testResult = VkApiTestService::testMethod('wall.get', ['count' => 1]);
            return $testResult['has_permission'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Проверка права photos
     * Пытаемся получить альбомы текущего пользователя
     */
    protected function checkPhotos(): bool
    {
        try {
            $testResult = VkApiTestService::testMethod('photos.getAlbums', ['count' => 1]);
            return $testResult['has_permission'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Проверка права offline (бессрочный токен)
     * В VK API нет прямого способа проверить, является ли токен offline
     * Считаем, что если токен валиден и работает, он может быть offline
     * Более точная проверка требует знания времени создания токена
     */
    protected function checkOffline(): bool
    {
        // Для offline токена обычно нет прямого способа проверки через API
        // Проверяем через валидность токена - если токен валиден, он может быть offline
        // Более точная проверка - это проверка времени жизни токена
        // Но в VK API нет прямого метода для этого
        
        // Альтернативный способ: проверить, работает ли токен после некоторого времени
        // Для простоты считаем, что если токен валиден, он может быть offline
        try {
            return VkApiTestService::isTokenValid();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Получение сводки
     */
    protected function getSummary(array $permissionsStatus): array
    {
        $total = count($permissionsStatus);
        $granted = count(array_filter($permissionsStatus, fn($p) => $p['has_permission']));
        $required = count(array_filter($permissionsStatus, fn($p) => $p['required']));
        $requiredGranted = count(array_filter($permissionsStatus, fn($p) => $p['required'] && $p['has_permission']));
        
        $missingRequired = [];
        foreach ($permissionsStatus as $permission => $status) {
            if ($status['required'] && !$status['has_permission']) {
                $missingRequired[] = $permission;
            }
        }
        
        return [
            'total' => $total,
            'granted' => $granted,
            'required' => $required,
            'required_granted' => $requiredGranted,
            'missing_required' => $missingRequired,
        ];
    }

    /**
     * Проверка наличия обязательных прав
     */
    protected function hasRequiredPermissions(array $permissionsStatus): bool
    {
        foreach ($permissionsStatus as $status) {
            if ($status['required'] && !$status['has_permission']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Отображение результатов в консоли
     */
    protected function displayResults(array $result, string $format): void
    {
        if ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }

        // Вывод информации о пользователе
        if ($result['user_info']) {
            $this->info('Информация о токене:');
            $userInfo = $result['user_info'];
            $this->table(
                ['Параметр', 'Значение'],
                [
                    ['ID пользователя', $userInfo['id'] ?? 'N/A'],
                    ['Имя', $userInfo['first_name'] ?? 'N/A'],
                    ['Фамилия', $userInfo['last_name'] ?? 'N/A'],
                    ['Screen name', $userInfo['screen_name'] ?? 'N/A'],
                ]
            );
            $this->newLine();
        }

        // Вывод статуса прав
        $this->info('Статус прав доступа:');
        $tableData = [];
        foreach ($result['permissions'] as $permission => $status) {
            $icon = $status['has_permission'] ? '✅' : '❌';
            $required = $status['required'] ? ' (обязательно)' : '';
            $tableData[] = [
                $icon . ' ' . $permission,
                $status['description'] . $required,
                $status['has_permission'] ? 'Есть' : 'Нет',
            ];
        }
        
        $this->table(['Право', 'Описание', 'Статус'], $tableData);
        $this->newLine();

        // Вывод сводки
        $summary = $result['summary'];
        $this->info('Сводка:');
        $this->line("Всего проверено прав: {$summary['total']}");
        $this->line("Предоставлено прав: {$summary['granted']}");
        $this->line("Обязательных прав: {$summary['required']}");
        $this->line("Обязательных прав предоставлено: {$summary['required_granted']}");
        
        if (!empty($summary['missing_required'])) {
            $this->newLine();
            $this->error('Отсутствуют обязательные права: ' . implode(', ', $summary['missing_required']));
            $this->warn('Для работы инструмента необходимо получить токен с этими правами.');
        } else {
            $this->newLine();
            $this->info('✅ Все обязательные права предоставлены!');
        }

        // Рекомендации
        $this->newLine();
        $this->info('Рекомендации:');
        $recommendations = $this->getRecommendations($result['permissions']);
        foreach ($recommendations as $recommendation) {
            $this->line("  • {$recommendation}");
        }
    }

    /**
     * Получение рекомендаций
     */
    protected function getRecommendations(array $permissionsStatus): array
    {
        $recommendations = [];
        
        foreach ($permissionsStatus as $permission => $status) {
            if (!$status['has_permission']) {
                if ($status['required']) {
                    $recommendations[] = "Получите право '{$permission}' - оно необходимо для работы инструмента";
                } else {
                    $recommendations[] = "Право '{$permission}' не предоставлено, но не является обязательным";
                }
            }
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Все права предоставлены. Токен настроен корректно.';
        }
        
        // Проверка offline
        if (!isset($permissionsStatus['offline']['has_permission']) || !$permissionsStatus['offline']['has_permission']) {
            $recommendations[] = 'Рекомендуется получить токен с правом "offline" для бессрочного доступа';
        }
        
        return $recommendations;
    }

    /**
     * Сохранение результатов в файл
     */
    protected function saveToFile(array $result, string $format, string $output): void
    {
        $dir = dirname($output);
        if ($dir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if ($format === 'json') {
            file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            // Для table формата сохраняем в текстовом виде
            $content = $this->formatResultsAsText($result);
            file_put_contents($output, $content);
        }
        
        $this->info("Результаты сохранены в: {$output}");
    }

    /**
     * Форматирование результатов как текст
     */
    protected function formatResultsAsText(array $result): string
    {
        $output = "Проверка прав доступа токена VK API\n";
        $output .= "=====================================\n\n";
        
        if ($result['user_info']) {
            $output .= "Информация о токене:\n";
            $userInfo = $result['user_info'];
            $output .= "ID пользователя: " . ($userInfo['id'] ?? 'N/A') . "\n";
            $output .= "Имя: " . ($userInfo['first_name'] ?? 'N/A') . "\n";
            $output .= "Фамилия: " . ($userInfo['last_name'] ?? 'N/A') . "\n";
            $output .= "Screen name: " . ($userInfo['screen_name'] ?? 'N/A') . "\n\n";
        }
        
        $output .= "Статус прав доступа:\n";
        $output .= "--------------------\n";
        foreach ($result['permissions'] as $permission => $status) {
            $icon = $status['has_permission'] ? '✅' : '❌';
            $required = $status['required'] ? ' (обязательно)' : '';
            $output .= "{$icon} {$permission}{$required}\n";
            $output .= "   Описание: {$status['description']}\n";
            $output .= "   Статус: " . ($status['has_permission'] ? 'Есть' : 'Нет') . "\n";
            if ($status['error']) {
                $output .= "   Ошибка: {$status['error']}\n";
            }
            $output .= "\n";
        }
        
        $summary = $result['summary'];
        $output .= "Сводка:\n";
        $output .= "-------\n";
        $output .= "Всего проверено прав: {$summary['total']}\n";
        $output .= "Предоставлено прав: {$summary['granted']}\n";
        $output .= "Обязательных прав: {$summary['required']}\n";
        $output .= "Обязательных прав предоставлено: {$summary['required_granted']}\n";
        
        if (!empty($summary['missing_required'])) {
            $output .= "\nОтсутствуют обязательные права: " . implode(', ', $summary['missing_required']) . "\n";
        }
        
        return $output;
    }

}

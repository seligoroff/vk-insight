<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use VK\OAuth\VKOAuth;
use VK\OAuth\VKOAuthResponseType;
use VK\OAuth\VKOAuthDisplay;
use VK\OAuth\Scopes\VKOAuthGroupScope;

class TokenGetGroup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:token-get-group
                            {--client-id= : ID приложения VK (Application ID)}
                            {--client-secret= : Секретный ключ приложения}
                            {--redirect-uri= : URL для перенаправления после авторизации}
                            {--scopes= : Права доступа (через запятую, например: photos,messages)}
                            {--implicit : Использовать implicit flow (токен в URL, не требует client_secret)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение community access token через OAuth 2.0';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Получение community access token через OAuth 2.0');
        $this->newLine();

        // Получаем параметры
        $clientId = $this->option('client-id') ?: $this->ask('Введите Application ID (client_id)');
        $redirectUri = $this->option('redirect-uri') ?: $this->ask('Введите redirect_uri (например, https://oauth.vk.com/blank.html)');
        $scopesInput = $this->option('scopes') ?: $this->ask('Введите права доступа (через запятую, например: photos,messages)', 'photos');
        $useImplicit = $this->option('implicit');
        $clientSecret = null;

        if (!$useImplicit) {
            $clientSecret = $this->option('client-secret') ?: $this->secret('Введите Client Secret (client_secret)');
        }

        if (empty($clientId) || empty($redirectUri)) {
            $this->error('client_id и redirect_uri обязательны');
            return 1;
        }

        if (!$useImplicit && empty($clientSecret)) {
            $this->error('client_secret обязателен для authorization code flow');
            return 1;
        }

        $clientId = (int)$clientId;
        if ($clientId <= 0) {
            $this->error('client_id должен быть положительным числом');
            return 1;
        }

        // Парсим scopes
        $scopesArray = array_map('trim', explode(',', $scopesInput));
        $scopes = [];
        foreach ($scopesArray as $scope) {
            $scope = trim($scope);
            if (!empty($scope)) {
                $scopeConstant = $this->mapScopeToConstant($scope);
                if ($scopeConstant !== null) {
                    $scopes[] = $scopeConstant;
                } else {
                    $this->warn("Неизвестный scope: {$scope}");
                }
            }
        }

        if (empty($scopes)) {
            $this->error('Не указаны допустимые права доступа');
            return 1;
        }

        try {
            // Генерируем state
            $state = bin2hex(random_bytes(16));

            // Создаем OAuth клиент
            $oauth = new VKOAuth();

            // Выбираем response type
            $responseType = $useImplicit ? VKOAuthResponseType::TOKEN : VKOAuthResponseType::CODE;

            // Получаем URL для авторизации
            $authUrl = $oauth->getAuthorizeUrl(
                $responseType,
                $clientId,
                $redirectUri,
                VKOAuthDisplay::PAGE,
                $scopes,
                $state,
                null // group_ids - будет выбран пользователем
            );

            $this->info('Выполните следующие шаги:');
            $this->newLine();
            $this->line('1. Откройте в браузере следующую ссылку:');
            $this->line($authUrl);
            $this->newLine();
            $this->line('2. Войдите в аккаунт VK и выберите сообщества, для которых разрешить доступ');
            
            if ($useImplicit) {
                $this->line('3. После авторизации вас перенаправит на redirect_uri с токенами в URL (фрагмент после #)');
                $this->line('4. Скопируйте полный URL из адресной строки браузера');
                $this->newLine();

                $callbackUrl = $this->ask('Вставьте URL после перенаправления');

                if (empty($callbackUrl)) {
                    $this->error('URL не указан');
                    return 1;
                }

                // Парсим токены из фрагмента URL
                $parsedUrl = parse_url($callbackUrl);
                if (empty($parsedUrl['fragment'])) {
                    $this->error('URL не содержит фрагмента с токенами');
                    return 1;
                }

                parse_str($parsedUrl['fragment'], $fragmentParams);

                // VK возвращает токены для каждого сообщества отдельно
                // Ключи в ответе имеют формат: access_token_XXXXXX, где XXXXXX - ID сообщества
                $accessTokens = [];
                foreach ($fragmentParams as $key => $value) {
                    if (strpos($key, 'access_token_') === 0) {
                        $groupId = str_replace('access_token_', '', $key);
                        $accessTokens[$groupId] = $value;
                    }
                }

                if (empty($accessTokens)) {
                    $this->error('В URL отсутствуют токены доступа');
                    return 1;
                }

                // Выводим результат
                $this->newLine();
                $this->info('✅ Токены успешно получены!');
                $this->newLine();
                $this->line('Токены для сообществ:');
                foreach ($accessTokens as $groupId => $token) {
                    $this->newLine();
                    $this->line("Группа ID: {$groupId}");
                    $this->line("Access Token: {$token}");
                }

                if (isset($fragmentParams['expires_in'])) {
                    $this->newLine();
                    $this->line('Срок действия: ' . $fragmentParams['expires_in'] . ' секунд');
                }

                $this->newLine();
                $this->info('Добавьте токен(ы) в .env:');
                foreach ($accessTokens as $groupId => $token) {
                    $this->line("VK_TOKEN_{$groupId}={$token}");
                }
                if (count($accessTokens) === 1) {
                    $this->line('Или используйте основной VK_TOKEN для первой группы');
                }
            } else {
                $this->line('3. После авторизации вас перенаправит на redirect_uri с параметром code в URL');
                $this->line('4. Скопируйте полный URL из адресной строки браузера');
                $this->newLine();

                $callbackUrl = $this->ask('Вставьте URL после перенаправления');

                if (empty($callbackUrl)) {
                    $this->error('URL не указан');
                    return 1;
                }

                // Парсим код из URL
                $parsedUrl = parse_url($callbackUrl);
                if (empty($parsedUrl['query'])) {
                    $this->error('URL не содержит параметров запроса');
                    return 1;
                }

                parse_str($parsedUrl['query'], $queryParams);

                if (empty($queryParams['code'])) {
                    $this->error('В URL отсутствует параметр code');
                    return 1;
                }

                if (!empty($queryParams['state']) && $queryParams['state'] !== $state) {
                    $this->error('Неверный state. Возможна попытка атаки.');
                    return 1;
                }

                // Получаем токен
                $this->info('Получение токена...');
                $tokenResponse = $oauth->getAccessToken($clientId, $clientSecret, $redirectUri, $queryParams['code']);

                // Выводим результат
                $this->newLine();
                $this->info('✅ Токен успешно получен!');
                $this->newLine();

                // VK возвращает токены для каждого сообщества отдельно
                $accessTokens = [];
                foreach ($tokenResponse as $key => $value) {
                    if (strpos($key, 'access_token_') === 0) {
                        $groupId = str_replace('access_token_', '', $key);
                        $accessTokens[$groupId] = $value;
                    }
                }

                if (empty($accessTokens)) {
                    // Если формат неожиданный, выводим весь ответ
                    $this->line('Access Token:');
                    $this->line(json_encode($tokenResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                } else {
                    $this->line('Токены для сообществ:');
                    foreach ($accessTokens as $groupId => $token) {
                        $this->newLine();
                        $this->line("Группа ID: {$groupId}");
                        $this->line("Access Token: {$token}");
                    }
                }

                if (isset($tokenResponse['expires_in'])) {
                    $this->newLine();
                    $this->line('Срок действия: ' . $tokenResponse['expires_in'] . ' секунд');
                }

                $this->newLine();
                $this->info('Добавьте токен(ы) в .env:');
                foreach ($accessTokens as $groupId => $token) {
                    $this->line("VK_TOKEN_{$groupId}={$token}");
                }
                if (count($accessTokens) === 1) {
                    $this->line('Или используйте основной VK_TOKEN для первой группы');
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Ошибка при получении токена: ' . $e->getMessage());
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return 1;
        }
    }

    /**
     * Преобразует строковый scope в константу класса VKOAuthGroupScope
     *
     * @param string $scope
     * @return int|null
     */
    protected function mapScopeToConstant(string $scope): ?int
    {
        $scopeMap = [
            'photos' => VKOAuthGroupScope::PHOTOS,
            'app_widget' => VKOAuthGroupScope::APP_WIDGET,
            'messages' => VKOAuthGroupScope::MESSAGES,
            'docs' => VKOAuthGroupScope::DOCS,
            'manage' => VKOAuthGroupScope::MANAGE,
        ];

        return $scopeMap[strtolower($scope)] ?? null;
    }
}

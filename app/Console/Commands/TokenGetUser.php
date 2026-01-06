<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use VK\OAuth\VKOAuth;
use VK\OAuth\VKOAuthResponseType;
use VK\OAuth\VKOAuthDisplay;
use VK\OAuth\Scopes\VKOAuthUserScope;

class TokenGetUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vk:token-get-user
                            {--client-id= : ID приложения VK (Application ID)}
                            {--redirect-uri= : URL для перенаправления после авторизации}
                            {--scopes=wall,groups,photos,stats : Права доступа (через запятую)}
                            {--implicit : Использовать implicit flow (токен в URL, не требует client_secret)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение user access token через OAuth 2.0';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Получение user access token через OAuth 2.0');
        $this->newLine();

        // Получаем параметры
        $clientId = $this->option('client-id') ?: $this->ask('Введите Application ID (client_id)');
        $redirectUri = $this->option('redirect-uri') ?: $this->ask('Введите redirect_uri (например, https://oauth.vk.com/blank.html)');
        $scopesInput = $this->option('scopes') ?: $this->ask('Введите права доступа (через запятую)', 'wall,groups,photos,stats');
        $useImplicit = $this->option('implicit');

        if (empty($clientId) || empty($redirectUri)) {
            $this->error('client_id и redirect_uri обязательны');
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
                $state
            );

            $this->info('Выполните следующие шаги:');
            $this->newLine();
            $this->line('1. Откройте в браузере следующую ссылку:');
            $this->line($authUrl);
            $this->newLine();
            $this->line('2. Войдите в аккаунт VK и разрешите доступ приложению');
            
            if ($useImplicit) {
                $this->line('3. После авторизации вас перенаправит на redirect_uri с токеном в URL (фрагмент после #)');
                $this->line('4. Скопируйте полный URL из адресной строки браузера');
                $this->newLine();

                $callbackUrl = $this->ask('Вставьте URL после перенаправления');

                if (empty($callbackUrl)) {
                    $this->error('URL не указан');
                    return 1;
                }

                // Парсим токен из фрагмента URL
                $parsedUrl = parse_url($callbackUrl);
                if (empty($parsedUrl['fragment'])) {
                    $this->error('URL не содержит фрагмента с токеном');
                    return 1;
                }

                parse_str($parsedUrl['fragment'], $fragmentParams);

                if (empty($fragmentParams['access_token'])) {
                    $this->error('В URL отсутствует access_token');
                    return 1;
                }

                if (!empty($fragmentParams['state']) && $fragmentParams['state'] !== $state) {
                    $this->error('Неверный state. Возможна попытка атаки.');
                    return 1;
                }

                $accessToken = $fragmentParams['access_token'];

                // Выводим результат
                $this->newLine();
                $this->info('✅ Токен успешно получен!');
                $this->newLine();
                $this->line('Access Token:');
                $this->line($accessToken);
                $this->newLine();

                if (isset($fragmentParams['expires_in'])) {
                    $this->line('Срок действия: ' . $fragmentParams['expires_in'] . ' секунд');
                    $this->newLine();
                }

                if (isset($fragmentParams['user_id'])) {
                    $this->line('User ID: ' . $fragmentParams['user_id']);
                    $this->newLine();
                }

                $this->info('Добавьте токен в .env:');
                $this->line('VK_TOKEN=' . $accessToken);
            } else {
                $this->line('3. После авторизации вас перенаправит на redirect_uri с параметром code в URL');
                $this->line('4. Скопируйте полный URL из адресной строки браузера');
                $this->newLine();
                $this->warn('Для получения токена через authorization code flow необходим client_secret.');
                $this->warn('Используйте опцию --implicit для получения токена без client_secret.');
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

                $this->newLine();
                $this->info('Код авторизации получен: ' . $queryParams['code']);
                $this->warn('Для получения токена необходим client_secret.');
                $this->warn('Используйте команду с опцией --implicit или получите токен вручную.');
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
     * Преобразует строковый scope в константу класса VKOAuthUserScope
     *
     * @param string $scope
     * @return int|null
     */
    protected function mapScopeToConstant(string $scope): ?int
    {
        $scopeMap = [
            'wall' => VKOAuthUserScope::WALL,
            'groups' => VKOAuthUserScope::GROUPS,
            'photos' => VKOAuthUserScope::PHOTOS,
            'audio' => VKOAuthUserScope::AUDIO,
            'video' => VKOAuthUserScope::VIDEO,
            'docs' => VKOAuthUserScope::DOCS,
            'friends' => VKOAuthUserScope::FRIENDS,
            'stats' => VKOAuthUserScope::STATS,
            'pages' => VKOAuthUserScope::PAGES,
            'notes' => VKOAuthUserScope::NOTES,
            'ads' => VKOAuthUserScope::ADS,
            'market' => VKOAuthUserScope::MARKET,
            'notifications' => VKOAuthUserScope::NOTIFICATIONS,
            'status' => VKOAuthUserScope::STATUS,
            'offline' => VKOAuthUserScope::OFFLINE,
            'email' => VKOAuthUserScope::EMAIL,
            'messages' => VKOAuthUserScope::MESSAGES,
            'notify' => VKOAuthUserScope::NOTIFY,
            'link' => VKOAuthUserScope::LINK,
        ];

        return $scopeMap[strtolower($scope)] ?? null;
    }
}

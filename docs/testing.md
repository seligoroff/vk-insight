# Настройка тестов

## Конфигурация базы данных для тестов

Приложение использует отдельный файл конфигурации `.env.testing` для тестов. Это позволяет использовать отдельную тестовую базу данных, не затрагивая основную.

## Настройка .env.testing

Создайте файл `.env.testing` в папке `tests/` или в корне проекта. Приложение сначала ищет файл в `tests/.env.testing`, затем в корне проекта.

Рекомендуемое расположение: `tests/.env.testing`

Пример настройки файла:

```env
APP_ENV=testing
APP_DEBUG=true

# Настройки базы данных для тестов
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vk_utils_test
DB_USERNAME=your_test_username
DB_PASSWORD=your_test_password

# Настройки VK API для тестов (можно использовать тестовые значения)
VK_TOKEN=test_token_for_unit_tests
VK_API_VERSION=5.122
VK_VERIFY_SSL=false
VK_ACCOUNT_BASE_URL=https://vk.com
```

## Создание тестовой базы данных

Перед запуском тестов необходимо создать тестовую базу данных:

```bash
mysql -u root -p

CREATE DATABASE vk_utils_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Создайте пользователя для тестов (опционально)
CREATE USER 'vk_utils_test_user'@'localhost' IDENTIFIED BY 'test_password';
GRANT ALL PRIVILEGES ON vk_utils_test.* TO 'vk_utils_test_user'@'localhost';
FLUSH PRIVILEGES;
```

## Автоматическая очистка и миграции

Тестовая база данных автоматически:
- Очищается перед запуском всех тестов (один раз)
- Миграции выполняются автоматически перед первым тестом
- Все таблицы удаляются и создаются заново через `migrate:fresh`

Это обеспечивает чистую базу данных для каждого запуска тестов.

**Важно:** Миграции запускаются один раз при первом тесте. Если нужно полностью изолировать тесты, используйте трейт `RefreshDatabase` в отдельных тестах.

## Запуск тестов

```bash
# Запуск всех тестов
php artisan test

# Или через PHPUnit
vendor/bin/phpunit

# Запуск только Unit тестов
php artisan test --testsuite=Unit

# Запуск только Feature тестов
php artisan test --testsuite=Feature

# Запуск конкретного теста
php artisan test tests/Unit/Services/VkApi/VkGroupServiceTest.php
```

## Использование RefreshDatabase в тестах

Для тестов, которые требуют работы с базой данных и полной изоляции, можно использовать трейт `RefreshDatabase`:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyTest extends TestCase
{
    use RefreshDatabase;

    public function test_something_with_database()
    {
        // База данных будет автоматически очищена и миграции выполнены
        // перед каждым тестом в этом классе
    }
}
```

**Примечание:** `RefreshDatabase` запускает миграции перед каждым тестом, что может быть медленнее, чем общий подход с одним запуском миграций для всех тестов. Используйте его только когда необходимо полностью изолировать тесты друг от друга.

## Использование DatabaseTransactions

Для еще более быстрых тестов можно использовать `DatabaseTransactions`:

```php
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_something_with_database()
    {
        // Все изменения в БД будут автоматически откачены после теста
    }
}
```

Это быстрее, чем `RefreshDatabase`, так как не пересоздает таблицы, а просто откатывает транзакции.

## Рекомендации

1. **Используйте моки для внешних API** - большинство Unit тестов не требуют реальной БД
2. **Используйте `RefreshDatabase` только когда необходимо** - для большинства тестов достаточно общего подхода
3. **Держите тестовую БД отдельно** - никогда не используйте продакшн БД для тестов
4. **Проверяйте .env.testing в .gitignore** - файл уже добавлен в `.gitignore`, чтобы не попасть в репозиторий

## Устранение проблем

### Ошибка подключения к базе данных при тестах

Убедитесь, что:
- Файл `.env.testing` существует и содержит правильные настройки БД
- Тестовая база данных создана
- Пользователь имеет права доступа к базе данных

### Миграции не выполняются

Если миграции не выполняются автоматически:
- Проверьте, что в `.env.testing` указан правильный `DB_CONNECTION=mysql`
- Убедитесь, что база данных доступна
- Попробуйте запустить миграции вручную: `php artisan migrate --env=testing`

### Тесты работают медленно

Если тесты работают медленно:
- Используйте `DatabaseTransactions` вместо `RefreshDatabase` где возможно
- Используйте моки для внешних API вместо реальных запросов
- Рассмотрите возможность использования SQLite in-memory для быстрых unit-тестов

<?php

declare(strict_types=1);

namespace App;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\SessionHandler;

// PHP sessions: a cookie with a random id, and the data in a file in storage/sessions/.
class Session
{
    public function __construct(private readonly string $savePath)
    {
    }

    public function start(): void
{
    $client = new DynamoDbClient([
        'region' => 'eu-central-1',
        'version' => 'latest',
    ]);

    $handler = SessionHandler::fromClient($client, [
        'table_name' => getenv('DYNAMODB_SESSIONS_TABLE')
            ?: 'timecapsule-dev-sessions',
        'hash_key' => 'id',
        'session_lifetime_attribute' => 'expires',
        'session_lifetime' => 86400, // 24 часа
    ]);

    $handler->register();

    session_name('timecapsule_session');

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    // A new session id (the data is kept). Call it when the user logs in or out,
    // so an id that somebody else learned before is worthless ("session fixation").
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    // Flash messages are shown once, on the next rendered page.
    // $type is "success" or "error".
    public function flash(string $type, string $message): void
    {
        $messages = $this->get('flash', []);
        $messages[] = ['type' => $type, 'message' => $message];
        $this->set('flash', $messages);
    }

    public function takeFlashes(): array
    {
        if (!$this->isStarted()) {
            return [];
        }

        $messages = $this->get('flash', []);
        $this->remove('flash');
        return $messages;
    }
}

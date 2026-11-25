<?php

declare(strict_types=1);

namespace App;

use Aws\Sns\SnsClient;

class EventPublisher
{
    private SnsClient $sns;

    public function __construct(
        private readonly string $topicArn,
        string $region
    ) {
        $this->sns = new SnsClient([
            'version' => 'latest',
            'region' => $region,
        ]);
    }

    public function capsuleSealed(int $capsuleId): void
    {
        $event = [
            'version' => 1,
            'type' => 'capsule.sealed',
            'event_id' => 'capsule.sealed:' . $capsuleId,
            'capsule_id' => $capsuleId,
        ];

        $this->sns->publish([
            'TopicArn' => $this->topicArn,
            'Message' => json_encode(
                $event,
                JSON_THROW_ON_ERROR
            ),
        ]);
    }
}
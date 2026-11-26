<?php

declare(strict_types=1);

use App\ImageModeration;
use App\Models\Capsule;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Rekognition\RekognitionClient;
use Aws\Result;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkSame(mixed $expected, mixed $actual, string $scenario): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($scenario . ': ' . var_export($actual, true));
    }
}

$bytes = 'mock image bytes';
$path = tempnam(sys_get_temp_dir(), 'moderation-');

try {
    file_put_contents($path, $bytes);
    $mock = new MockHandler([
        new Result(['ModerationLabels' => []]),
        new Result(['ModerationLabels' => [
            ['Name' => 'Custom category', 'ParentName' => '', 'Confidence' => 80.0],
            ['Name' => 'Below threshold', 'Confidence' => 79.9],
        ]]),
        new AwsException('Mock access denied', new Command('DetectModerationLabels'), [
            'code' => 'AccessDeniedException',
        ]),
    ]);
    $client = new RekognitionClient([
        'version' => 'latest',
        'region' => 'eu-central-1',
        'credentials' => false,
        'handler' => $mock,
        'retries' => 0,
    ]);
    $moderation = new ImageModeration('eu-central-1', 80, $client);

    $skipped = ['status' => 'not_applicable', 'labels' => []];
    checkSame($skipped, $moderation->moderate(null, null), 'No attachment');
    foreach (['image/gif', 'image/webp', 'application/pdf'] as $mime) {
        checkSame($skipped, $moderation->moderate($path, $mime), $mime);
    }
    checkSame(null, $mock->getLastCommand(), 'Skipped attachments do not call Rekognition');

    checkSame(['status' => 'safe', 'labels' => []], $moderation->moderate($path, 'image/jpeg'), 'Safe JPEG');
    $command = $mock->getLastCommand();
    checkSame('DetectModerationLabels', $command->getName(), 'AWS operation');
    checkSame(80, $command['MinConfidence'], 'Threshold sent to AWS');
    checkSame($bytes, $command['Image']['Bytes'], 'Original bytes sent to AWS');

    $result = $moderation->moderate($path, 'image/png');
    checkSame([
        'status' => 'sensitive',
        'labels' => [['name' => 'Custom category', 'parent_name' => '', 'confidence' => 80.0]],
    ], $result, 'Sensitive PNG at the threshold');
    checkSame(['status' => 'unverified', 'labels' => []], $moderation->moderate($path, 'image/jpeg'), 'AWS failure');
    checkSame($bytes, file_get_contents($path), 'Upload stays unchanged');

    $row = [
        'id' => 1, 'user_id' => 1, 'title' => 'Test', 'message' => 'Test',
        'open_at' => '', 'is_public' => false, 'recipient_email' => null,
        'file_path' => 'test.png', 'file_name' => 'test.png', 'file_mime' => 'image/png',
        'opened_at' => null, 'created_at' => '',
        'moderation_labels' => json_encode($result['labels'], JSON_PRESERVE_ZERO_FRACTION),
    ];
    foreach (['safe' => false, 'sensitive' => true, 'unverified' => true, 'not_applicable' => false] as $status => $warn) {
        $capsule = Capsule::fromRow($row + ['moderation_status' => $status]);
        checkSame($warn, $capsule->requiresContentWarning(), 'Preview warning for ' . $status);
        checkSame($result['labels'], $capsule->moderationLabels, 'Hydrated moderation labels');
    }
} finally {
    unlink($path);
}

echo "Image moderation tests passed.\n";

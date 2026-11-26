<?php

declare(strict_types=1);

namespace App;

use Aws\Rekognition\RekognitionClient;
use RuntimeException;
use Throwable;

final class ImageModeration
{
    public function __construct(
        private readonly string $region,
        private readonly int $minConfidence = 80,
        private ?RekognitionClient $client = null,
    ) {
    }

    /** @return array{status: string, labels: list<array{name: string, parent_name: string, confidence: float}>} */
    public function moderate(?string $temporaryPath, ?string $mime): array
    {
        $started = hrtime(true);
        $status = 'not_applicable';
        $labels = [];
        $threshold = max(0, min(100, $this->minConfidence));

        if ($temporaryPath !== null && in_array($mime, ['image/jpeg', 'image/png'], true)) {
            try {
                $bytes = @file_get_contents($temporaryPath);
                if ($bytes === false) {
                    throw new RuntimeException('Unable to read uploaded image.');
                }

                $this->client ??= new RekognitionClient([
                    'version' => 'latest',
                    'region' => $this->region,
                    'retries' => ['mode' => 'standard', 'max_attempts' => 2],
                    'http' => ['connect_timeout' => 2, 'timeout' => 5],
                ]);
                $result = $this->client->detectModerationLabels([
                    'Image' => ['Bytes' => $bytes],
                    'MinConfidence' => $threshold,
                ]);

                foreach ($result['ModerationLabels'] ?? [] as $label) {
                    $confidence = (float) $label['Confidence'];
                    if ($confidence >= $threshold) {
                        $labels[] = [
                            'name' => $label['Name'],
                            'parent_name' => $label['ParentName'] ?? '',
                            'confidence' => $confidence,
                        ];
                    }
                }
                $status = $labels === [] ? 'safe' : 'sensitive';
            } catch (Throwable) {
                $status = 'unverified';
                $labels = [];
            }
        }

        error_log('Image moderation ' . json_encode([
            'status' => $status,
            'labels' => $labels,
            'min_confidence' => $threshold,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ], JSON_INVALID_UTF8_SUBSTITUTE));

        return ['status' => $status, 'labels' => $labels];
    }
}

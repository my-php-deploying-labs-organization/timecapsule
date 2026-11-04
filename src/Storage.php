<?php

declare(strict_types=1);

namespace App;

use Aws\S3\S3Client;
use RuntimeException;

class Storage
{
    private S3Client $s3;

    public function __construct(
        private readonly string $bucket,
        string $region
    ) {
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => $region,
        ]);
    }

    public function save(array $uploadedFile, string $extension): string
    {
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        $mime = mime_content_type($uploadedFile['tmp_name'])
            ?: 'application/octet-stream';

        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->key($storedName),
            'SourceFile' => $uploadedFile['tmp_name'],
            'ContentType' => $mime,
        ]);

        return $storedName;
    }

    public function delete(?string $storedName): void
    {
        if ($storedName === null || $storedName === '') {
            return;
        }

        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->key($storedName),
        ]);
    }

    public function stream(
        string $storedName,
        string $mime,
        string $downloadName,
        string $disposition
    ): void {
        if (!in_array($disposition, ['inline', 'attachment'], true)) {
            throw new RuntimeException('Invalid content disposition.');
        }

        $asciiName = preg_replace(
            '/[^A-Za-z0-9._ -]/',
            '_',
            $downloadName
        );

        $contentDisposition = sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $asciiName,
            rawurlencode($downloadName)
        );

        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->key($storedName),
            'ResponseContentType' => $mime,
            'ResponseContentDisposition' => $contentDisposition,
        ]);

        $request = $this->s3->createPresignedRequest(
            $command,
            '+5 minutes'
        );

        header('Location: ' . (string) $request->getUri(), true, 302);
        exit;
    }

    private function key(string $storedName): string
    {
        return 'uploads/' . basename($storedName);
    }
}

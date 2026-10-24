<?php

namespace Tests\Support;

use RuntimeException;

/** Optional one-process encrypted pipe; never writes source HTML or cells to disk. */
final class PrivateWorkbookBrowserCapture
{
    private ?string $key = null;

    private array $pages = [];

    public function __construct()
    {
        if (getenv('REAL_RESPONSE_BROWSER_CAPTURE') !== '1') {
            return;
        }
        $key = base64_decode((string) getenv('REAL_RESPONSE_BROWSER_KEY'), true);
        if ($key === false || strlen($key) !== 32 || ! defined('STDOUT')) {
            throw new RuntimeException('Private browser capture requires its ephemeral pipe key.');
        }
        $this->key = $key;
    }

    public function page(string $id, string $html, array $checks, string $kind): void
    {
        if ($this->key !== null) {
            $this->pages[] = compact('id', 'html', 'checks', 'kind');
        }
    }

    public function emit(): void
    {
        if ($this->key === null) {
            return;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $payload = openssl_encrypt(json_encode($this->pages, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key,
            OPENSSL_RAW_DATA, $nonce, $tag, 'certimate-real-workbook-layout-v1');
        if ($payload === false) {
            throw new RuntimeException('Private browser evidence could not be encrypted.');
        }
        fwrite(STDOUT, PHP_EOL.'REAL_WORKBOOK_BROWSER_BUNDLE:'.base64_encode($nonce.$tag.$payload).PHP_EOL);
        $this->pages = [];
        $this->key = null;
    }
}

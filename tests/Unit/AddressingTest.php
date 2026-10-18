<?php

declare(strict_types=1);

namespace GoSuccess\S3\Tests\Unit;

use GoSuccess\S3\Addressing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Addressing::class)]
final class AddressingTest extends TestCase
{
    public function testPathStyle(): void
    {
        $addressing = new Addressing('https://eu-central-2.storage.impossibleapi.net', true);

        self::assertSame('eu-central-2.storage.impossibleapi.net', $addressing->host('backups'));
        self::assertSame('/backups', $addressing->path('backups'));
        self::assertSame('/backups/a/b.txt', $addressing->path('backups', 'a/b.txt'));
        // Keys are taken literally: for S3, "/a" and "a" are different objects.
        self::assertSame('/backups//a', $addressing->path('backups', '/a'));
        self::assertSame('https://eu-central-2.storage.impossibleapi.net/backups/a%20b/%C3%BC.txt', $addressing->url('backups', 'a b/ü.txt'));
    }

    public function testVirtualHostStyle(): void
    {
        $addressing = new Addressing('https://s3.eu-central-1.amazonaws.com', false);

        self::assertSame('backups.s3.eu-central-1.amazonaws.com', $addressing->host('backups'));
        self::assertSame('/', $addressing->path('backups'));
        self::assertSame('/key.txt', $addressing->path('backups', 'key.txt'));
        self::assertSame('https://backups.s3.eu-central-1.amazonaws.com/key.txt', $addressing->url('backups', 'key.txt'));
    }

    public function testPorts(): void
    {
        self::assertSame('s3.example.test', new Addressing('https://s3.example.test:443', true)->host('b'));
        self::assertSame('s3.example.test', new Addressing('http://s3.example.test:80', true)->host('b'));
        self::assertSame('127.0.0.1:9100', new Addressing('http://127.0.0.1:9100', true)->host('b'));
        self::assertSame('http', new Addressing('http://127.0.0.1:9100', true)->scheme());
        self::assertSame('https', new Addressing('s3.example.test', true)->scheme());
    }

    public function testKeysKeepEmptySegments(): void
    {
        self::assertSame('/b///double', new Addressing('https://s3.example.test', true)->path('b', '//double'));
    }
}

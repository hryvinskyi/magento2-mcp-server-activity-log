<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Unit\Model;

use Hryvinskyi\ActivityLog\Api\ConfigInterface;
use Hryvinskyi\ActivityLog\Model\Sanitizer\Sanitizer;
use Hryvinskyi\McpServerActivityLog\Model\CallPayload;
use PHPUnit\Framework\TestCase;

class CallPayloadTest extends TestCase
{
    public function testSecretsAreMaskedAtAnyDepthBeforeTheValueIsWritten(): void
    {
        $kept = $this->payload()->of(['customer' => ['email' => 'a@b.c', 'password' => 'hunter2'], 'token' => 'x']);

        self::assertSame(
            ['customer' => ['email' => 'a@b.c', 'password' => '••••••'], 'token' => '••••••'],
            json_decode((string) $kept['text'], true)
        );
        self::assertFalse($kept['truncated']);
    }

    public function testAnObjectIsWrittenAsTheDataItHolds(): void
    {
        $kept = $this->payload()->of((object) ['sku' => 'MB01', 'api_key' => 'k']);

        self::assertSame(['sku' => 'MB01', 'api_key' => '••••••'], json_decode((string) $kept['text'], true));
    }

    public function testALargeValueIsCutOnACharacterBoundaryAndSaysSo(): void
    {
        $kept = $this->payload(16)->of(['name' => 'Zażółć gęślą jaźń']);

        self::assertTrue($kept['truncated']);
        self::assertLessThanOrEqual(16, strlen((string) $kept['text']));
        self::assertTrue(mb_check_encoding((string) $kept['text'], 'UTF-8'));
    }

    public function testNoValueKeepsNothing(): void
    {
        self::assertSame(['text' => null, 'truncated' => false], $this->payload()->of(null));
    }

    /**
     * A payload over the real sanitizer with a minimal secret list
     *
     * @param int $maxBytes
     * @return CallPayload
     */
    private function payload(int $maxBytes = 262144): CallPayload
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getSecretFields')->willReturn(['password', 'token', 'api_key']);

        return new CallPayload(new Sanitizer($config), $maxBytes);
    }
}

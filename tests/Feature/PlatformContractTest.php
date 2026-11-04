<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 契约适配层（PlatformContract 中间件）行为测试
 *
 * 覆盖规范 §8.1（请求头）与 §8.2（信封、错误码）在 DooTask 上的落地方式。
 * 这些用例**只做只读请求**，不写业务数据。
 *
 * 运行：PHP_BIN=D:/dt-run/php/php.exe npm run test
 */
class PlatformContractTest extends TestCase
{
    /** 不依赖数据库的探针路径：走 InvokeController 的 404 分支 */
    private const PROBE = '/api/project/notexist';

    public function test_request_id_is_generated_and_echoed(): void
    {
        $response = $this->getJson(self::PROBE);

        $requestId = $response->headers->get('X-Request-Id');
        $this->assertNotEmpty($requestId, '响应头缺少 X-Request-Id');
        $this->assertStringStartsWith('req_', $requestId);
        $this->assertSame($requestId, $response->json('requestId'), '响应体 requestId 与响应头不一致');
    }

    public function test_inbound_request_id_is_reused(): void
    {
        $traceId = 'trace-abc-0001';
        $response = $this->getJson(self::PROBE, ['X-Request-Id' => $traceId]);

        $this->assertSame($traceId, $response->headers->get('X-Request-Id'));
        $this->assertSame($traceId, $response->json('requestId'));
    }

    public function test_illegal_inbound_request_id_falls_back_to_new_one(): void
    {
        // 含空格，不满足 [A-Za-z0-9._:-]{8,128}
        $response = $this->getJson(self::PROBE, ['X-Request-Id' => 'bad id with spaces']);

        $requestId = $response->json('requestId');
        $this->assertNotSame('bad id with spaces', $requestId);
        $this->assertStringStartsWith('req_', (string) $requestId);
    }

    public function test_invalid_app_code_is_rejected_with_400(): void
    {
        $response = $this->getJson(self::PROBE, ['X-App-Code' => 'hacker']);

        $response->assertStatus(400);
        $this->assertSame('VALIDATION_ERROR', $response->json('code'));
        $this->assertSame('APP_CODE_INVALID', $response->json('data.reason'));
        $this->assertSame('hacker', $response->json('data.appCode'));
        // 兼容性：老字段必须还在，否则按 ret 判成败的客户端会拿到看不懂的结构
        $this->assertSame(0, $response->json('ret'));
        $this->assertIsString($response->json('msg'));
    }

    public function test_valid_app_code_is_accepted(): void
    {
        $response = $this->getJson(self::PROBE, ['X-App-Code' => 'workshop']);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('requestId'));
    }

    public function test_legacy_envelope_fields_are_preserved(): void
    {
        $response = $this->getJson(self::PROBE);

        // 既有三字段一个都不能少（前端按 ret 判断、展示 msg、读 data）
        $response->assertJsonStructure(['ret', 'msg', 'data', 'code', 'requestId']);
        $this->assertSame(0, $response->json('ret'));
    }

    public function test_unknown_method_maps_to_not_found(): void
    {
        $response = $this->getJson(self::PROBE);

        // DooTask 对"方法不存在"返回 HTTP 200 + msg 前缀 "404 not found"，
        // 状态码不可靠，中间件按消息判定为 NOT_FOUND。
        $this->assertSame('NOT_FOUND', $response->json('code'));
        $this->assertStringContainsString('404', (string) $response->json('msg'));
    }

    public function test_unauthenticated_request_maps_to_auth_required(): void
    {
        $response = $this->getJson('/api/project/lists');

        // DooTask 历史行为：HTTP 200 + ret=-1，中间件只补 code，不改状态码（DV-04）
        $response->assertStatus(200);
        $this->assertSame(-1, $response->json('ret'));
        $this->assertSame('AUTH_REQUIRED', $response->json('code'));
    }

    public function test_access_log_contains_request_id(): void
    {
        $traceId = 'trace-log-0001';
        $this->getJson(self::PROBE, ['X-Request-Id' => $traceId]);

        $logFile = storage_path('logs/platform-access.log');
        $this->assertFileExists($logFile);

        $content = (string) file_get_contents($logFile);
        $this->assertStringContainsString($traceId, $content, '访问日志未记录 requestId');

        $lines = array_values(array_filter(explode(PHP_EOL, trim($content))));
        $last = json_decode($lines[count($lines) - 1], true);
        $this->assertIsArray($last);
        foreach (['ts', 'requestId', 'appCode', 'method', 'path', 'status', 'durationMs'] as $field) {
            $this->assertArrayHasKey($field, $last, "访问日志缺字段 {$field}");
        }
        // 规范 §10.5：不得记录 token 与请求体
        $this->assertArrayNotHasKey('token', $last);
        $this->assertArrayNotHasKey('body', $last);
    }

    public function test_contract_can_be_disabled_by_env(): void
    {
        $previous = getenv('PLATFORM_CONTRACT_ENABLED');
        putenv('PLATFORM_CONTRACT_ENABLED=false');
        $_ENV['PLATFORM_CONTRACT_ENABLED'] = 'false';
        $_SERVER['PLATFORM_CONTRACT_ENABLED'] = 'false';

        try {
            $response = $this->getJson(self::PROBE);
        } finally {
            if ($previous === false) {
                putenv('PLATFORM_CONTRACT_ENABLED');
                unset($_ENV['PLATFORM_CONTRACT_ENABLED'], $_SERVER['PLATFORM_CONTRACT_ENABLED']);
            } else {
                putenv('PLATFORM_CONTRACT_ENABLED=' . $previous);
                $_ENV['PLATFORM_CONTRACT_ENABLED'] = $previous;
                $_SERVER['PLATFORM_CONTRACT_ENABLED'] = $previous;
            }
        }

        // 关闭后回到原始行为：无 code / requestId 叠加
        $this->assertNull($response->json('code'));
        $this->assertNull($response->json('requestId'));
        $this->assertSame(0, $response->json('ret'));
    }
}

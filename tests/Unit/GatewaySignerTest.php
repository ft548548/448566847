<?php

namespace Tests\Unit;

use App\Services\GatewaySigner;
use Tests\TestCase;

/**
 * L2 签名一致性测试
 *
 * 期望值来自《警院WeChat五平台_统一API网关部署报告》配套的 verify_gateway.py
 * （l2_headers()），并用 Python 与 PHP 双侧实算比对确认过逐字节相同。
 *
 * 这些用例的作用是"锁死"规范串：一旦有人重排字段、改了 query 编码方式，
 * 或把 body 从 sha256 改回原文，签名会静默失效（对端只回 40105），
 * 排查成本很高，所以必须由测试兜住。
 */
class GatewaySignerTest extends TestCase
{
    private const APP_KEY = 'jy-exam-svc';
    private const TIMESTAMP = '1793893800';
    private const NONCE = 'abc123def456';
    private const PATH = '/api/v1/learning/internal/ping';
    /** 故意包含重复键、乱序、含空格的值 */
    private const QUERY = 'b=2&a=1&c=x%20y&a=0';
    private const BODY = '{"courseId":"C001"}';
    private const SECRET = 'unit-test-secret';

    /** Python 侧 sha256(body) */
    private const BODY_DIGEST = '74ebda088361d153a3711b2dad1dbb8f7c90a614781a5e5196ef48441353ac3a';

    /** Python 侧 hmac(secret, canonical) */
    private const SIGNATURE = '6558cf2c39b9f11e2ab6f23accac6824bfa8d7627aae0f4d655821ff170deecd';

    public function test_query_is_sorted_then_encoded_like_python_urlencode(): void
    {
        // 排序键为 (name, value)：a=0 在 a=1 之前；空格编码为 +
        $this->assertSame('a=0&a=1&b=2&c=x+y', GatewaySigner::canonicalQuery(self::QUERY));
        $this->assertSame('', GatewaySigner::canonicalQuery(''));
        $this->assertSame('flag=', GatewaySigner::canonicalQuery('flag'));
        $this->assertSame('a=1&b=2', GatewaySigner::canonicalQuery('?b=2&a=1'));
    }

    public function test_gateway_canonical_string_matches_deployed_gateway(): void
    {
        $expected = implode("\n", [
            self::APP_KEY,
            self::TIMESTAMP,
            self::NONCE,
            'POST',
            self::PATH,
            'a=0&a=1&b=2&c=x+y',
            self::BODY_DIGEST,
        ]);

        $actual = GatewaySigner::callCanonicalGateway(
            self::APP_KEY,
            self::TIMESTAMP,
            self::NONCE,
            'POST',
            self::PATH,
            self::QUERY,
            self::BODY
        );

        $this->assertSame($expected, $actual);
    }

    public function test_gateway_signature_matches_reference(): void
    {
        $signature = GatewaySigner::callSign(
            self::SECRET,
            'POST',
            self::PATH,
            self::QUERY,
            self::BODY,
            self::APP_KEY,
            self::TIMESTAMP,
            self::NONCE,
            GatewaySigner::L2_VERSION_GATEWAY
        );

        $this->assertSame(self::SIGNATURE, $signature);
    }

    public function test_spec_version_keeps_four_part_canonical(): void
    {
        // V3.0 四段版必须保持不变，否则按文档实现的对接方会全部验签失败
        $expected = "POST\n" . self::PATH . "\n" . self::QUERY . "\n" . self::BODY;
        $this->assertSame($expected, GatewaySigner::callCanonical('POST', self::PATH, self::QUERY, self::BODY));

        $specSign = GatewaySigner::callSign(
            self::SECRET,
            'POST',
            self::PATH,
            self::QUERY,
            self::BODY,
            self::APP_KEY,
            self::TIMESTAMP,
            self::NONCE,
            GatewaySigner::L2_VERSION_SPEC
        );

        $this->assertSame(hash_hmac('sha256', $expected, self::SECRET), $specSign);
        // 两个版本必须产出不同签名，否则"版本"就没有意义
        $this->assertNotSame(self::SIGNATURE, $specSign);
    }

    public function test_app_key_and_timestamp_participate_in_gateway_signature(): void
    {
        $baseline = GatewaySigner::callSign(
            self::SECRET, 'POST', self::PATH, '', '', 'app-a', '100', 'n1', GatewaySigner::L2_VERSION_GATEWAY
        );

        $otherKey = GatewaySigner::callSign(
            self::SECRET, 'POST', self::PATH, '', '', 'app-b', '100', 'n1', GatewaySigner::L2_VERSION_GATEWAY
        );
        $otherTs = GatewaySigner::callSign(
            self::SECRET, 'POST', self::PATH, '', '', 'app-a', '101', 'n1', GatewaySigner::L2_VERSION_GATEWAY
        );
        $otherNonce = GatewaySigner::callSign(
            self::SECRET, 'POST', self::PATH, '', '', 'app-a', '100', 'n2', GatewaySigner::L2_VERSION_GATEWAY
        );

        $this->assertNotSame($baseline, $otherKey);
        $this->assertNotSame($baseline, $otherTs);
        $this->assertNotSame($baseline, $otherNonce);
    }

    public function test_headers_carry_all_five_l2_fields(): void
    {
        $headers = GatewaySigner::callHeaders(
            self::SECRET,
            self::APP_KEY,
            'workshop',
            'GET',
            '/api/v1/workshop/internal/ping',
            '',
            '',
            '',
            GatewaySigner::L2_VERSION_GATEWAY
        );

        foreach (['X-App-Key', 'X-Timestamp', 'X-Nonce', 'X-Sign-Alg', 'X-Signature'] as $name) {
            $this->assertArrayHasKey($name, $headers, '缺少 L2 必需头 ' . $name);
            $this->assertNotSame('', (string) $headers[$name]);
        }
        $this->assertSame('HMAC-SHA256', $headers['X-Sign-Alg']);
        // 签名头里的时间戳必须与头本身一致，否则网关按头取时间、按签名算串，必然不符
        $this->assertSame(
            $headers['X-Timestamp'],
            (string) (int) $headers['X-Timestamp']
        );
    }

    public function test_version_falls_back_to_gateway_on_unknown_value(): void
    {
        $this->assertSame(GatewaySigner::L2_VERSION_GATEWAY, GatewaySigner::l2Version('whatever'));
        $this->assertSame(GatewaySigner::L2_VERSION_SPEC, GatewaySigner::l2Version('spec'));

        config(['platform.gateway.l2_version' => 'spec']);
        $this->assertSame(GatewaySigner::L2_VERSION_SPEC, GatewaySigner::l2Version());

        config(['platform.gateway.l2_version' => 'gateway']);
        $this->assertSame(GatewaySigner::L2_VERSION_GATEWAY, GatewaySigner::l2Version());
    }
}

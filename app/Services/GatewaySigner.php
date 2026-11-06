<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * 网关签名工具（L1 入站验签 + L2 出站签名）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0：
 *   §5.5.1 可信头规范化串（网关注入、本平台校验）
 *   §5.5.2 校验结果与处置
 *   §12.4  跨平台调用的 L2 签名（method / path / query / body 四段）
 *
 * 规范化串字段顺序是契约的一部分，任何一侧改了顺序，验签必然失败。
 * 两侧都实现一遍是刻意的——网关与本平台各算一次，比对不通过即拒绝。
 */
class GatewaySigner
{
    /** §5.5.1 参与可信头签名的字段，顺序固定不可调整 */
    public const TRUST_FIELDS = [
        'X-User-Id',
        'X-User-Role',
        'X-Client-Id',
        'X-Token-Exp',
        'X-Request-Id',
        'X-Auth-Mode',
        'X-Timestamp',
    ];

    /** L2 规范串版本：V3.0 文档四段 / 已部署网关七段 */
    public const L2_VERSION_SPEC = 'spec';
    public const L2_VERSION_GATEWAY = 'gateway';

    /** 验签失败的具体原因，直接映射到响应 data.reason，便于对端排查 */
    public const REASON_SIGN_MISSING = 'SIGN_MISSING';
    public const REASON_SIGN_MISMATCH = 'SIGN_MISMATCH';
    public const REASON_CLOCK_SKEW = 'CLOCK_SKEW';
    public const REASON_TIMESTAMP_MISSING = 'TIMESTAMP_MISSING';
    public const REASON_NOT_TRUSTED_NETWORK = 'NOT_TRUSTED_NETWORK';
    public const REASON_SECRET_MISSING = 'SECRET_MISSING';
    /** 密钥存在但形态不对（带空格、被换行截断、长度过短）——属于配置错误，不是签名错误 */
    public const REASON_SECRET_MALFORMED = 'SECRET_MALFORMED';

    /**
     * 读取入站验签密钥（本平台专属，与 L2 AppSecret 无关）
     *
     * 两个来源，按优先级：
     *   1. `GATEWAY_INTERNAL_SECRET`      —— 直接注入，最常见的部署方式；
     *   2. `GATEWAY_INTERNAL_SECRET_FILE` —— 文件注入，适配容器 Secret / 600 权限挂载
     *      （网关侧 secrets.json 本身就是 600，运维更愿意挂文件而不是抄进环境变量）。
     *
     * 统一在这里读，而不是各调用点各读一次 config：
     *   - 所有来源都要 trim，否则从文件或复制粘贴带进来的尾部换行会让签名永远对不上；
     *   - 文件读失败不能静默返回空串，要有日志，否则表现成"验签总是失败"。
     *
     * @return string 未配置或读取失败时返回空串
     */
    public static function internalSecret(): string
    {
        $secret = trim((string) config('platform.gateway.internal_secret', ''));
        if ($secret !== '') {
            return $secret;
        }

        $file = trim((string) config('platform.gateway.internal_secret_file', ''));
        if ($file === '') {
            return '';
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            // 路径不算敏感信息，记下来能直接指认"挂载没生效"
            Log::error('[gateway] 读取内部签名密钥文件失败', ['file' => $file]);
            return '';
        }

        // 部分 Secret 挂载会在末尾补换行，trim 一次即可；不要对内容做其他加工
        return trim($content);
    }

    /**
     * 密钥形态是否可用
     *
     * 只拦明显坏掉的输入，不限定必须是 64 位 hex——本地联调与测试会用短一些的
     * 占位值，硬卡长度会让"能跑通的配置"被判死。真正的一致性由验签结果说话。
     *
     * 能拦住的三类典型错误：复制时带空格 / 被换行截断 / 只填了半截。
     *
     * @param string $secret
     * @return bool
     */
    public static function isWellFormedSecret(string $secret): bool
    {
        if ($secret === '' || strlen($secret) < 16) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9_\-]+$/', $secret) === 1;
    }

    /**
     * 密钥指纹（不可逆，仅用于两侧比对）
     *
     * 排查"平台与网关是不是同一把"时，把两边的指纹一比即可，
     * 不需要把密钥原文贴到聊天窗口或工单里。
     *
     * @param string $secret
     * @return string 12 位 hex；空密钥返回空串
     */
    public static function secretFingerprint(string $secret): string
    {
        return $secret === '' ? '' : substr(hash('sha256', $secret), 0, 12);
    }

    /**
     * 构造可信头规范化串（缺失字段以空串占位）
     *
     * @param array $headers 键名不区分大小写的头数组
     * @return string
     */
    public static function trustCanonical(array $headers): string
    {
        $lines = [];
        foreach (self::TRUST_FIELDS as $name) {
            $lines[] = self::headerValue($headers, $name);
        }
        return implode("\n", $lines);
    }

    /**
     * 计算可信头签名
     *
     * @param array $headers
     * @param string $secret
     * @return string
     */
    public static function trustSign(array $headers, string $secret): string
    {
        return hash_hmac('sha256', self::trustCanonical($headers), $secret);
    }

    /**
     * 校验可信头签名
     *
     * @param array $headers 请求头
     * @param string $secret 网关内部密钥
     * @param int $skew 允许的时间偏移（秒）
     * @param int|null $now 当前时间，测试可注入
     * @return array{ok:bool,reason:string,skew:int}
     */
    public static function verifyTrust(array $headers, string $secret, int $skew = 300, ?int $now = null): array
    {
        if ($secret === '') {
            // 密钥未配置时不能"默认通过"——那等于没有验签
            return ['ok' => false, 'reason' => self::REASON_SECRET_MISSING, 'skew' => 0];
        }

        $received = strtolower(self::headerValue($headers, 'X-Internal-Sign'));
        if ($received === '') {
            return ['ok' => false, 'reason' => self::REASON_SIGN_MISSING, 'skew' => 0];
        }

        $timestamp = self::headerValue($headers, 'X-Timestamp');
        if ($timestamp === '') {
            return ['ok' => false, 'reason' => self::REASON_TIMESTAMP_MISSING, 'skew' => 0];
        }

        $now = $now ?? time();
        $offset = abs($now - (int) $timestamp);
        if ($skew > 0 && $offset > $skew) {
            return ['ok' => false, 'reason' => self::REASON_CLOCK_SKEW, 'skew' => $offset];
        }

        $expected = self::trustSign($headers, $secret);
        if (!hash_equals($expected, $received)) {
            return ['ok' => false, 'reason' => self::REASON_SIGN_MISMATCH, 'skew' => $offset];
        }

        return ['ok' => true, 'reason' => '', 'skew' => $offset];
    }

    /**
     * 构造 L2 出站签名规范化串（§12.4：method \n path \n query \n body）
     *
     * query 必须是**未重新排序**的原始串：重新拼接会改变签名结果，
     * 对端按收到的原始 query 计算，两边就对不上。
     *
     * @param string $method
     * @param string $path
     * @param string $query
     * @param string $body
     * @return string
     */
    public static function callCanonical(string $method, string $path, string $query = '', string $body = ''): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . $query . "\n" . $body;
    }

    /**
     * 构造 L2 出站签名（按版本分派）
     *
     * @param string $secret
     * @param string $method
     * @param string $path
     * @param string $query
     * @param string $body
     * @param string $appKey 仅 gateway 版需要（参与规范串）
     * @param string $timestamp 仅 gateway 版需要（参与规范串）
     * @param string $nonce 仅 gateway 版需要（参与规范串）
     * @param string|null $version 不传则读 platform.gateway.l2_version
     * @return string
     */
    public static function callSign(
        string $secret,
        string $method,
        string $path,
        string $query = '',
        string $body = '',
        string $appKey = '',
        string $timestamp = '',
        string $nonce = '',
        ?string $version = null
    ): string {
        if (self::l2Version($version) === self::L2_VERSION_GATEWAY) {
            return hash_hmac(
                'sha256',
                self::callCanonicalGateway($appKey, $timestamp, $nonce, $method, $path, $query, $body),
                $secret
            );
        }
        return hash_hmac('sha256', self::callCanonical($method, $path, $query, $body), $secret);
    }

    /**
     * 构造已部署网关（jy-l2-sign）使用的规范串
     *
     * 顺序与字段来自验证脚本 l2_headers()，七段依次为：
     *   appKey \n timestamp \n nonce \n METHOD \n path \n 排序后的 query \n sha256(body)
     *
     * 注意三处易错点：
     *   1. appKey / timestamp / nonce 本身参与签名（V3.0 四段版没有）；
     *   2. query 必须先按 (name, value) 排序再整体 urlencode，不能直接用原始串；
     *   3. body 取 SHA-256 十六进制摘要，不是原文。
     *
     * @param string $appKey
     * @param string $timestamp
     * @param string $nonce
     * @param string $method
     * @param string $path 不含 query 的路径部分
     * @param string $query 原始 query 串（可带或不带 ?）
     * @param string $body 原始请求体
     * @return string
     */
    public static function callCanonicalGateway(
        string $appKey,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $query = '',
        string $body = ''
    ): string {
        return implode("\n", [
            $appKey,
            $timestamp,
            $nonce,
            strtoupper($method),
            $path,
            self::canonicalQuery($query),
            hash('sha256', $body),
        ]);
    }

    /**
     * 规范化 query：解析 → 按 (name, value) 升序 → 重新 urlencode
     *
     * 之所以不能直接排序原始 `k=v&k=v` 片段，是因为要先解码再编码才能与
     * 对端 parse_qsl + urlencode 的结果逐字节一致（例如 `%20` 与 `+`）。
     *
     * @param string $query
     * @return string
     */
    public static function canonicalQuery(string $query): string
    {
        $query = ltrim($query, '?');
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $pos = strpos($chunk, '=');
            if ($pos === false) {
                $pairs[] = [urldecode($chunk), ''];
            } else {
                $pairs[] = [urldecode(substr($chunk, 0, $pos)), urldecode(substr($chunk, $pos + 1))];
            }
        }

        usort($pairs, static function (array $a, array $b): int {
            $cmp = strcmp($a[0], $b[0]);
            return $cmp !== 0 ? $cmp : strcmp($a[1], $b[1]);
        });

        $parts = [];
        foreach ($pairs as $pair) {
            $parts[] = self::quotePlus($pair[0]) . '=' . self::quotePlus($pair[1]);
        }
        return implode('&', $parts);
    }

    /**
     * 与 Python urllib.parse.urlencode 对齐的编码（空格→+）
     *
     * PHP urlencode 与 Python quote_plus 同为 RFC1738，唯一差异是 ASCII `~`：
     * Python 视为安全字符不编码，PHP 编成 %7E，这里补回来。
     *
     * @param string $value
     * @return string
     */
    private static function quotePlus(string $value): string
    {
        return str_replace('%7E', '~', urlencode($value));
    }

    /**
     * 解析 L2 版本，非法值一律回落到 gateway（宁可签名不通，也不能静默走错分支）
     *
     * @param string|null $version
     * @return string
     */
    public static function l2Version(?string $version = null): string
    {
        $value = $version ?? (string) config('platform.gateway.l2_version', self::L2_VERSION_GATEWAY);
        return $value === self::L2_VERSION_SPEC ? self::L2_VERSION_SPEC : self::L2_VERSION_GATEWAY;
    }

    /**
     * 构造 L2 签名所需的请求头
     *
     * @param string $secret
     * @param string $appKey
     * @param string $appCode
     * @param string $method
     * @param string $path
     * @param string $query
     * @param string $body
     * @param string $requestId
     * @param string|null $version 不传则读 platform.gateway.l2_version
     * @return array
     */
    public static function callHeaders(
        string $secret,
        string $appKey,
        string $appCode,
        string $method,
        string $path,
        string $query = '',
        string $body = '',
        string $requestId = '',
        ?string $version = null
    ): array {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $headers = [
            'X-App-Key' => $appKey,
            'X-App-Code' => $appCode,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Sign-Alg' => 'HMAC-SHA256',
            'X-Signature' => self::callSign($secret, $method, $path, $query, $body, $appKey, $timestamp, $nonce, $version),
        ];

        if ($requestId !== '') {
            // §6.4.3 规则四：requestId 必须双向透传
            $headers['X-Request-Id'] = $requestId;
        }

        return $headers;
    }

    /**
     * 从请求头数组取值（大小写不敏感）
     *
     * @param array $headers
     * @param string $name
     * @return string
     */
    private static function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                if (is_array($value)) {
                    return trim((string) reset($value));
                }
                return trim((string) $value);
            }
        }
        return '';
    }
}

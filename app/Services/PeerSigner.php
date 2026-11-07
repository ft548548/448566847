<?php

namespace App\Services;

/**
 * 三平台对端层签名（《三平台对接方案 V2.0》§5.2、清单 §4.2）
 *
 * 与 GatewaySigner 的区别（两者不可互换，密钥与算法都不同）：
 *
 *   GatewaySigner —— L2 网关层。X-App-Key / X-Signature 五头，平台 ↔ 网关，
 *                    canonical 含 method/path/query，防的是"请求被改道"。
 *   PeerSigner    —— 对端层。X-App-Code / X-Internal-Sign，平台 ↔ 平台，
 *                    canonical 只含 4 段，防的是"身份与载荷被改"。
 *
 * 规范原文：
 *   canonical = appCode \n requestId \n timestamp \n sha256_hex(rawBody)
 *   signature = hex(HMAC_SHA256(secret, canonical))
 *
 * 四个易错点（清单 §4.3），本类逐条落实：
 *   1. 对原始字节哈希 —— 用 $request->getContent()，绝不重新 json_encode；
 *   2. GET body 为空时哈希值是 sha256("")=e3b0c442...b855，不能省略该段；
 *   3. 时间窗 ±300 秒，秒级；
 *   4. 签名小写十六进制（hash_hmac 默认小写，不要 strtoupper）。
 */
class PeerSigner
{
    public const HEADER_APP_CODE = 'X-App-Code';
    public const HEADER_REQUEST_ID = 'X-Request-Id';
    public const HEADER_TIMESTAMP = 'X-Timestamp';
    public const HEADER_SIGNATURE = 'X-Internal-Sign';

    /** 空 body 的 SHA-256，单独常量化以免被误当成"缺字段"跳过 */
    public const EMPTY_BODY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** requestId 允许的字符与长度（与 PlatformContract 保持一致） */
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    /**
     * 载荷哈希：对**原始字节**求 SHA-256
     *
     * 传空串时返回 sha256("")，这是规范要求的固定值，不是"没有哈希"。
     *
     * @param string $rawBody
     * @return string 小写十六进制
     */
    public static function bodyHash(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    /**
     * 拼接待签串
     *
     * @param string $appCode
     * @param string $requestId
     * @param string|int $timestamp
     * @param string $rawBody
     * @return string
     */
    public static function canonical(string $appCode, string $requestId, $timestamp, string $rawBody): string
    {
        return implode("\n", [
            $appCode,
            $requestId,
            (string) $timestamp,
            self::bodyHash($rawBody),
        ]);
    }

    /**
     * 计算签名
     *
     * @param string $secret
     * @param string $appCode
     * @param string $requestId
     * @param string|int $timestamp
     * @param string $rawBody
     * @return string 小写十六进制
     */
    public static function sign(string $secret, string $appCode, string $requestId, $timestamp, string $rawBody): string
    {
        return hash_hmac(
            'sha256',
            self::canonical($appCode, $requestId, $timestamp, $rawBody),
            $secret
        );
    }

    /**
     * 常量时间比对（防时序侧信道）
     *
     * @param string $secret
     * @param string $appCode
     * @param string $requestId
     * @param string|int $timestamp
     * @param string $rawBody
     * @param string $provided
     * @return bool
     */
    public static function verify(
        string $secret,
        string $appCode,
        string $requestId,
        $timestamp,
        string $rawBody,
        string $provided
    ): bool {
        if ($secret === '' || $provided === '') {
            return false;
        }
        return hash_equals(
            self::sign($secret, $appCode, $requestId, $timestamp, $rawBody),
            strtolower(trim($provided))
        );
    }

    /**
     * 构造出站请求头
     *
     * @param string $secret
     * @param string $appCode 本平台编码（发送方身份）
     * @param string $rawBody 将被发送的原始字节，必须与实际发出的 body 完全一致
     * @param string $requestId 留空则自动生成
     * @param int|null $timestamp 便于测试注入，默认取当前秒
     * @return array
     */
    public static function headers(string $secret, string $appCode, string $rawBody, string $requestId = '', ?int $timestamp = null): array
    {
        $requestId = $requestId !== '' ? $requestId : self::newRequestId();
        $timestamp = $timestamp !== null ? $timestamp : time();

        return [
            self::HEADER_APP_CODE => $appCode,
            self::HEADER_REQUEST_ID => $requestId,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_SIGNATURE => self::sign($secret, $appCode, $requestId, $timestamp, $rawBody),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * 生成 requestId：req_ + 32 位十六进制（满足 8~128 位与字符集约束）
     *
     * @return string
     */
    public static function newRequestId(): string
    {
        return 'req_' . bin2hex(random_bytes(16));
    }

    /**
     * requestId 是否合法
     *
     * @param string $requestId
     * @return bool
     */
    public static function validRequestId(string $requestId): bool
    {
        return preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1;
    }
}

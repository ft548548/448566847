<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 论坛账号确认客户端（本平台作为调用方）
 *
 * 依据《警院论坛账号确认接口 · 接口说明与四平台接入指南》v2.2.0：
 *   §一   调用链：平台 BFF →(L2 五头)→ 统一网关 →(内部签名 + 回环)→ 论坛
 *   §2.1  请求：POST /answer/api/v1/account/verify，login / pass / login_type / client_id
 *   §2.2  响应：以 data.confirmed 为唯一结论，附带最小身份信息
 *   §四   失败情形：网关数字错误码 + data.reason
 *
 * 五条必须守住的规则：
 *   1. **只看 confirmed**：平台不自行比对任何东西，也不对 reason 做二次判定；
 *   2. **密码不出本方法**：不写日志、不进异常、不落库、不回显（日志只记 login）；
 *   3. **不重试**：文档 §四 明确"签名类失败禁原样重试"，密码类失败重试等同撞库；
 *   4. **渠道故障与认证失败必须分开**：超时/5xx 报 channel_unavailable，
 *      绝不能报成"账号或密码错误"，否则用户会一直重试且查不到原因；
 *   5. **网关码要留档**：gatewayCode 原样带回，联调时一眼看出卡在网关还是论坛。
 *
 * 与 ExamClient / ShowcaseClient 同构：未配置密钥自动 mock，结果标记 source。
 * mock 只在本地生效——它不做任何真实校验，绝不能出现在生产配置里。
 */
class ForumAccountClient
{
    /** 确认失败（对端业务结论，文档 §四：账号不存在与密码错误刻意返回同一结论） */
    public const REASON_INVALID_CREDENTIALS = 'invalid_credentials';
    public const REASON_ACCOUNT_UNAVAILABLE = 'account_unavailable';

    /** 请求本身不合法（平台侧校验或对端 40001/41300） */
    public const REASON_INVALID_PARAMS = 'invalid_params';
    public const REASON_PAYLOAD_TOO_LARGE = 'payload_too_large';

    /** 渠道/配置类故障：与"密码错"严格区分 */
    public const REASON_CHANNEL_UNAVAILABLE = 'channel_unavailable';
    public const REASON_CHANNEL_MISCONFIGURED = 'channel_misconfigured';
    public const REASON_CLOCK_SKEW = 'clock_skew';
    public const REASON_RATE_LIMITED = 'rate_limited';
    public const REASON_DISABLED = 'disabled';
    public const REASON_INTERNAL_ERROR = 'internal_error';

    /** 结论来源，联调时用来区分"真的回源了"还是"本地 mock" */
    public const SOURCE_REMOTE = 'remote';
    public const SOURCE_MOCK = 'mock';

    /** 文档 §2.1 允许的 login_type */
    public const LOGIN_TYPES = ['auto', 'username', 'email'];

    /**
     * 调用账号确认
     *
     * 返回结构固定，控制器直接按 code/status 映射响应，不再二次判断。
     *
     * @param string $login 论坛用户名或邮箱
     * @param string $password 明文密码，仅在本方法内存中停留
     * @param string $loginType auto / username / email
     * @param string $requestId 全链路追踪号，透传给网关与论坛
     * @return array{ok:bool,code:string,reason:string,message:string,status:int,gatewayCode:int,source:string,profile:array}
     */
    public function verify(string $login, string $password, string $loginType = 'auto', string $requestId = ''): array
    {
        if (!$this->enabled()) {
            return $this->fail(
                PlatformResponse::SERVICE_UNAVAILABLE,
                self::REASON_DISABLED,
                '论坛账号确认通道未启用',
                503
            );
        }

        $login = trim($login);

        // 平台侧先做一次校验：省掉一次跨网往返，也不把明显非法的报文送出去
        $invalid = $this->validate($login, $password, $loginType);
        if ($invalid !== '') {
            return $this->fail(PlatformResponse::VALIDATION_ERROR, self::REASON_INVALID_PARAMS, $invalid, 400);
        }

        if ($this->mockEnabled()) {
            return $this->mockResult($login);
        }

        return $this->requestRemote($login, $password, $loginType, $requestId);
    }

    /**
     * 入参校验（文档 §2.1 字段约束 + §四 login_type 非法 → 40001）
     *
     * 非法 login_type 不做"归一为 auto"处理：静默纠正会掩盖调用方的错误，
     * 与文档 §四 明确返回 40001 的约定也不一致，联调时更难发现。
     *
     * @param string $login
     * @param string $password
     * @param string $loginType
     * @return string 通过时返回空串
     */
    public function validate(string $login, string $password, string $loginType = 'auto'): string
    {
        if ($login === '') {
            return '请输入论坛用户名或邮箱';
        }
        if (mb_strlen($login) > 100) {
            return '登录名长度不得超过 100 个字符';
        }
        if ($password === '') {
            return '请输入密码';
        }
        // 8~32 是对端约束：小于 8 直接判定不合法，避免把无效报文送到论坛
        if (mb_strlen($password) < 8 || mb_strlen($password) > 32) {
            return '密码长度应为 8~32 个字符';
        }
        if (!in_array($loginType, self::LOGIN_TYPES, true)) {
            return '登录类型只支持 auto / username / email';
        }
        return '';
    }

    /**
     * 失败节流：查询剩余锁定秒数
     *
     * 密码代验接口暴露的是"账号是否存在 + 密码是否正确"，不限流就是一个
     * 现成的撞库入口。这里按 (login, ip) 维度计数，命中阈值即锁定一段时间。
     *
     * 键里用 sha1 而不是明文：缓存若被导出，不应直接暴露用户账号。
     *
     * @param string $login
     * @param string $ip
     * @return int 0 表示未锁定
     */
    public function lockRemaining(string $login, string $ip = ''): int
    {
        $key = $this->throttleKey($login, $ip);
        $count = (int) Cache::get($key, 0);
        if ($count < $this->throttleMax()) {
            return 0;
        }

        // 剩余时间取决于缓存实现；这里用窗口长度作上界，宁可多锁一会儿
        return max(1, $this->throttleWindow());
    }

    /**
     * 记录一次确认失败（仅对 invalid_credentials / account_unavailable 计数）
     *
     * @param string $login
     * @param string $ip
     * @return void
     */
    public function recordFailure(string $login, string $ip = ''): void
    {
        $key = $this->throttleKey($login, $ip);
        if (!Cache::has($key)) {
            Cache::put($key, 0, $this->throttleWindow());
        }
        Cache::increment($key);
    }

    /**
     * 确认成功后清空计数
     *
     * @param string $login
     * @param string $ip
     * @return void
     */
    public function clearFailures(string $login, string $ip = ''): void
    {
        Cache::forget($this->throttleKey($login, $ip));
    }

    /**
     * 通道是否可用（配置 + 开关）
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return $this->boolConfig('platform.forum.enabled', true);
    }

    /**
     * 是否 mock：显式配置优先，否则"没配密钥"即 mock
     *
     * 这一条与 ExamClient / ShowcaseClient 保持同一判据，避免三个客户端
     * 出现"一个真调、一个假调"的错位。
     *
     * @return bool
     */
    public function mockEnabled(): bool
    {
        $value = config('platform.forum.mock', null);
        if ($value !== null && $value !== '') {
            return $this->boolConfig('platform.forum.mock', false);
        }
        return trim((string) config('platform.forum.app_secret', '')) === '';
    }

    /**
     * 真实调用网关
     *
     * body 必须先序列化成字符串再签名，且**发送的就是这一串**：
     * 用 Http::post($url, $array) 会让框架重新编码，键序或转义一变签名就对不上。
     *
     * @param string $login
     * @param string $password
     * @param string $loginType
     * @param string $requestId
     * @return array
     */
    private function requestRemote(string $login, string $password, string $loginType, string $requestId): array
    {
        $baseUrl = rtrim((string) config('platform.forum.base_url', ''), '/');
        $path = (string) config('platform.forum.verify_path', '/answer/api/v1/account/verify');
        $secret = (string) config('platform.forum.app_secret', '');

        if ($baseUrl === '' || $secret === '') {
            // 缺配置属于部署问题，不能当成"确认失败"
            Log::error('[forum] account verify not configured', [
                'hasBaseUrl' => $baseUrl !== '',
                'hasSecret' => $secret !== '',
            ]);
            return $this->fail(
                PlatformResponse::SERVICE_UNAVAILABLE,
                self::REASON_CHANNEL_MISCONFIGURED,
                '账号确认通道未配置，请联系管理员',
                503
            );
        }

        $body = json_encode([
            'login' => $login,
            'pass' => $password,
            'login_type' => $loginType,
            // client_id 只做审计，用配置值而不是用户可控输入
            'client_id' => (string) config('platform.forum.client_id', 'jy-workshop-web'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            return $this->fail(PlatformResponse::INTERNAL_ERROR, self::REASON_INTERNAL_ERROR, '请求体序列化失败', 500);
        }

        $headers = GatewaySigner::callHeaders(
            $secret,
            (string) config('platform.forum.app_key', 'jy-workshop-svc'),
            (string) config('platform.app_code', 'workshop'),
            'POST',
            $path,
            '',
            $body,
            $requestId
        );

        $timeout = max(0.5, ((int) config('platform.forum.timeout_ms', 2500)) / 1000);

        try {
            $response = Http::withHeaders($headers)
                ->withBody($body, 'application/json')
                ->timeout($timeout)
                ->acceptJson()
                ->post($baseUrl . $path);
        } catch (\Throwable $e) {
            // 超时 / DNS / 连接失败：平台级故障。绝不报成密码错误，
            // 也不重试——重试只会让用户等更久且放大对端压力。
            Log::warning('[forum] account verify request failed', [
                'login' => $login,
                'requestId' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return $this->fail(
                PlatformResponse::SERVICE_UNAVAILABLE,
                self::REASON_CHANNEL_UNAVAILABLE,
                '账号确认服务暂时不可用，请稍后重试',
                503
            );
        }

        return $this->parse($response->status(), $response->json(), $login, $requestId);
    }

    /**
     * 解析网关响应
     *
     * 判据是 `code`（数字）与 `data.confirmed`，不是 HTTP 200：
     * 网关在业务失败时也可能给 200，只看状态码会误判为登录成功。
     *
     * @param int $status
     * @param mixed $body
     * @param string $login
     * @param string $requestId
     * @return array
     */
    private function parse(int $status, $body, string $login, string $requestId): array
    {
        if (!is_array($body)) {
            Log::warning('[forum] account verify non-json response', ['status' => $status, 'requestId' => $requestId]);
            return $this->fail(
                PlatformResponse::SERVICE_UNAVAILABLE,
                self::REASON_CHANNEL_UNAVAILABLE,
                '账号确认返回格式异常，请稍后重试',
                503
            );
        }

        $code = (int) ($body['code'] ?? 0);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $reason = (string) ($data['reason'] ?? '');

        // ---------- 成功：唯一判据是 confirmed ----------
        if ($status === 200 && $code === 200 && ($data['confirmed'] ?? false) === true) {
            return [
                'ok' => true,
                'code' => PlatformResponse::OK,
                'reason' => '',
                'message' => '账号确认通过',
                'status' => 200,
                'gatewayCode' => $code,
                'source' => self::SOURCE_REMOTE,
                'profile' => [
                    // user_id 是论坛主键，跨平台 sub。原值存储：不 trim、不转小写、不加前缀
                    'subject' => (string) ($data['user_id'] ?? ''),
                    'username' => (string) ($data['username'] ?? ''),
                    'displayName' => (string) ($data['display_name'] ?? ''),
                    'role' => (string) ($data['role'] ?? ''),
                    'emailMasked' => (string) ($data['e_mail_masked'] ?? ''),
                    'accountSource' => (string) ($data['account_source'] ?? ''),
                    'forumRequestId' => (string) ($data['request_id'] ?? ''),
                ],
            ];
        }

        // ---------- 认证失败：40104，属于业务结论，原样透传 reason ----------
        if ($code === 40104) {
            $known = in_array($reason, [self::REASON_INVALID_CREDENTIALS, self::REASON_ACCOUNT_UNAVAILABLE], true);
            $reason = $known ? $reason : self::REASON_INVALID_CREDENTIALS;

            Log::info('[forum] account verify rejected', [
                'login' => $login,
                'reason' => $reason,
                'requestId' => $requestId,
            ]);

            return $this->fail(
                PlatformResponse::AUTH_REQUIRED,
                $reason,
                $reason === self::REASON_ACCOUNT_UNAVAILABLE
                    ? '账号已停用，请联系管理员'
                    : '账号或密码错误',
                401,
                $code
            );
        }

        // ---------- 参数类：用户可自行修正 ----------
        if ($code === 40001) {
            return $this->fail(PlatformResponse::VALIDATION_ERROR, self::REASON_INVALID_PARAMS, '账号或密码格式不正确', 400, $code);
        }
        if ($code === 41300) {
            return $this->fail(PlatformResponse::VALIDATION_ERROR, self::REASON_PAYLOAD_TOO_LARGE, '请求内容过大', 413, $code);
        }

        // ---------- 限流：让调用方按 Retry-After 退避 ----------
        if ($code === 42901 || $status === 429) {
            return $this->fail(PlatformResponse::SERVICE_UNAVAILABLE, self::REASON_RATE_LIMITED, '请求过于频繁，请稍后重试', 429, $code);
        }

        // ---------- 其余一律是平台级故障，逐码给出可操作提示 ----------
        $hint = $this->channelHint($code);
        Log::error('[forum] account verify channel error', [
            'status' => $status,
            'code' => $code,
            'hint' => $hint,
            'requestId' => $requestId,
        ]);

        return $this->fail(
            PlatformResponse::SERVICE_UNAVAILABLE,
            self::REASON_CHANNEL_MISCONFIGURED,
            '账号确认服务暂不可用（' . $hint . '）',
            503,
            $code
        );
    }

    /**
     * 网关故障码 → 可操作提示
     *
     * 这几个码在联调时最容易互相误判，逐个写清楚省下大量排查时间。
     *
     * @param int $code
     * @return string
     */
    private function channelHint(int $code): string
    {
        switch ($code) {
            case 40105:
                return 'L2 签名不通过：核对 AppSecret 与规范串版本';
            case 40106:
                return '时钟偏移超窗：校准本机时间';
            case 40107:
                return 'nonce 被判重放：检查随机源';
            case 40108:
                return 'AppKey 未在网关预置';
            case 40303:
                return '内部签名校验失败：账号确认路由的密钥不匹配';
            case 40300:
                return '账号确认路由未登记';
            case 50200:
                return '网关上游不可达';
            case 50400:
                return '网关上游超时';
            case 50000:
                return '论坛内部错误';
            default:
                return 'code=' . $code;
        }
    }

    /**
     * mock 结果（仅本地）
     *
     * 明确把 source 标成 mock 并在 data 里回传 mock=true：
     * 绝不能让"本地演示能登录"被误当成"接入验证通过"。
     *
     * @param string $login
     * @return array
     */
    private function mockResult(string $login): array
    {
        return [
            'ok' => true,
            'code' => PlatformResponse::OK,
            'reason' => '',
            'message' => '账号确认通过（mock）',
            'status' => 200,
            'gatewayCode' => 0,
            'source' => self::SOURCE_MOCK,
            'profile' => [
                'subject' => 'mock_forum_' . substr(sha1($login), 0, 8),
                'username' => $login,
                'displayName' => '演示账号',
                'role' => 'student',
                'emailMasked' => '',
                'accountSource' => 'legacy',
                'forumRequestId' => '',
            ],
        ];
    }

    /**
     * 统一失败结构
     *
     * @param string $code 平台规范码
     * @param string $reason 机读原因
     * @param string $message 展示文案
     * @param int $status HTTP 状态
     * @param int $gatewayCode 网关原始码（0 表示未到达网关）
     * @return array
     */
    private function fail(string $code, string $reason, string $message, int $status, int $gatewayCode = 0): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'reason' => $reason,
            'message' => $message,
            'status' => $status,
            'gatewayCode' => $gatewayCode,
            'source' => self::SOURCE_REMOTE,
            'profile' => [],
        ];
    }

    /**
     * 节流键：账号与 IP 都做摘要，缓存里不出现明文
     *
     * @param string $login
     * @param string $ip
     * @return string
     */
    private function throttleKey(string $login, string $ip): string
    {
        return 'forum_verify:fail:' . sha1(mb_strtolower($login) . '|' . $ip);
    }

    /**
     * @return int
     */
    private function throttleMax(): int
    {
        return max(1, (int) config('platform.forum.throttle_max', 5));
    }

    /**
     * @return int
     */
    private function throttleWindow(): int
    {
        return max(60, (int) config('platform.forum.throttle_window', 300));
    }

    /**
     * @param string $key
     * @param bool $default
     * @return bool
     */
    private function boolConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
    }
}

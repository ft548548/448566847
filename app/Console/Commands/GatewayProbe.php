<?php

namespace App\Console\Commands;

use App\Services\ForumAccountClient;
use App\Services\GatewaySigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 统一 API 网关接入自检
 *
 * 依据《警院WeChat五平台_统一API网关部署报告》：
 *   路由基线（19 条）、L1 令牌自省、L2 五头签名、默认拒绝、数字错误码。
 *
 * 目的：把"接不上"拆成可判定的几段，一次跑完就知道卡在哪。
 *   1. 网关是否可达
 *   2. workshop 前缀路由是否已登记（默认拒绝是否生效）
 *   3. L2 请求头是否被受理（区分 AppKey 未预置 / 时钟偏移 / 签名不符）
 *   4. 本平台凭据是否真能通关
 *
 * 本命令只读：不写库、不改路由、不消费幂等键以外的状态。
 */
class GatewayProbe extends Command
{
    /** 该路由用于验证"服务间调用"的 L2 链路，不存在的路径也会在鉴权阶段被拦下 */
    private const INTERNAL_PROBE_PATH = '/api/v1/workshop/internal/ping';

    /** 未登记路由必须被默认拒绝规则拦下 */
    private const UNREGISTERED_PATH = '/api/v1/__gw_probe_unregistered';

    /** L1 路由：无令牌必须是 401 / 40101 */
    private const L1_PROBE_PATH = '/api/v1/workshop/ping';

    /**
     * 明显不能用于生产的占位密钥
     *
     * 这类值最危险的地方在于"看起来配了"：health 显示 internalSecretSet=true，
     * 但网关用真密钥签的请求一律 403，排查时很容易往网络或网关配置上找。
     */
    private const INTERNAL_PLACEHOLDERS = [
        'local-dev-secret-not-for-prod',
        'changeme',
        'change-me',
        'secret',
        'test',
    ];

    protected $signature = 'gateway:probe
        {--base= : 网关地址，默认取 GATEWAY_BASE_URL}
        {--app-key= : L2 的 AppKey，默认取 EXAM_APP_KEY}
        {--app-secret= : L2 的 AppSecret，默认取 EXAM_APP_SECRET}
        {--token= : 可选，L1 访问令牌（Bearer），用于验证用户态链路}
        {--login= : 可选，论坛账号；配合环境变量 FORUM_PROBE_PASS 验证账号确认链路}
        {--forum-app-key= : 账号确认用的 AppKey，默认取 FORUM_APP_KEY}';

    protected $description = '探测统一 API 网关的连通性、路由登记、L1/L2 鉴权与论坛账号确认链路';

    public function handle()
    {
        $base = rtrim((string) ($this->option('base') ?: config('platform.gateway.base_url', '')), '/');
        if ($base === '') {
            $this->error('未配置网关地址：请设置 .env 的 GATEWAY_BASE_URL，或使用 --base=http://host');
            return 1;
        }

        $appKey = (string) ($this->option('app-key') ?: config('platform.exam.app_key', ''));
        $appSecret = (string) ($this->option('app-secret') ?: config('platform.exam.app_secret', ''));
        $version = GatewaySigner::l2Version();
        $timeout = max(2, (int) config('platform.gateway.probe_timeout', 8));

        $this->line('网关地址  ：' . $base);
        $this->line('L2 规范串 ：' . $version . '（' . ($version === GatewaySigner::L2_VERSION_GATEWAY
            ? 'appKey/ts/nonce/METHOD/path/query/sha256(body) 七段'
            : 'METHOD/path/query/body 四段') . '）');
        $this->line('AppKey    ：' . ($appKey !== '' ? $appKey : '(未配置)'));
        $this->line('AppSecret ：' . ($appSecret !== '' ? '已配置（' . strlen($appSecret) . ' 字节）' : '(未配置)'));

        $forumKey = (string) ($this->option('forum-app-key') ?: config('platform.forum.app_key', 'jy-workshop-svc'));
        $forumSecretSet = trim((string) config('platform.forum.app_secret', '')) !== '';
        $this->line('账号确认  ：' . $forumKey . '，AppSecret ' . ($forumSecretSet ? '已配置' : '未配置（走 mock）'));
        $internalSecret = GatewaySigner::internalSecret();
        $this->line('入站验签  ：' . ($internalSecret !== ''
            ? '已配置（指纹 ' . GatewaySigner::secretFingerprint($internalSecret) . '，' . strlen($internalSecret) . ' 位）'
            : '未配置（网关回源将被拒）'));
        $this->newLine();

        $rows = [];

        // ---------- 1. 连通性 ----------
        $rows[] = $this->probeConnectivity($base, $timeout);

        // ---------- 2. 路由登记与默认拒绝 ----------
        $rows[] = $this->probeL1Route($base, $timeout);
        $rows[] = $this->probeDefaultDeny($base, $timeout);

        // ---------- 3. L2 请求头受理 ----------
        $l2 = $this->probeL2($base, $timeout, $appKey, $appSecret, $version);
        $rows[] = $l2[0];

        // ---------- 4. L1 用户态（可选） ----------
        $token = (string) $this->option('token');
        if ($token !== '') {
            $rows[] = $this->probeL1Token($base, $timeout, $token);
        }

        // ---------- 5. 论坛账号确认 ----------
        // P6a 只验"路由已登记 + L2 生效"，不需要密钥；
        // P6b 需要账号和密码，密码只从环境变量读，避免进 shell 历史与进程列表。
        $rows[] = $this->probeAccountRoute($base, $timeout);

        $forumLogin = trim((string) $this->option('login'));
        $forumPass = (string) env('FORUM_PROBE_PASS', '');
        if ($forumLogin !== '' && $forumPass !== '') {
            $rows[] = $this->probeAccountVerify($forumLogin, $forumPass);
        }

        // ---------- 6. 入站验签（本平台作为上游） ----------
        $rows[] = $this->probeInternalSign();

        $this->table(['项', '结果', '说明'], $rows);

        $exit = $this->summarize($rows, $l2[1], $appSecret !== '');

        if (!$forumSecretSet) {
            $this->line('  提示：FORUM_APP_SECRET 未配置，账号确认当前走 mock（只能验通路由，不能验通签名）。');
            $this->line('        补齐密钥后执行：FORUM_PROBE_PASS=<密码> php artisan gateway:probe --login=<论坛账号>');
        }

        return $exit;
    }

    /**
     * @param string $base
     * @param int $timeout
     * @return array
     */
    private function probeConnectivity(string $base, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->get($base . '/');
        } catch (\Throwable $e) {
            return ['P1 网关可达', 'FAIL', $this->shorten($e->getMessage())];
        }

        $status = $response->status();
        return ['P1 网关可达', $status === 200 ? 'PASS' : 'FAIL', 'HTTP ' . $status];
    }

    /**
     * @param string $base
     * @param int $timeout
     * @return array
     */
    private function probeL1Route(string $base, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->acceptJson()->get($base . self::L1_PROBE_PATH);
        } catch (\Throwable $e) {
            return ['P2 路由已登记', 'FAIL', $this->shorten($e->getMessage())];
        }

        $status = $response->status();
        $code = (int) ($response->json('code') ?? 0);
        $ok = $status === 401 && $code === 40101;

        return [
            'P2 路由已登记',
            $ok ? 'PASS' : 'FAIL',
            'HTTP ' . $status . ' code=' . $code . ($ok ? '（缺令牌被拒，符合预期）' : '（预期 401/40101）'),
        ];
    }

    /**
     * @param string $base
     * @param int $timeout
     * @return array
     */
    private function probeDefaultDeny(string $base, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->acceptJson()->get($base . self::UNREGISTERED_PATH);
        } catch (\Throwable $e) {
            return ['P3 默认拒绝生效', 'FAIL', $this->shorten($e->getMessage())];
        }

        $status = $response->status();
        $code = (int) ($response->json('code') ?? 0);
        $ok = $status === 403 && $code === 40300;

        return [
            'P3 默认拒绝生效',
            $ok ? 'PASS' : 'FAIL',
            'HTTP ' . $status . ' code=' . $code . ($ok ? '（未登记 API 被拒）' : '（预期 403/40300）'),
        ];
    }

    /**
     * L2 探测：签名为"当前凭据 + 当前时间 + 一次性 nonce"的真实请求
     *
     * 结果码能精确定位卡点：
     *   200    → 凭据有效，链路打通
     *   40108  → AppKey 未在网关预置（需要网关侧加客户端）
     *   40106  → 时间戳超窗（本机与网关时钟不同步）
     *   40107  → nonce 重复（几乎不会发生，说明随机源异常）
     *   40105  → 头齐全但签名不符（AppSecret 不对，或规范串版本选错）
     *
     * @return array{0:array,1:array} [表格行, 诊断信息]
     */
    private function probeL2(string $base, int $timeout, string $appKey, string $appSecret, string $version): array
    {
        $diagnosis = ['code' => 0, 'message' => ''];

        if ($appKey === '') {
            return [
                ['P4 L2 服务签名', 'FAIL', '缺少 AppKey，跳过'],
                ['code' => -1, 'message' => '缺少 AppKey'],
            ];
        }

        // 未配置密钥时用占位值：仍能走到"签名比对"阶段，
        // 这样可以先确认 AppKey 是否预置，而不必等密钥到位。
        $secret = $appSecret !== '' ? $appSecret : 'probe-placeholder-secret';

        $headers = GatewaySigner::callHeaders(
            $secret,
            $appKey,
            (string) config('platform.app_code', 'workshop'),
            'GET',
            self::INTERNAL_PROBE_PATH,
            '',
            '',
            '',
            $version
        );

        try {
            $response = Http::withHeaders($headers)->timeout($timeout)->acceptJson()->get($base . self::INTERNAL_PROBE_PATH);
        } catch (\Throwable $e) {
            return [
                ['P4 L2 服务签名', 'FAIL', $this->shorten($e->getMessage())],
                ['code' => -1, 'message' => $e->getMessage()],
            ];
        }

        $status = $response->status();
        $code = (int) ($response->json('code') ?? 0);
        $message = (string) ($response->json('message') ?? '');
        $diagnosis = ['code' => $code, 'message' => $message];

        if ($status === 200) {
            return [['P4 L2 服务签名', 'PASS', 'HTTP 200，签名通过（auth_mode=' . (string) $response->json('identity.auth_mode', '-') . '）'], $diagnosis];
        }

        $map = [
            40105 => '头齐全但签名不符：AppSecret 不正确，或 L2 规范串版本选错（当前 ' . $version . '）',
            40106 => '时间戳超出 ±300s：本机与网关时钟不同步',
            40107 => 'nonce 被判定重放：随机源异常',
            40108 => 'AppKey 未在网关预置：需网关侧为本平台注册客户端并下发 AppSecret',
        ];

        $hint = $map[$code] ?? ('未预期的响应：' . $message);
        return [['P4 L2 服务签名', 'FAIL', 'code=' . $code . ' ' . $hint], $diagnosis];
    }

    /**
     * @param string $base
     * @param int $timeout
     * @param string $token
     * @return array
     */
    private function probeL1Token(string $base, int $timeout, string $token): array
    {
        try {
            $response = Http::withToken($token)->timeout($timeout)->acceptJson()->get($base . self::L1_PROBE_PATH);
        } catch (\Throwable $e) {
            return ['P5 L1 令牌链路', 'FAIL', $this->shorten($e->getMessage())];
        }

        $status = $response->status();
        $ok = $status === 200;

        return [
            'P5 L1 令牌链路',
            $ok ? 'PASS' : 'FAIL',
            'HTTP ' . $status . ($ok ? '（令牌有效，已注入身份）' : '（令牌无效或已过期）'),
        ];
    }

    /**
     * P6a：账号确认路由是否已登记（不需要密钥）
     *
     * 判据是"不带签名时被 L2 拦下"：
     *   401/40105 → 路由存在且 L2 生效（正常）
     *   403/40300 → 路由未登记（网关侧配置缺项）
     *   404       → 网关版本尚未包含 account-verify
     *
     * 这样即使 AppSecret 还没下发，也能先确认"网关这一段是通的"。
     *
     * @param string $base
     * @param int $timeout
     * @return array
     */
    private function probeAccountRoute(string $base, int $timeout): array
    {
        $path = (string) config('platform.forum.verify_path', '/answer/api/v1/account/verify');

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($base . $path, ['login' => 'probe', 'pass' => 'probe-not-real']);
        } catch (\Throwable $e) {
            return ['P6 账号确认路由', 'FAIL', $this->shorten($e->getMessage())];
        }

        $status = $response->status();
        $code = (int) ($response->json('code') ?? 0);

        if ($status === 401 && in_array($code, [40105, 40101], true)) {
            return ['P6 账号确认路由', 'PASS', 'HTTP 401 code=' . $code . '（路由已登记，未带签名被 L2 拦下）'];
        }
        if ($status === 403 || $code === 40300) {
            return ['P6 账号确认路由', 'FAIL', 'HTTP ' . $status . ' code=' . $code . '（路由未登记，需网关侧补 account-verify）'];
        }

        return ['P6 账号确认路由', 'FAIL', 'HTTP ' . $status . ' code=' . $code . '（预期 401/40105）'];
    }

    /**
     * P6b：用真实账号跑一次确认
     *
     * 刻意复用业务客户端 ForumAccountClient，而不是另写一份签名逻辑——
     * 自检若走独立实现，就会出现"自检通过、业务失败"的分裂。
     *
     * 结果判读：
     *   ok=true                     → 完全打通（签名 + 论坛查询 + 密码比对）
     *   invalid_credentials         → 同样是打通：签名已过，只是账号密码不匹配
     *   40108                       → AppKey 未在网关预置
     *   40105                       → AppSecret 不对，或 L2 规范串版本选错
     *   channel_unavailable         → 连通性/超时
     *
     * @param string $login
     * @param string $pass
     * @return array
     */
    private function probeAccountVerify(string $login, string $pass): array
    {
        $client = new ForumAccountClient();

        if ($client->mockEnabled()) {
            return ['P6b 账号确认验证', 'SKIP', '通道处于 mock（未配 FORUM_APP_SECRET），未发起真实请求'];
        }

        $result = $client->verify($login, $pass, 'auto', 'probe-' . bin2hex(random_bytes(6)));

        if ($result['ok']) {
            $profile = $result['profile'];
            return [
                'P6b 账号确认验证',
                'PASS',
                'confirmed=true uid=' . $profile['subject'] . ' role=' . ((string) $profile['role'] ?: '-'),
            ];
        }

        if (in_array($result['reason'], [
            ForumAccountClient::REASON_INVALID_CREDENTIALS,
            ForumAccountClient::REASON_ACCOUNT_UNAVAILABLE,
        ], true)) {
            return [
                'P6b 账号确认验证',
                'PASS',
                '已到达论坛业务层（gatewayCode=' . $result['gatewayCode'] . '），仅账号状态不符：' . $result['reason'],
            ];
        }

        return [
            'P6b 账号确认验证',
            'FAIL',
            'reason=' . $result['reason'] . ' gatewayCode=' . $result['gatewayCode'] . ' ' . $result['message'],
        ];
    }

    /**
     * P7：入站验签密钥（本平台作为上游）
     *
     * 只能做"本地自洽"验证：用当前密钥签一段样例可信头，再交给真实的
     * verifyTrust 验回来。这能证明长度、编码、算法与时间窗都正常，但
     * **不能**证明网关用的是同一把——那要靠网关真实回源，判据是 403 + SIGN_MISMATCH。
     *
     * 之所以单列一项：缺密钥时平台并不报错，只是把 X-User-Id 当匿名，
     * 表现为"登录了但接口说没登录"，比直接 403 更难定位。
     *
     * @return array
     */
    private function probeInternalSign(): array
    {
        $label = 'P7 入站验签密钥';

        $raw = config('platform.gateway.verify_sign', true);
        $enabled = is_bool($raw)
            ? $raw
            : !in_array(strtolower((string) $raw), ['0', 'false', 'off', 'no', ''], true);
        if (!$enabled) {
            return [$label, 'SKIP', 'GATEWAY_VERIFY_SIGN=false：未启用验签，可信头一律不信任（仅限本地调试）'];
        }

        $secret = GatewaySigner::internalSecret();
        if ($secret === '') {
            return [$label, 'SKIP', '未配置：网关回源将被 403 SECRET_MISSING，需取 secrets.json 中 workshop 条目'];
        }

        if (in_array(strtolower($secret), self::INTERNAL_PLACEHOLDERS, true)) {
            return [$label, 'FAIL', '仍是占位值，网关签名的请求会一律 403 SIGN_MISMATCH'];
        }

        if (!GatewaySigner::isWellFormedSecret($secret)) {
            return [$label, 'FAIL', '形态不合法（' . strlen($secret) . ' 位，含空白或过短），检查是否被换行截断'];
        }

        $fingerprint = GatewaySigner::secretFingerprint($secret);
        if (strlen($secret) !== 64) {
            // 不判死：本地联调可能用短值；但与网关的 64 位不一致必须点出来
            return [
                $label,
                'FAIL',
                '长度 ' . strlen($secret) . ' 位，网关侧为 64 位 hex；指纹 ' . $fingerprint,
            ];
        }

        $headers = [
            'X-User-Id' => 'usr_probe_selfcheck',
            'X-User-Role' => 'student',
            'X-Client-Id' => 'jy-workshop-web',
            'X-Token-Exp' => (string) (time() + 600),
            'X-Request-Id' => 'probe-selfcheck-' . bin2hex(random_bytes(4)),
            'X-Auth-Mode' => 'token',
            'X-Timestamp' => (string) time(),
        ];
        $headers['X-Internal-Sign'] = GatewaySigner::trustSign($headers, $secret);

        $result = GatewaySigner::verifyTrust($headers, $secret, (int) config('platform.gateway.skew', 300));
        if (!$result['ok']) {
            return [$label, 'FAIL', '本地自洽验签失败：' . $result['reason']];
        }

        return [$label, 'PASS', '自洽验签通过（指纹 ' . $fingerprint . '）；与网关是否同源需真实回源确认'];
    }

    /**
     * @param array $rows
     * @param array $l2
     * @param bool $secretProvided
     * @return int
     */
    private function summarize(array $rows, array $l2, bool $secretProvided): int
    {
        $failed = [];
        $skipped = [];
        foreach ($rows as $row) {
            if ($row[1] === 'FAIL') {
                $failed[] = $row[0];
            } elseif ($row[1] !== 'PASS') {
                // SKIP 不是失败：把"没验证"和"验证不通过"混在一起会误导判断
                $skipped[] = $row[0];
            }
        }

        $this->newLine();

        if (!$failed) {
            $this->info('✓ 接入检查全部通过：网关可达、路由已登记、L2 签名有效。');
            if ($skipped) {
                $this->line('  未验证项（非失败）：' . implode('、', $skipped));
            }
            return 0;
        }

        $this->warn('未通过项：' . implode('、', $failed));

        if (!$secretProvided) {
            $this->line('  提示：AppSecret 未配置，L2 只能探测到"头是否被受理"。');
            $this->line('        补齐 GATEWAY 凭据后重跑本命令即可完成最终验证。');
        }

        if (($l2['code'] ?? 0) === 40108) {
            $this->line('  阻塞：网关未登记当前 AppKey，请在网关侧新增客户端后重试。');
        }

        return 1;
    }

    /**
     * @param string $message
     * @return string
     */
    private function shorten(string $message): string
    {
        return mb_substr($message, 0, 120);
    }
}

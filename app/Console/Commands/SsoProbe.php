<?php

namespace App\Console\Commands;

use App\Services\GatewaySigner;
use App\Services\IdentityProvider;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 统一身份（OAuth / OIDC）对接就绪自检
 *
 * 目的：把「SSO 能不能切」从口头确认变成一条可复跑的命令。
 * 迁移方案（docs/platform/oauth-migration-plan.md）里的三个阻塞项，
 * 有两项（B-2 端点、B-3 凭据）可以在这里被机器判定，不必每次人工 curl。
 *
 * 判定原则：**不看 HTTP 200，看正文是不是真接口**
 *   - 网关 80 端口对未匹配路径回落 APISIX Dashboard 的 SPA，同样回 200 + 6666 字节 HTML。
 *     若只看状态码，会把「端点根本没注册」误判成「已经可用」，这是本项目已经踩过的坑（E-5）。
 *   - 因此对每条路径做正文分类：json / html 登录页 / dashboard 兜底页 / 重定向 / 连接失败。
 *
 * 本命令只读：不发写请求、不建会话、不改配置。--json 供 CI 与脚本消费。
 */
class SsoProbe extends Command
{
    /** APISIX Dashboard 兜底页的两个特征串，同时命中才判定为兜底 */
    private const DASHBOARD_MARKERS = ['You need to enable JavaScript', 'Apache License'];

    /** 明显不能用于生产的占位密钥 */
    private const PLACEHOLDERS = [
        'changeme',
        'change-me',
        'secret',
        'test',
        'local-dev-secret-not-for-prod',
        'todo',
    ];

    /** 回调路径必须与白名单登记的完全一致 */
    private const CALLBACK_SUFFIX = '/api/v1/workshop/auth/oauth/callback';

    protected $signature = 'sso:probe
        {--base= : 身份中心地址，默认取 SSO_BASE_URL，其次 GATEWAY_BASE_URL}
        {--timeout= : 单次探测超时秒数，默认 8}
        {--json : 以 JSON 输出结论，供脚本消费}';

    protected $description = '自检统一身份（OAuth/OIDC）的配置完整性与五条协议端点是否真注册';

    public function handle()
    {
        $json = (bool) $this->option('json');

        $base = $this->resolveBase();
        $timeout = max(2, (int) ($this->option('timeout') ?: 8));

        $rows = [];
        $blockers = [];

        // ---------- 1. 配置完整性 ----------
        $rows[] = $this->checkMode($blockers);
        $rows[] = $this->checkBaseUrl($base, $blockers);
        $rows[] = $this->checkClientId($blockers);
        $rows[] = $this->checkClientSecret($blockers);
        $rows[] = $this->checkRedirectUri($blockers);
        $rows[] = $this->checkMockEnvs($blockers);

        // ---------- 2. 五条协议端点 ----------
        $endpointRows = [];
        if ($base !== '') {
            $endpointRows = $this->probeEndpoints($base, $timeout);
            foreach ($endpointRows as $row) {
                if ($row['blocking']) {
                    $blockers[] = $row['name'];
                }
            }
        }

        $ready = $blockers === [];

        if ($json) {
            $this->outputJson($base, $rows, $endpointRows, $blockers, $ready);
            return $ready ? 0 : 1;
        }

        $this->renderHeader($base);
        $this->table(['配置项', '结果', '说明'], $rows);

        if ($endpointRows !== []) {
            $this->newLine();
            $this->table(
                ['协议端点', '判定', '说明'],
                array_map(function (array $row) {
                    return [$row['name'], $row['verdict'], $row['detail']];
                }, $endpointRows)
            );
        }

        $this->summary($ready, $blockers, $endpointRows);

        return $ready ? 0 : 1;
    }

    // ------------------------------------------------------------------
    // 配置检查
    // ------------------------------------------------------------------

    /**
     * 模式：生产必须是 real；mock 在生产已 fail closed
     *
     * @param array $blockers
     * @return array
     */
    private function checkMode(array &$blockers): array
    {
        $provider = new IdentityProvider();
        $mode = $provider->mode();
        $isProd = app()->environment('production');

        if ($mode === 'real') {
            return ['SSO_MODE', '通过', 'real'];
        }

        if ($isProd && !$provider->isMockAllowed()) {
            $blockers[] = 'SSO_MODE';
            return ['SSO_MODE', '阻塞', '当前为 ' . $mode . '，且本环境不允许 mock（登录会 fail closed）'];
        }

        return ['SSO_MODE', '需切换', '当前为 ' . $mode . '，仅在非生产或显式放行时可用'];
    }

    /**
     * @param string $base
     * @param array $blockers
     * @return array
     */
    private function checkBaseUrl(string $base, array &$blockers): array
    {
        if ($base === '') {
            $blockers[] = 'SSO_BASE_URL';
            return ['SSO_BASE_URL', '阻塞', '未配置：无法拼出授权地址'];
        }
        return ['SSO_BASE_URL', '通过', $base];
    }

    /**
     * @param array $blockers
     * @return array
     */
    private function checkClientId(array &$blockers): array
    {
        $clientId = trim((string) config('platform.sso.client_id', ''));
        if ($clientId === '') {
            $blockers[] = 'SSO_CLIENT_ID';
            return ['SSO_CLIENT_ID', '阻塞', '未配置'];
        }
        return ['SSO_CLIENT_ID', '通过', $clientId];
    }

    /**
     * 客户端密钥：既要"有"，也要"没被误用成网关 L2 凭据"
     *
     * B-3 的典型故障是"授权页能打开、回来就失败"，表面像网络问题，
     * 实际是换码时 invalid_client。这里直接比对同值，提前拦掉。
     *
     * @param array $blockers
     * @return array
     */
    private function checkClientSecret(array &$blockers): array
    {
        $secret = trim((string) config('platform.sso.client_secret', ''));

        if ($secret === '') {
            $blockers[] = 'SSO_CLIENT_SECRET';
            return ['SSO_CLIENT_SECRET', '阻塞', '未配置：换码阶段必然 invalid_client'];
        }

        if (in_array(strtolower($secret), self::PLACEHOLDERS, true)) {
            $blockers[] = 'SSO_CLIENT_SECRET';
            return ['SSO_CLIENT_SECRET', '阻塞', '疑似占位值（长度 ' . strlen($secret) . '），不是真密钥'];
        }

        $sameAs = $this->sameSecretSource($secret);
        if ($sameAs !== '') {
            $blockers[] = 'SSO_CLIENT_SECRET';
            return [
                'SSO_CLIENT_SECRET',
                '阻塞',
                '与 ' . $sameAs . ' 同值：OAuth 客户端密钥属于另一个信任域，不能复用网关 L2 凭据',
            ];
        }

        return [
            'SSO_CLIENT_SECRET',
            '通过',
            '已配置（' . strlen($secret) . ' 位，指纹 ' . GatewaySigner::secretFingerprint($secret) . '）',
        ];
    }

    /**
     * 回调地址必须与身份中心登记的白名单逐字符一致
     *
     * @param array $blockers
     * @return array
     */
    private function checkRedirectUri(array &$blockers): array
    {
        $redirect = trim((string) config('platform.sso.redirect_uri', ''));

        if ($redirect === '') {
            return ['SSO_REDIRECT_URI', '提示', '未显式配置，将按请求域自动推导；跨站场景建议显式写死以对齐白名单'];
        }

        if (substr($redirect, -strlen(self::CALLBACK_SUFFIX)) !== self::CALLBACK_SUFFIX) {
            $blockers[] = 'SSO_REDIRECT_URI';
            return ['SSO_REDIRECT_URI', '阻塞', '结尾不是 ' . self::CALLBACK_SUFFIX . '，与路由不匹配'];
        }

        if (stripos($redirect, 'http://') === 0 && !app()->environment(['local', 'testing'])) {
            return ['SSO_REDIRECT_URI', '风险', $redirect . '（明文 HTTP + SameSite 限制，跨站会话可能「登录成功但页面未登录」）'];
        }

        return ['SSO_REDIRECT_URI', '通过', $redirect];
    }

    /**
     * mock_envs 里绝不能出现 production
     *
     * 不按"当前环境"判断：只要配置里含 production，这份配置一旦被带到线上
     * 就是一道免密登录后门，因此在任何环境跑自检都应拦下。
     *
     * @param array $blockers
     * @return array
     */
    private function checkMockEnvs(array &$blockers): array
    {
        $raw = (string) config('platform.sso.mock_envs', '');
        $envs = array_filter(array_map('trim', explode(',', strtolower($raw))));

        if (in_array('production', $envs, true)) {
            $blockers[] = 'SSO_MOCK_ENVS';
            return ['SSO_MOCK_ENVS', '阻塞', 'production 被列入 mock 白名单：等于一道免密登录后门，必须清掉'];
        }

        return ['SSO_MOCK_ENVS', '通过', $envs === [] ? '(空，任何环境都不允许 mock)' : implode(',', $envs)];
    }

    // ------------------------------------------------------------------
    // 端点探测
    // ------------------------------------------------------------------

    /**
     * @param string $base
     * @param int $timeout
     * @return array<int, array{name:string,verdict:string,detail:string,blocking:bool}>
     */
    private function probeEndpoints(string $base, int $timeout): array
    {
        $discoveryPath = (string) config('platform.sso.discovery_path', '/.well-known/openid-configuration');
        $authorizePath = (string) config('platform.sso.login_path', '/oauth/authorize');
        $tokenPath = (string) config('platform.sso.token_path', '/oauth/token');
        $userinfoPath = (string) config('platform.sso.userinfo_path', '/oauth/userinfo');
        $logoutPath = (string) config('platform.sso.logout_path', '/protocol/logout');

        $rows = [];

        // --- discovery ---
        $r = $this->fetch('GET', $base . $discoveryPath, $timeout);
        $rows[] = $this->judgeDiscovery($r);

        // --- authorize：正常是 HTML 授权页，JSON 也算已注册 ---
        $r = $this->fetch('GET', $base . $authorizePath, $timeout);
        $rows[] = $this->judgeAuthorize($r);

        // --- token：无参 POST 必须给标准 error 报文，给 200 成功反而是异常 ---
        $r = $this->fetch('POST', $base . $tokenPath, $timeout, [
            'grant_type' => 'authorization_code',
            'code' => 'probe',
            'client_id' => (string) config('platform.sso.client_id', ''),
        ]);
        $rows[] = $this->judgeToken($r);

        // --- userinfo：无令牌必须 401 ---
        $r = $this->fetch('GET', $base . $userinfoPath, $timeout);
        $rows[] = $this->judgeUserinfo($r);

        // --- logout ---
        $r = $this->fetch('GET', $base . $logoutPath, $timeout);
        $rows[] = $this->judgeLogout($r);

        return $rows;
    }

    /**
     * @param array $r
     * @return array
     */
    private function judgeDiscovery(array $r): array
    {
        $name = 'discovery';

        if ($r['type'] === 'dashboard') {
            return $this->row($name, '未注册', '返回 APISIX Dashboard 兜底页：该路径没有 API 路由', true);
        }
        if ($r['type'] === 'network') {
            return $this->row($name, '不可达', $r['error'], true);
        }
        if ($r['type'] !== 'json') {
            return $this->row($name, '可选缺失', '未提供发现文档（可跳过，代码会降级到配置路径）', false);
        }

        $missing = [];
        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            if (empty($r['json'][$key])) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            return $this->row($name, '字段不全', 'JSON 合法但缺少 ' . implode(' / ', $missing), true);
        }

        return $this->row($name, '已注册', 'issuer=' . ($r['json']['issuer'] ?? '(未声明)'), false);
    }

    /**
     * @param array $r
     * @return array
     */
    private function judgeAuthorize(array $r): array
    {
        $name = 'authorize';

        if ($r['type'] === 'dashboard') {
            return $this->row($name, '未注册', '返回 APISIX Dashboard 兜底页', true);
        }
        if ($r['type'] === 'network') {
            return $this->row($name, '不可达', $r['error'], true);
        }
        if ($r['type'] === 'json') {
            return $this->row($name, '已注册', 'JSON 响应（缺参时报错属正常）', false);
        }
        if ($r['type'] === 'html') {
            return $this->row($name, '已注册（待确认）', '返回 HTML 页面，符合授权页形态；需人工确认是可登录页而非错误页', false);
        }
        if ($r['type'] === 'redirect') {
            return $this->row($name, '已注册（待确认）', 'HTTP ' . $r['status'] . ' 跳转；确认跳转目标是否为登录页', false);
        }

        return $this->row($name, '待确认', 'HTTP ' . $r['status'] . '，无法归类', true);
    }

    /**
     * @param array $r
     * @return array
     */
    private function judgeToken(array $r): array
    {
        $name = 'token';

        if ($r['type'] === 'dashboard') {
            return $this->row($name, '未注册', '返回 APISIX Dashboard 兜底页', true);
        }
        if ($r['type'] === 'network') {
            return $this->row($name, '不可达', $r['error'], true);
        }
        if ($r['type'] !== 'json') {
            return $this->row($name, '未注册', '未返回 JSON（HTTP ' . $r['status'] . '）：换码接口必须给标准 OAuth 错误体', true);
        }

        $error = (string) ($r['json']['error'] ?? '');
        if ($error === '') {
            return $this->row($name, '待确认', 'HTTP ' . $r['status'] . ' 但缺少 error 字段，不是标准 OAuth 错误体', true);
        }

        return $this->row($name, '已注册', '错误体 error=' . $error . '（无参探测属预期）', false);
    }

    /**
     * @param array $r
     * @return array
     */
    private function judgeUserinfo(array $r): array
    {
        $name = 'userinfo';

        if ($r['type'] === 'dashboard') {
            return $this->row($name, '未注册', '返回 APISIX Dashboard 兜底页', true);
        }
        if ($r['type'] === 'network') {
            return $this->row($name, '不可达', $r['error'], true);
        }
        if ($r['type'] !== 'json') {
            return $this->row($name, '未注册', '未返回 JSON（HTTP ' . $r['status'] . '）', true);
        }
        if ($r['status'] === 200) {
            return $this->row($name, '待确认', '无令牌却返回 200：该端点可能未做鉴权，需核对', true);
        }

        return $this->row($name, '已注册', 'HTTP ' . $r['status'] . '（无令牌被拒属预期）', false);
    }

    /**
     * @param array $r
     * @return array
     */
    private function judgeLogout(array $r): array
    {
        $name = 'logout';

        if ($r['type'] === 'dashboard') {
            return $this->row($name, '未注册', '返回 APISIX Dashboard 兜底页', true);
        }
        if ($r['type'] === 'network') {
            return $this->row($name, '不可达', $r['error'], true);
        }
        if ($r['type'] === 'redirect' || $r['type'] === 'html' || $r['type'] === 'json') {
            return $this->row($name, '已注册', 'HTTP ' . $r['status'] . '（' . $r['type'] . '）', false);
        }

        return $this->row($name, '待确认', 'HTTP ' . $r['status'] . '，无法归类', true);
    }

    // ------------------------------------------------------------------
    // HTTP 与工具
    // ------------------------------------------------------------------

    /**
     * 发一次只读探测请求并归类响应
     *
     * 不重试：探测的目的就是看"当前这一下"的真实状态，重试会掩盖间歇性故障。
     *
     * @param string $method
     * @param string $url
     * @param int $timeout
     * @param array $form
     * @return array{type:string,status:int,json:mixed,error:string}
     */
    private function fetch(string $method, string $url, int $timeout, array $form = []): array
    {
        try {
            $pending = Http::timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->acceptJson();

            $response = $method === 'POST'
                ? $pending->asForm()->post($url, $form)
                : $pending->get($url);
        } catch (ConnectionException $e) {
            return ['type' => 'network', 'status' => 0, 'json' => null, 'error' => $this->shorten($e->getMessage())];
        } catch (\Throwable $e) {
            return ['type' => 'network', 'status' => 0, 'json' => null, 'error' => $this->shorten($e->getMessage())];
        }

        $status = $response->status();
        $body = (string) $response->body();

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return ['type' => 'json', 'status' => $status, 'json' => $decoded, 'error' => ''];
        }

        if ($this->isDashboardPage($body)) {
            return ['type' => 'dashboard', 'status' => $status, 'json' => null, 'error' => ''];
        }

        if ($status >= 300 && $status < 400) {
            return ['type' => 'redirect', 'status' => $status, 'json' => null, 'error' => ''];
        }

        if (stripos($body, '<html') !== false || stripos($body, '<!doctype') !== false || stripos($body, '<!--') === 0) {
            return ['type' => 'html', 'status' => $status, 'json' => null, 'error' => ''];
        }

        return ['type' => 'other', 'status' => $status, 'json' => null, 'error' => ''];
    }

    /**
     * 是否被 APISIX Dashboard 的 SPA 兜底页吃掉
     *
     * 两个特征串同时命中才判定，避免把真正的前端登录页误判为兜底页。
     *
     * @param string $body
     * @return bool
     */
    private function isDashboardPage(string $body): bool
    {
        foreach (self::DASHBOARD_MARKERS as $marker) {
            if (stripos($body, $marker) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * 找出与客户端密钥同值的其它密钥来源
     *
     * @param string $secret
     * @return string 同值时返回来源名，否则空串
     */
    private function sameSecretSource(string $secret): string
    {
        $candidates = [
            'GATEWAY_INTERNAL_SECRET' => (string) config('platform.gateway.internal_secret', ''),
            'FORUM_APP_SECRET' => (string) config('platform.forum.app_secret', ''),
            'EXAM_APP_SECRET' => (string) config('platform.exam.app_secret', ''),
            'SHOWCASE_APP_SECRET' => (string) config('platform.showcase.app_secret', ''),
        ];

        foreach ($candidates as $name => $value) {
            if (trim($value) !== '' && hash_equals(trim($value), $secret)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * @return string
     */
    private function resolveBase(): string
    {
        $base = (string) ($this->option('base') ?: config('platform.sso.base_url', ''));
        if (trim($base) === '') {
            $base = (string) config('platform.gateway.base_url', '');
        }
        return rtrim(trim($base), '/');
    }

    /**
     * @param string $name
     * @param string $verdict
     * @param string $detail
     * @param bool $blocking
     * @return array
     */
    private function row(string $name, string $verdict, string $detail, bool $blocking): array
    {
        return ['name' => $name, 'verdict' => $verdict, 'detail' => $detail, 'blocking' => $blocking];
    }

    /**
     * @param string $base
     * @return void
     */
    private function renderHeader(string $base): void
    {
        $this->line('身份中心 ：' . ($base !== '' ? $base : '(未配置)'));
        $this->line('ClientID ：' . ((string) config('platform.sso.client_id', '') ?: '(未配置)'));
        $this->line('回调地址 ：' . ((string) config('platform.sso.redirect_uri', '') ?: '(自动推导)'));
        $this->line('环境     ：' . app()->environment());
        $this->newLine();
    }

    /**
     * @param bool $ready
     * @param array $blockers
     * @param array $endpointRows
     * @return void
     */
    private function summary(bool $ready, array $blockers, array $endpointRows): void
    {
        $this->newLine();

        if ($ready) {
            $this->info('结论：READY —— 配置与端点均就绪，可切换 SSO_MODE=real 并真跑一次授权。');
            $this->line('  下一步：按迁移方案 §8 的 P3 执行 —— 跳转 → 登录 → 回调 → userinfo 的 uid 是否为十进制。');
            return;
        }

        $this->error('结论：BLOCKED —— 存在 ' . count($blockers) . ' 项阻塞：' . implode('、', $blockers));

        $unregistered = array_filter($endpointRows, function (array $r) {
            return $r['verdict'] === '未注册';
        });
        if ($unregistered !== []) {
            $this->line('  端点未注册通常是被 APISIX Dashboard 的 SPA 兜底页吃掉（HTTP 200 + HTML 6666 字节）。');
            $this->line('  需要网关侧把这些路径登记为 API 路由，详见 docs/platform/sso-onboarding-request.md。');
        }

        if (in_array('SSO_CLIENT_SECRET', $blockers, true)) {
            $this->line('  OAuth 客户端密钥须由身份中心注册签发，与网关 L2 的 AppSecret 不是同一个信任域，不能复用。');
        }
    }

    /**
     * @param string $base
     * @param array $configRows
     * @param array $endpointRows
     * @param array $blockers
     * @param bool $ready
     * @return void
     */
    private function outputJson(string $base, array $configRows, array $endpointRows, array $blockers, bool $ready): void
    {
        $this->line((string) json_encode([
            'ready' => $ready,
            'baseUrl' => $base,
            'environment' => app()->environment(),
            'blockers' => array_values($blockers),
            'config' => array_map(function (array $row) {
                return ['item' => $row[0], 'result' => $row[1], 'detail' => $row[2]];
            }, $configRows),
            'endpoints' => array_map(function (array $row) {
                return [
                    'name' => $row['name'],
                    'verdict' => $row['verdict'],
                    'detail' => $row['detail'],
                    'blocking' => $row['blocking'],
                ];
            }, $endpointRows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param string $message
     * @return string
     */
    private function shorten(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));
        return mb_strlen($message) > 80 ? mb_substr($message, 0, 80) . '…' : $message;
    }
}

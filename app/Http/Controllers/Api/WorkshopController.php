<?php

namespace App\Http\Controllers\Api;

use App\Models\ProjectRecruitment;
use App\Models\User;
use App\Services\ExamClient;
use App\Services\ForumAccountClient;
use App\Services\GatewayIdentity;
use App\Services\GatewaySigner;
use App\Services\IdentityBinder;
use App\Services\IdentityProvider;
use App\Services\PlatformResponse;
use App\Services\WorkshopSession;
use Illuminate\Http\Request;

/**
 * 实训平台对外接口 v1（/api/v1/workshop/*）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §6.1.2、表 6-2。
 *
 * 本控制器只做三件事：协议转换（REST ↔ RPC）、身份与会话、委托给 Service。
 * 业务判断一律复用既有控制器方法（见 forward()），不复制一份逻辑——
 * 复制出来的第二套规则一定会与第一套走偏。
 *
 * 已实现：auth/login、auth/oauth/callback、auth/userinfo、auth/logout、
 *        auth/logged-out、recruitments/{id}/admission、health
 * 别名实现：projects、projects/{id}、recruitments、recruitments/{id}
 */
class WorkshopController extends AbstractController
{
    /**
     * 发起授权（302）
     *
     * GET /api/v1/workshop/auth/login?redirect=/project
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function login(Request $request)
    {
        $redirect = $this->safeRedirect((string) $request->query('redirect', '/'));
        $provider = new IdentityProvider();

        $pkce = $provider->pkcePair();
        $state = WorkshopSession::putState([
            'redirect' => $redirect,
            'verifier' => $pkce['verifier'],
        ]);

        if ($provider->isMock()) {
            // mock 只打通链路：不校验 redirect_uri 精确匹配、不模拟吊销、不校验 PKCE
            $callback = url('/api/v1/workshop/auth/oauth/callback');
            $query = http_build_query([
                'code' => 'mock_' . bin2hex(random_bytes(8)),
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986);

            return redirect($callback . '?' . $query);
        }

        return redirect($provider->authorizeUrl($state, $pkce['challenge']));
    }

    /**
     * 授权回跳（服务端换码 + 建会话 + 302）
     *
     * GET /api/v1/workshop/auth/oauth/callback?code=...&state=...
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function oauthCallback(Request $request)
    {
        $state = trim((string) $request->query('state', ''));
        $code = trim((string) $request->query('code', ''));

        $stored = WorkshopSession::pullState($state);
        if (!$stored) {
            // state 不匹配即中止：可能是 CSRF 或过期的授权请求
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                '授权状态校验失败，请重新登录',
                ['reason' => 'STATE_INVALID'],
                400
            );
        }

        if ($code === '') {
            return PlatformResponse::fail(
                PlatformResponse::VALIDATION_ERROR,
                '缺少授权码 code',
                ['reason' => 'CODE_MISSING'],
                400
            );
        }

        $provider = new IdentityProvider();

        if ($provider->isMock()) {
            $profile = $provider->mockProfile();
        } else {
            $token = $provider->exchangeCode($code, (string) ($stored['verifier'] ?? ''));
            if (!$token['ok']) {
                return PlatformResponse::fail(
                    PlatformResponse::AUTH_REQUIRED,
                    '授权码换取令牌失败，请重新登录',
                    ['reason' => 'TOKEN_EXCHANGE_FAILED', 'error' => $token['error']],
                    401
                );
            }

            $userinfo = $provider->userinfo($token['accessToken']);
            if (!$userinfo['ok']) {
                // 不降级放行：宁可登录失败，也不产生无法对账的临时身份
                return PlatformResponse::fail(
                    PlatformResponse::SERVICE_UNAVAILABLE,
                    '身份中心返回的用户信息不完整，请稍后重试',
                    ['reason' => 'USERINFO_INVALID'],
                    503
                );
            }
            $profile = $userinfo['profile'];
        }

        $binder = new IdentityBinder();
        $result = $binder->bindOrCreate(GatewayIdentity::issuer(), (string) $profile['subject'], $profile);

        if (!$result['ok']) {
            return PlatformResponse::fail($result['code'], $result['message'], [], null);
        }

        /** @var User $user */
        $user = $result['user'];

        // 会话固定防护：登录成功即换新 sid，旧 sid 立即删除
        $previous = (string) $request->cookie(WorkshopSession::cookieName(), '');
        if ($previous !== '') {
            WorkshopSession::destroy($previous);
        }

        $sid = WorkshopSession::create([
            'userid' => (int) $user->userid,
            'subject' => (string) $profile['subject'],
            'roles' => $profile['roles'] ?? [],
            'client_id' => (string) config('platform.sso.client_id', ''),
            'mode' => 'user',
        ]);

        $response = redirect($this->safeRedirect((string) ($stored['redirect'] ?? '/')));
        WorkshopSession::attachCookie($response, $sid);

        return $response;
    }

    /**
     * 论坛账号确认登录（密码代验式）
     *
     * POST /api/v1/workshop/auth/forum-verify
     * 请求：{ login, pass, login_type? }
     * 成功：200 + Set-Cookie，data 含 uid / forum_uid（论坛 user.id 原值）、角色、会话时长
     *
     * 依据《警院论坛账号确认接口 · 接口说明与四平台接入指南》v2.2.0 §五第 3 步：
     * "只在 confirmed === true 时建立平台会话"。
     *
     * 与 auth/login（OAuth）是**两条独立入口**，会话结构完全一致，下游无需区分：
     *   - auth/login        → 跳身份中心，平台不接触密码（终态目标）
     *   - auth/forum-verify → 本地表单提交论坛账号，平台经网关代验（过渡方案）
     *
     * 刻意不做的事：
     *   1. 不重试 —— 文档 §四：签名类失败禁原样重试，密码类失败重试等同撞库；
     *   2. 不把对端的 role 写进本地 identity —— 本地权限体系独立，混用会越权；
     *   3. 不拿 e_mail_masked 建号 —— 它形如 a***@jspi.edu.cn，不是合法邮箱。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forumVerify(Request $request)
    {
        $login = trim((string) $request->input('login', ''));
        $password = (string) $request->input('pass', '');
        $loginType = (string) $request->input('login_type', 'auto');
        $ip = (string) $request->ip();

        $client = new ForumAccountClient();

        // 撞库防护：同一账号 + IP 连续失败达阈值即锁定，先于任何出站请求
        $locked = $client->lockRemaining($login, $ip);
        if ($locked > 0) {
            return PlatformResponse::fail(
                PlatformResponse::SERVICE_UNAVAILABLE,
                '尝试次数过多，请稍后再试',
                [
                    'reason' => ForumAccountClient::REASON_RATE_LIMITED,
                    'retryAfter' => $locked,
                ],
                429
            )->header('Retry-After', (string) $locked);
        }

        $result = $client->verify($login, $password, $loginType, PlatformResponse::requestId());

        if (!$result['ok']) {
            // 只有"确认未通过"才计入节流；渠道故障不计，否则一次网关抖动会锁住正常用户
            if (in_array($result['reason'], [
                ForumAccountClient::REASON_INVALID_CREDENTIALS,
                ForumAccountClient::REASON_ACCOUNT_UNAVAILABLE,
            ], true)) {
                $client->recordFailure($login, $ip);
            }

            return PlatformResponse::fail(
                $result['code'],
                $result['message'],
                [
                    'reason' => $result['reason'],
                    // 网关原始码原样带回：联调时一眼看出卡在网关还是论坛
                    'gatewayCode' => (int) $result['gatewayCode'],
                    'source' => $result['source'],
                ],
                (int) $result['status']
            );
        }

        $client->clearFailures($login, $ip);

        $profile = $result['profile'];
        $subject = (string) ($profile['subject'] ?? '');
        if ($subject === '') {
            // 对端说 confirmed 却不给 user_id：身份不完整，宁可不建号
            return PlatformResponse::fail(
                PlatformResponse::SERVICE_UNAVAILABLE,
                '账号确认结果缺少用户标识，请联系管理员',
                ['reason' => 'SUBJECT_MISSING'],
                503
            );
        }

        $displayName = (string) ($profile['displayName'] ?? '');
        if ($displayName === '') {
            $displayName = (string) ($profile['username'] ?? '');
        }

        $binder = new IdentityBinder();
        $bind = $binder->bindOrCreate(
            (string) config('platform.forum.issuer', 'jingyuan-forum'),
            $subject,
            ['nickname' => $displayName]
        );

        if (!$bind['ok']) {
            $data = ['reason' => 'BIND_FAILED'];
            // 与成功路径同样显式回传来源：否则 mock 下"uid 不合规"会被
            // 误读成"接入口径不对"，联调时多绕一圈
            if ($result['source'] === ForumAccountClient::SOURCE_MOCK) {
                $data['mock'] = true;
            }
            $data['source'] = $result['source'];

            return PlatformResponse::fail($bind['code'], $bind['message'], $data, null);
        }

        /** @var User $user */
        $user = $bind['user'];

        // 会话固定防护：登录成功即换新 sid，旧 sid 立即删除
        $previous = (string) $request->cookie(WorkshopSession::cookieName(), '');
        if ($previous !== '') {
            WorkshopSession::destroy($previous);
        }

        $forumRole = (string) ($profile['role'] ?? '');

        $sid = WorkshopSession::create([
            'userid' => (int) $user->userid,
            'subject' => $subject,
            'roles' => $forumRole !== '' ? [$forumRole] : [],
            'client_id' => (string) config('platform.forum.client_id', ''),
            'mode' => 'user',
            // 便于按入口排查：同一用户可能从 OAuth 或 forum-verify 进来
            'auth_source' => 'forum_verify',
        ]);

        $data = [
            // 对外唯一身份：forum_uid，即论坛 user.id 十进制原值（R1/R2）。
            // 本地主键与之相等，故不存在"平台侧独立标识"，也不回传 local_user_id。
            'uid' => $subject,
            'forum_uid' => $subject,
            'uid_source' => 'forum',
            'platform_user_id' => null,
            'platform_id_generated' => false,
            'username' => (string) ($profile['username'] ?? ''),
            'displayName' => $displayName,
            'role' => $forumRole,
            'emailMasked' => (string) ($profile['emailMasked'] ?? ''),
            'accountSource' => (string) ($profile['accountSource'] ?? ''),
            'created' => (bool) $bind['created'],
            'session' => [
                'idleExpiresIn' => WorkshopSession::idleTtl(),
                'absoluteExpiresIn' => WorkshopSession::absTtl(),
            ],
            'source' => $result['source'],
        ];

        // mock 必须显式回传：绝不让"本地能登录"被误认为"接入验证通过"
        if ($result['source'] === ForumAccountClient::SOURCE_MOCK) {
            $data['mock'] = true;
        }

        $response = PlatformResponse::ok($data, '账号确认通过');
        WorkshopSession::attachCookie($response, $sid);

        return $response;
    }

    /**
     * 当前身份（字段对齐 sub）
     *
     * GET /api/v1/workshop/auth/userinfo
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function userinfo(Request $request)
    {
        $identity = GatewayIdentity::resolve($request);
        if (!$identity->authenticated()) {
            return PlatformResponse::authRequired('请登录后继续...', ['reason' => 'AUTH_REQUIRED']);
        }

        $user = $identity->user;
        $session = WorkshopSession::resolve($request);

        $subject = $identity->subject;
        if ($subject === '') {
            // 存量 token 场景没有 sub：明确告知对端，不要拿本地 userid 冒充
            $subject = '';
        }

        return PlatformResponse::ok([
            // 对外唯一身份：forum_uid，即论坛 user.id 十进制原值（R1/R2）。
            // 本地主键与之相等，故不回传 local_user_id 之类的"平台侧标识"。
            'uid' => $subject,
            'forum_uid' => $subject,
            'uid_source' => $subject !== '' ? 'forum' : null,
            'platform_user_id' => null,
            'platform_id_generated' => false,
            'roles' => $identity->roles,
            'nickname' => $user ? (string) $user->nickname : '',
            'email' => $user ? (string) $user->email : '',
            'avatar' => $user ? (string) $user->userimg : '',
            'source' => $identity->source,
            'authMode' => $identity->mode,
            'clientId' => $identity->clientId,
            'session' => [
                'active' => !empty($session['data']),
                'expiresIn' => !empty($session['data'])
                    ? max(0, (int) $session['data']['created_at'] + WorkshopSession::absTtl() - time())
                    : 0,
            ],
        ]);
    }

    /**
     * 退出登录
     *
     * POST /api/v1/workshop/auth/logout
     *
     * 顺序不可颠倒：先删本地会话，再让浏览器跳身份中心登出（§4.5 退出行）。
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $sid = (string) $request->cookie(WorkshopSession::cookieName(), '');
        if ($sid !== '') {
            WorkshopSession::destroy($sid);
        }

        $provider = new IdentityProvider();

        $response = PlatformResponse::ok([
            'logoutUrl' => $provider->isMock() ? '' : $provider->logoutUrl(),
            'mock' => $provider->isMock(),
        ], '已退出登录');

        return WorkshopSession::clearCookie($response);
    }

    /**
     * 身份中心登出后的回跳页（不依赖登录态）
     *
     * GET /api/v1/workshop/auth/logged-out
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function loggedOut()
    {
        return PlatformResponse::ok([
            'loggedOut' => true,
            'message' => '已退出统一身份',
        ], '已退出登录');
    }

    /**
     * 招募准入校验结果（回源 + 缓存）
     *
     * GET /api/v1/workshop/recruitments/{id}/admission
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function admission(Request $request, $id)
    {
        $identity = GatewayIdentity::resolve($request);
        if (!$identity->authenticated()) {
            return PlatformResponse::authRequired('请登录后继续...', ['reason' => 'AUTH_REQUIRED']);
        }

        $recruitment = ProjectRecruitment::find(intval($id));
        if (!$recruitment) {
            return PlatformResponse::fail(
                PlatformResponse::NOT_FOUND,
                '招募不存在',
                ['reason' => 'RECRUITMENT_NOT_FOUND'],
                404
            );
        }

        // 权限点 admission:read（§7.3）：发布者、管理员、或本人
        $isOwner = (int) $recruitment->publisher_id === $identity->userid;
        $isAdmin = $identity->hasRole('admin')
            || ($identity->user && User::identityRaw('admin', $identity->user->identity));
        if (!$isOwner && !$isAdmin) {
            return PlatformResponse::fail(
                PlatformResponse::FORBIDDEN,
                '无权查看该招募的准入结果',
                ['reason' => 'ADMISSION_FORBIDDEN'],
                403
            );
        }

        $courseId = trim((string) ($recruitment->course_id ?? ''));
        if ($courseId === '') {
            // 未绑定课程 = 该招募不校验准入（§10.4.2）
            return PlatformResponse::ok([
                'recruitmentId' => (int) $recruitment->id,
                'courseId' => '',
                'required' => false,
                'eligible' => true,
                'reason' => 'not_required',
                'reasonText' => '该招募未绑定课程，不校验考试准入',
                'score' => null,
                'source' => 'local',
                'cached' => false,
                'checkedAt' => (new \DateTimeImmutable('now'))->format('c'),
            ]);
        }

        $subject = $identity->subject;
        if ($subject === '') {
            return PlatformResponse::fail(
                PlatformResponse::CONFLICT,
                '当前账号未绑定统一身份 sub，无法查询考试准入',
                ['reason' => 'SUBJECT_NOT_BOUND'],
                409
            );
        }

        $client = new ExamClient();
        $result = $client->admission($subject, $courseId, PlatformResponse::requestId());

        return PlatformResponse::ok([
            'recruitmentId' => (int) $recruitment->id,
            'courseId' => $courseId,
            'subject' => $subject,
            'required' => true,
            'eligible' => (bool) $result['eligible'],
            'reason' => (string) $result['reason'],
            'reasonText' => ExamClient::reasonMessage((string) $result['reason']),
            'score' => $result['score'],
            'ruleVersion' => $result['ruleVersion'],
            'source' => $result['source'],
            'cached' => (bool) $result['cached'],
            'checkedAt' => $result['checkedAt'],
            'mock' => (bool) ($result['mock'] ?? false),
        ]);
    }

    /**
     * 健康检查
     *
     * GET /api/v1/workshop/health
     *
     * 只做探活与能力自述，不返回任何密钥、路径或连接串。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function health()
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
        ];

        $ssoMode = (new IdentityProvider())->mode();
        $examMock = $this->mockFlag('platform.exam.mock', 'platform.exam.base_url');
        $showcaseMock = $this->mockFlag('platform.showcase.mock', 'platform.showcase.base_url');

        // 论坛账号确认：只暴露"通道处于哪种状态"，不回显 AppKey / AppSecret
        $forumClient = new ForumAccountClient();
        $forumBase = rtrim((string) config('platform.forum.base_url', ''), '/');
        $forumSecretSet = trim((string) config('platform.forum.app_secret', '')) !== '';

        $status = in_array('down', $checks, true) ? 'degraded' : 'ok';

        // 网关接入状态：只暴露"是否已配置"与协议版本，绝不回显密钥本身
        $gatewayBase = rtrim((string) config('platform.gateway.base_url', ''), '/');

        // 入站密钥：只报"有没有 / 形态对不对 / 从哪读的"，不报值、不报指纹（health 是公开路径）
        $internalSecret = GatewaySigner::internalSecret();
        $internalSecretSource = 'none';
        if (trim((string) config('platform.gateway.internal_secret', '')) !== '') {
            $internalSecretSource = 'env';
        } elseif ($internalSecret !== '') {
            $internalSecretSource = 'file';
        }

        return PlatformResponse::ok([
            'status' => $status,
            'service' => (string) config('platform.app_code', 'workshop'),
            'name' => (string) config('app.name', '警务实训平台'),
            'version' => (string) env('APP_VERSION', 'dev'),
            'buildTime' => (string) env('APP_BUILD_TIME', ''),
            // 便于联调核对"本地通了线上不通"的差异
            'capabilities' => [
                'ssoMode' => $ssoMode,
                'examMock' => $examMock,
                'showcaseMock' => $showcaseMock,
                'signVerify' => $this->boolConfig('platform.gateway.verify_sign', true),
                'cookieName' => WorkshopSession::cookieName(),
                'secureCookie' => WorkshopSession::secure(),
                'idempotency' => $this->boolConfig('platform.idempotency.enabled', true),
            ],
            'forumVerify' => [
                'enabled' => $forumClient->enabled(),
                // mock=true 表示"确认"是本地假象，不能当成接入验证通过
                'mock' => $forumClient->mockEnabled(),
                'configured' => $forumBase !== '' && $forumSecretSet,
                'path' => (string) config('platform.forum.verify_path', '/answer/api/v1/account/verify'),
                'timeoutMs' => (int) config('platform.forum.timeout_ms', 2500),
            ],
            'gateway' => [
                'configured' => $gatewayBase !== '',
                'baseUrl' => $gatewayBase,
                // L2 规范串版本决定出站签名能否被对端验过，联调时先看这一项
                'l2Version' => GatewaySigner::l2Version(),
                // 本平台作为上游时，验 X-Internal-Sign 用的密钥是否已注入
                'internalSecretSet' => $internalSecret !== '',
                // 已注入 ≠ 能用：带空格/被换行截断/只填半截时会表现为"所有回源 403"
                'internalSecretValid' => $internalSecret !== '' && GatewaySigner::isWellFormedSecret($internalSecret),
                // 读取来源，便于排查"改了 .env 为什么不生效"（容器里可能挂在文件上）
                'internalSecretSource' => $internalSecretSource,
                'directAccess' => (string) config('platform.gateway.direct_access', 'reject'),
            ],
            'checks' => $checks,
            'time' => (new \DateTimeImmutable('now'))->format('c'),
            'timestamp' => time(),
        ]);
    }

    /**
     * 项目列表（REST 别名）
     *
     * GET /api/v1/workshop/projects?page=1&pageSize=20
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function projects(Request $request)
    {
        return $this->forward(ProjectController::class, 'lists', [
            'page' => (int) $request->query('page', 1),
            'pagesize' => (int) $request->query('pageSize', $request->query('pagesize', 20)),
            'keyword' => (string) $request->query('keyword', ''),
            'archived' => $request->query('archived', ''),
        ]);
    }

    /**
     * 项目详情（REST 别名）
     *
     * GET /api/v1/workshop/projects/{id}
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function projectOne(Request $request, $id)
    {
        return $this->forward(ProjectController::class, 'one', [
            'project_id' => intval($id),
        ]);
    }

    /**
     * 招募列表（REST 别名）
     *
     * GET /api/v1/workshop/recruitments?page=1&pageSize=20
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function recruitments(Request $request)
    {
        return $this->forward(RecruitmentController::class, 'lists', [
            'page' => (int) $request->query('page', 1),
            'pagesize' => (int) $request->query('pageSize', $request->query('pagesize', 20)),
            'status' => (string) $request->query('status', ''),
            'keyword' => (string) $request->query('keyword', ''),
        ]);
    }

    /**
     * 招募详情（REST 别名）
     *
     * GET /api/v1/workshop/recruitments/{id}
     *
     * @param Request $request
     * @param int|string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function recruitmentOne(Request $request, $id)
    {
        return $this->forward(RecruitmentController::class, 'one', [
            'recruitment_id' => intval($id),
        ]);
    }

    /**
     * 转发到既有 RPC 控制器方法
     *
     * 复用现有业务逻辑（含权限与数据归属判定），只做两件事：
     *   1. 把 v1 的 camelCase 参数拍平成 RPC 认识的字段；
     *   2. 把当前身份桥接成该控制器依赖的 token 头。
     *
     * 桥接是临时的：等前端整体切到会话 Cookie 后，User::auth() 会改为读会话，
     * 这里的 injectToken() 即可删除（扩展点标记 [SESSION-MIGRATION]）。
     *
     * @param string $controller
     * @param string $method
     * @param array $params
     * @return \Illuminate\Http\JsonResponse
     */
    private function forward(string $controller, string $method, array $params)
    {
        $identity = GatewayIdentity::resolve(request());
        if (!$identity->authenticated()) {
            return PlatformResponse::authRequired('请登录后继续...', ['reason' => 'AUTH_REQUIRED']);
        }

        $this->injectToken($identity);

        foreach ($params as $key => $value) {
            request()->merge([$key => $value]);
            request()->query->set($key, $value);
        }

        try {
            /** @var AbstractController $instance */
            $instance = app($controller);
            $result = $instance->$method();
        } catch (\Throwable $e) {
            return PlatformResponse::fail(
                PlatformResponse::INTERNAL_ERROR,
                $e->getMessage() ?: '服务器内部错误',
                ['reason' => 'RPC_FORWARD_FAILED'],
                500
            );
        }

        return $this->wrapRpcResult($result);
    }

    /**
     * 把当前身份桥接为 token 头（[SESSION-MIGRATION] 扩展点）
     *
     * @param GatewayIdentity $identity
     * @return void
     */
    private function injectToken(GatewayIdentity $identity): void
    {
        $user = $identity->user ?: User::whereUserid($identity->userid)->first();
        if (!$user) {
            return;
        }

        // 用副本生成 token：User::token() 会 unset encrypt/password，不能污染共享实例
        $copy = clone $user;
        $token = User::token($copy);

        request()->headers->set('token', $token);
        request()->merge(['token' => $token]);

        // 清掉可能在本次请求内已缓存的认证结果，让 User::auth() 重新按新 token 解析
        global $_A;
        if (is_array($_A) || !isset($_A)) {
            unset($_A['__static_auth']);
        }
    }

    /**
     * RPC 结果 → v1 信封
     *
     * @param mixed $result
     * @return \Illuminate\Http\JsonResponse
     */
    private function wrapRpcResult($result)
    {
        if (!is_array($result) || !array_key_exists('ret', $result)) {
            return PlatformResponse::ok($this->normalizePagination($result));
        }

        if ((int) $result['ret'] > 0) {
            return PlatformResponse::ok(
                $this->normalizePagination($result['data'] ?? []),
                (string) ($result['msg'] ?? 'success')
            );
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $msg = (string) ($result['msg'] ?? '操作失败');

        // 明确的无权限/不存在语义映射到规范码，其余归为业务拒绝
        if (strpos($msg, '无权') !== false) {
            return PlatformResponse::fail(PlatformResponse::FORBIDDEN, $msg, $data + ['reason' => 'FORBIDDEN'], 403);
        }
        if (stripos($msg, '404 not found') === 0) {
            return PlatformResponse::fail(PlatformResponse::NOT_FOUND, $msg, $data + ['reason' => 'NOT_FOUND'], 404);
        }

        return PlatformResponse::businessError($msg, $data);
    }

    /**
     * 把 Laravel 原生分页结构规范为契约结构（§6.6）
     *
     * 契约要求 `{lists, total, page, pagesize}`；存量 RPC 返回的是
     * `{current_page, data, per_page, total, links...}`。v1 是对外口径，
     * 因此在这里做一次转换，而不是要求接入方适配框架细节。
     *
     * @param mixed $data
     * @return mixed
     */
    private function normalizePagination($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        if (!array_key_exists('current_page', $data) || !array_key_exists('data', $data) || !is_array($data['data'])) {
            return $data;
        }

        return [
            'lists' => array_values($data['data']),
            'total' => (int) ($data['total'] ?? 0),
            'page' => (int) ($data['current_page'] ?? 1),
            'pagesize' => (int) ($data['per_page'] ?? count($data['data'])),
        ];
    }

    /**
     * 只允许站内相对路径回跳，避免开放重定向
     *
     * @param string $redirect
     * @return string
     */
    private function safeRedirect(string $redirect): string
    {
        $redirect = trim($redirect);
        if ($redirect === '' || $redirect[0] !== '/' || strpos($redirect, '//') === 0) {
            return '/';
        }
        if (strpos($redirect, '\\') !== false) {
            return '/';
        }
        return $redirect;
    }

    /**
     * @return string
     */
    private function checkDatabase(): string
    {
        try {
            \Illuminate\Support\Facades\DB::select('SELECT 1');
            return 'ok';
        } catch (\Throwable $e) {
            return 'down';
        }
    }

    /**
     * @return string
     */
    private function checkCache(): string
    {
        try {
            \Illuminate\Support\Facades\Cache::put('health:probe', '1', 10);
            return \Illuminate\Support\Facades\Cache::get('health:probe') === '1' ? 'ok' : 'degraded';
        } catch (\Throwable $e) {
            return 'degraded';
        }
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

    /**
     * 判定某平台客户端是否处于 mock
     *
     * 必须与 ExamClient / ShowcaseClient 的 mockEnabled() 同构，否则健康检查会
     * 报"未 mock"而实际仍在 mock（或反之），联调时极难判断。
     * 规则：显式配置了 mock 开关就以它为准，否则以 base_url 是否为空为准。
     *
     * @param string $mockKey
     * @param string $baseKey
     * @return bool
     */
    private function mockFlag(string $mockKey, string $baseKey): bool
    {
        $value = config($mockKey, null);
        if ($value !== null && $value !== '') {
            return $this->boolConfig($mockKey, false);
        }
        return trim((string) config($baseKey, '')) === '';
    }
}

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
use Illuminate\Support\Facades\Log;

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

        // mock 只允许在本地/测试环境（理由见 oauthCallback）。放在生成 state 之前，
        // 免得被拦下时还在缓存里留一条 10 分钟的空授权记录。
        if ($provider->isMock() && !$provider->isMockAllowed()) {
            Log::error('[sso] mock mode blocked outside allowed environments', [
                'env' => app()->environment(),
            ]);
            return $this->ssoFailure(
                'SSO_NOT_CONFIGURED',
                '统一身份未配置，暂不能登录，请联系管理员',
                503
            );
        }

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
        $error = trim((string) $request->query('error', ''));

        // ① 身份中心拒绝授权（用户在授权页点「取消」，或 client_id / redirect_uri 不被接受）。
        //    RFC 6749 §4.1.2.1：此时回跳带 error、不带 code。必须先判它——
        //    否则会被下面的 code 判空截住，把「用户主动取消」误报成「缺少授权码」，
        //    于是用户看到一条技术故障，运维收到一个不存在的告警。
        if ($error !== '') {
            // state 仍然一次性消费掉，避免被重放；但不参与判定：
            // 用户已明确终止授权，此刻再抛「state 失效」只会让人更困惑。
            WorkshopSession::pullState($state);
            Log::info('[sso] authorize rejected', ['error' => $error]);

            return $this->ssoFailure(
                $error === 'access_denied' ? 'ACCESS_DENIED' : 'AUTHORIZE_REJECTED',
                $error === 'access_denied' ? '已取消登录' : '身份中心拒绝了本次授权',
                400,
                ['upstream' => $error]
            );
        }

        $stored = WorkshopSession::pullState($state);
        if (!$stored) {
            // state 不匹配即中止：可能是 CSRF 或过期的授权请求
            return $this->ssoFailure('STATE_INVALID', '授权状态校验失败，请重新登录', 400);
        }

        if ($code === '') {
            return $this->ssoFailure('CODE_MISSING', '缺少授权码 code', 400);
        }

        $provider = new IdentityProvider();

        if ($provider->isMock()) {
            // mock 只允许在本地/测试环境。生产若误留 SSO_MODE=mock，
            // 任何人访问 auth/login 都会拿到一个"演示身份"的会话——
            // 一旦有人把 SSO_MOCK_SUB 配成数字（如 1），那就是一个免密登录后门。
            // 因此这里 fail closed：宁可登录不可用，也不接受假身份。
            if (!$provider->isMockAllowed()) {
                Log::error('[sso] mock mode blocked outside allowed environments', [
                    'env' => app()->environment(),
                ]);
                return $this->ssoFailure(
                    'SSO_NOT_CONFIGURED',
                    '统一身份未配置，暂不能登录，请联系管理员',
                    503
                );
            }
            $profile = $provider->mockProfile();
        } else {
            $token = $provider->exchangeCode($code, (string) ($stored['verifier'] ?? ''));
            if (!$token['ok']) {
                // 区分「够不着」与「被拒绝」：前者是网络/平台故障(503)，后者是
                // 凭据或授权码问题(401)。混成一个码，运维会把 invalid_client
                // 当网络抖动反复重试，永远修不好。
                $unreachable = $token['error'] === 'token_endpoint_unreachable'
                    || (int) ($token['status'] ?? 0) >= 500;

                return $unreachable
                    ? $this->ssoFailure('TOKEN_ENDPOINT_UNREACHABLE', '身份中心暂时不可用，请稍后重试', 503, ['error' => $token['error']])
                    : $this->ssoFailure('TOKEN_EXCHANGE_FAILED', '授权码换取令牌失败，请重新登录', 401, ['error' => $token['error']]);
            }

            $userinfo = $provider->userinfo($token['accessToken']);
            if (!$userinfo['ok']) {
                // 不降级放行：宁可登录失败，也不产生无法对账的临时身份
                $unreachable = $userinfo['error'] === 'userinfo_unreachable'
                    || (int) ($userinfo['status'] ?? 0) >= 500;

                return $this->ssoFailure(
                    $unreachable ? 'USERINFO_UNREACHABLE' : 'USERINFO_INVALID',
                    $unreachable
                        ? '身份中心暂时不可用，请稍后重试'
                        : '身份中心返回的用户信息不完整，请联系管理员',
                    503,
                    ['error' => $userinfo['error']]
                );
            }
            $profile = $userinfo['profile'];
        }

        $binder = new IdentityBinder();
        $result = $binder->bindOrCreate(GatewayIdentity::issuer(), (string) $profile['subject'], $profile);

        if (!$result['ok']) {
            // 绑定失败最常见的原因是 uid 不合规（R1/R2）。必须原样暴露 reason：
            // 否则会被当成「登录超时」反复重试，而它重试一万次也不会成功。
            return $this->ssoFailure(
                'BIND_FAILED',
                (string) $result['message'],
                400,
                ['code' => (string) $result['code']]
            );
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

        $ssoProvider = new IdentityProvider();
        $ssoMode = $ssoProvider->mode();
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
                // 三项都齐才可能换码成功。只报布尔值，绝不回显 client_secret 本身，
                // 连长度都不报——health 是公开路径。
                'ssoConfigured' => (string) config('platform.sso.client_id', '') !== ''
                    && (string) config('platform.sso.client_secret', '') !== ''
                    && (string) config('platform.sso.base_url', '') !== '',
                // mock 在当前环境是否被允许：ssoMode=mock 且此值为 false 时，
                // 登录会被直接拒绝（fail closed），这里提前暴露便于排查"为什么登录不通"
                'ssoMockAllowed' => $ssoProvider->isMockAllowed(),
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
    /**
     * 登录失败原因 → 面向用户的文案
     *
     * 只做查表，不拼接任何请求参数：错误页永不回显用户可控内容，
     * 因此不存在反射型 XSS 面（连转义都不依赖）。
     */
    private const SSO_ERROR_MESSAGES = [
        'ACCESS_DENIED' => '你已取消登录。如需继续，请重新发起登录。',
        'AUTHORIZE_REJECTED' => '身份中心拒绝了本次授权，请联系管理员核对客户端配置。',
        'STATE_INVALID' => '本次登录已超时或校验失败，请重新发起登录。',
        'CODE_MISSING' => '身份中心未返回授权码，请重新发起登录。',
        'TOKEN_ENDPOINT_UNREACHABLE' => '身份中心暂时无法访问，请稍后重试。',
        'TOKEN_EXCHANGE_FAILED' => '授权码校验未通过（可能已过期），请重新发起登录。',
        'USERINFO_UNREACHABLE' => '身份中心暂时无法访问，请稍后重试。',
        'USERINFO_INVALID' => '身份中心未返回有效的用户标识，请联系管理员。',
        'BIND_FAILED' => '账号与论坛身份绑定失败，请联系管理员。',
        'SSO_NOT_CONFIGURED' => '统一身份尚未配置，暂不能通过该方式登录，请联系管理员。',
    ];

    /** 属于「服务端暂时不可用」的原因：错误页回 503，便于监控与告警区分 */
    private const SSO_ERROR_SERVICE_DOWN = [
        'TOKEN_ENDPOINT_UNREACHABLE',
        'USERINFO_UNREACHABLE',
        'SSO_NOT_CONFIGURED',
    ];

    /**
     * 登录失败落地页
     *
     * GET /api/v1/workshop/auth/login-failed?reason=...
     *
     * 独立成页而不是跳首页：首页收到 ?reason= 也没人渲染，用户只会看到
     * 「莫名其妙回到首页、还是没登录」，比明确报错更糟。
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function loginFailed(Request $request)
    {
        $reason = (string) $request->query('reason', '');
        $known = array_key_exists($reason, self::SSO_ERROR_MESSAGES);
        $message = $known ? self::SSO_ERROR_MESSAGES[$reason] : '登录未完成，请重新发起登录。';
        $status = in_array($reason, self::SSO_ERROR_SERVICE_DOWN, true) ? 503 : 400;

        $retry = e(url('/api/v1/workshop/auth/login'));
        $home = e(url('/'));
        $reasonText = e($known ? $reason : 'UNKNOWN');
        $messageText = e($message);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录未完成</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 24px; background: #f5f6f8; color: #1f2329;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
  }
  .card {
    width: 100%; max-width: 460px; background: #fff; border-radius: 14px;
    padding: 32px 28px; box-shadow: 0 8px 28px rgba(15, 23, 42, .08);
  }
  .badge {
    display: inline-block; font-size: 12px; letter-spacing: .04em; color: #5b6472;
    background: #eef1f5; border-radius: 999px; padding: 4px 10px; margin-bottom: 14px;
  }
  h1 { font-size: 20px; margin: 0 0 12px; font-weight: 600; }
  .msg { font-size: 15px; line-height: 1.7; margin: 0 0 14px; color: #33383f; }
  .code { font-size: 13px; margin: 0 0 22px; color: #6b7280; }
  .code code {
    background: #f2f4f7; border-radius: 6px; padding: 2px 7px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #42474e;
  }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; }
  .btn {
    flex: 1 1 auto; min-width: 132px; text-align: center; text-decoration: none;
    border: 1px solid #d5dae2; border-radius: 9px; padding: 11px 16px;
    font-size: 14px; color: #2b3038; background: #fff;
  }
  .btn.primary { background: #2f6fed; border-color: #2f6fed; color: #fff; font-weight: 500; }
  .hint { margin: 20px 0 0; font-size: 12px; line-height: 1.6; color: #8a929e; }
  @media (prefers-color-scheme: dark) {
    body { background: #16181d; color: #e6e8ec; }
    .card { background: #1e2127; box-shadow: none; border: 1px solid #2b2f38; }
    .badge { background: #262a32; color: #a6adba; }
    .msg { color: #d3d7de; }
    .code { color: #9aa1ad; }
    .code code { background: #262a32; color: #cfd4dc; }
    .btn { background: #23262d; border-color: #333843; color: #dfe3ea; }
    .btn.primary { background: #3b76ef; border-color: #3b76ef; color: #fff; }
    .hint { color: #848c99; }
  }
</style>
</head>
<body>
  <main class="card">
    <span class="badge">统一身份登录</span>
    <h1>登录未完成</h1>
    <p class="msg">{$messageText}</p>
    <p class="code">错误码：<code>{$reasonText}</code></p>
    <div class="actions">
      <a class="btn primary" href="{$retry}">重新登录</a>
      <a class="btn" href="{$home}">返回首页</a>
    </div>
    <p class="hint">如反复失败，请把上面的错误码告知管理员。</p>
  </main>
</body>
</html>
HTML;

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * 统一登录失败出口
     *
     * 浏览器（Accept 含 text/html）→ 302 到错误落地页，不把裸 JSON 甩给用户；
     * 其他客户端（curl / 脚本 / 默认 `*​/*`）→ 保持原 JSON 信封，不破坏既有联调脚本。
     *
     * @param string $reason
     * @param string $message
     * @param int $status
     * @param array $extra 仅用于 JSON 分支，跳转时一律丢弃（避免把上游原文反射进 URL）
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private function ssoFailure(string $reason, string $message, int $status, array $extra = [])
    {
        if ($this->prefersHtml()) {
            $query = http_build_query(['reason' => $reason], '', '&', PHP_QUERY_RFC3986);
            return redirect('/api/v1/workshop/auth/login-failed?' . $query);
        }

        return PlatformResponse::fail(
            $this->codeForStatus($status),
            $message,
            array_merge(['reason' => $reason], $extra),
            $status
        );
    }

    /**
     * 是否按浏览器处理
     *
     * 只有显式声明 text/html 才走页面跳转。curl 默认发 `*​/*`，仍拿 JSON，
     * 所以既有的联调脚本、探针、监控探活都不受影响。
     *
     * @return bool
     */
    private function prefersHtml(): bool
    {
        if (strtolower((string) request()->query('format', '')) === 'json') {
            return false;
        }

        $accept = strtolower((string) request()->headers->get('Accept', ''));
        return strpos($accept, 'text/html') !== false;
    }

    /**
     * HTTP 状态 → 规范业务码
     *
     * @param int $status
     * @return string
     */
    private function codeForStatus(int $status): string
    {
        switch ($status) {
            case 401:
                return PlatformResponse::AUTH_REQUIRED;
            case 403:
                return PlatformResponse::FORBIDDEN;
            case 404:
                return PlatformResponse::NOT_FOUND;
            case 503:
                return PlatformResponse::SERVICE_UNAVAILABLE;
            default:
                return PlatformResponse::VALIDATION_ERROR;
        }
    }

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

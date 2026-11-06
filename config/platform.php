<?php

/**
 * 五平台对接配置（网关 / 统一身份 / 考试平台 / 成果广场）
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §10.2.3、§13.1。
 *
 * 约定：
 *   1. 所有密钥只由环境注入，本文件不出现明文默认值；
 *   2. 开关类默认值取"安全侧"——验签默认开、CORS 默认空、SSO 默认 mock；
 *   3. 三套凭据不得复用（§10.2.3 末段）：SSO_CLIENT_SECRET / GATEWAY_INTERNAL_SECRET /
 *      EXAM_APP_SECRET 各自独立，泄露任一影响面不同。
 */
return [

    /*
    |--------------------------------------------------------------------------
    | 平台身份
    |--------------------------------------------------------------------------
    | 规范 §8.1：五平台编码固定为 forum / learning / exam / workshop / showcase
    */
    'app_code' => env('APP_CODE', 'workshop'),

    /*
    |--------------------------------------------------------------------------
    | 统一身份（OAuth 2.0 授权码 + 服务端换码）
    |--------------------------------------------------------------------------
    | §4.3：客户端为机密客户端，client_secret 只存服务端；redirect_uri 精确匹配。
    | §4.7：SSO_MODE=mock 仅用于打通链路，测试与生产必须 real。
    */
    'sso' => [
        'mode' => env('SSO_MODE', 'mock'),
        'issuer' => env('SSO_ISSUER', 'jingyuan-forum'),
        'base_url' => env('SSO_BASE_URL', ''),
        'client_id' => env('SSO_CLIENT_ID', 'jy-workshop-web'),
        'client_secret' => env('SSO_CLIENT_SECRET', ''),
        'redirect_uri' => env('SSO_REDIRECT_URI', ''),
        'post_logout_uri' => env('SSO_POST_LOGOUT_URI', ''),
        'scope' => env('SSO_SCOPE', 'openid profile'),
        'pkce' => env('SSO_PKCE', true),
        'http_timeout' => env('SSO_HTTP_TIMEOUT', 3),
        'introspect_timeout_ms' => env('SSO_INTROSPECT_TIMEOUT_MS', 800),
        'login_path' => env('SSO_LOGIN_PATH', '/oauth/authorize'),
        'token_path' => env('SSO_TOKEN_PATH', '/oauth/token'),
        'userinfo_path' => env('SSO_USERINFO_PATH', '/oauth/userinfo'),
        'logout_path' => env('SSO_LOGOUT_PATH', '/protocol/logout'),
        'discovery_path' => env('SSO_DISCOVERY_PATH', '/.well-known/openid-configuration'),
        // mock 只在这些环境生效。生产漏配 SSO_* 会静默落到 mock，
        // 而 mock 会凭空造出一个身份（若 SSO_MOCK_SUB 被配成数字就是免密后门），
        // 故默认只放 local/testing；显式留空 = 任何环境都不允许。
        'mock_envs' => env('SSO_MOCK_ENVS', 'local,testing'),
        // mock 模式的演示身份：sub 必须是"看起来像论坛 sub"的字符串，禁止用数字主键
        'mock_sub' => env('SSO_MOCK_SUB', 'usr_mock_workshop_demo'),
        'mock_email' => env('SSO_MOCK_EMAIL', ''),
        'mock_roles' => env('SSO_MOCK_ROLES', 'student'),
        'mock_nickname' => env('SSO_MOCK_NICKNAME', '演示用户'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 服务端会话
    |--------------------------------------------------------------------------
    | §4.5：Cookie 名称 __Host-workshop_sid（HTTPS 强制 Secure）；空闲 30 分钟、
    | 绝对 8 小时。本地 HTTP 调试时 __Host- 前缀会被浏览器拒绝，因此允许
    | 通过 WORKSHOP_SESSION_COOKIE 降级为不带前缀的名称，并在健康检查里
    | 暴露 secureCookie 标志，避免"本地调通了线上不通"。
    */
    'session' => [
        'cookie' => env('WORKSHOP_SESSION_COOKIE', 'workshop_sid'),
        'secure' => env('WORKSHOP_SESSION_SECURE', false),
        'idle_ttl' => (int) env('SESSION_IDLE_TTL', 1800),
        'abs_ttl' => (int) env('SESSION_ABS_TTL', 28800),
        'same_site' => env('WORKSHOP_SESSION_SAMESITE', 'Lax'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 网关
    |--------------------------------------------------------------------------
    | §5.5：本平台必须校验 X-Internal-Sign，不能只依赖"边缘清除了外部同名头"。
    | §5.4：公开路径白名单必须是精确路径，不能用前缀通配。
    */
    'gateway' => [
        'public_base' => env('GATEWAY_PUBLIC_BASE', ''),
        // 网关实际部署地址，如 http://8.138.246.181（见《统一API网关部署报告》）
        // 配置后：出站跨平台调用与外部可达性校验都以它为基地址
        'base_url' => env('GATEWAY_BASE_URL', ''),
        'verify_sign' => env('GATEWAY_VERIFY_SIGN', true),
        // 入站验签密钥（本平台专属）：内部签名密钥按平台独立，论坛那把不能用于 workshop。
        // 两个来源由 GatewaySigner::internalSecret() 统一读取：直填值优先，其次读文件。
        'internal_secret' => env('GATEWAY_INTERNAL_SECRET', ''),
        'internal_secret_file' => env('GATEWAY_INTERNAL_SECRET_FILE', ''),
        'trusted_cidr' => env('GATEWAY_TRUSTED_CIDR', ''),
        'skew' => (int) env('GATEWAY_SKEW', 300),
        'cors_origins' => env('GATEWAY_CORS_ORIGINS', ''),
        // reject：签名缺失即 403（生产）；allow：允许直连（仅本地演示，会在启动日志告警）
        'direct_access' => env('GATEWAY_DIRECT_ACCESS', 'reject'),
        // 兼容期：无网关环境下仍允许存量 token 头访问 v1（默认开，联调完成后关）
        'allow_token_fallback' => env('GATEWAY_ALLOW_TOKEN_FALLBACK', true),

        /*
        | L2 出站签名的规范串版本
        |--------------------------------------------------------------------------
        | spec    —— 《统一身份与网关接入说明》V3.0 §12.4：METHOD / path / query / body 四段
        | gateway —— 已部署网关（APISIX + jy-l2-sign）的实际实现，七段：
        |            appKey / ts / nonce / METHOD / path / 排序后的 query / sha256(body)
        |
        | 两者不可混用：规范串差一个字段，签名必然对不上。默认取 gateway，因为
        | 当前对接的是真实网关；若对端按 V3.0 四段实现，改回 spec 即可，业务代码不动。
        */
        'l2_version' => env('GATEWAY_L2_VERSION', 'gateway'),
        // 探测命令（php artisan gateway:probe）的连接超时，秒
        'probe_timeout' => (int) env('GATEWAY_PROBE_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | 论坛账号确认（密码代验式，只读）
    |--------------------------------------------------------------------------
    | 《警院论坛账号确认接口 · 接口说明与四平台接入指南》v2.2.0。
    |
    | 与 sso 段是**两条独立路径**，不可互相替代：
    |   sso   —— 授权码 + PKCE，用户跳到身份中心，平台全程不接触密码（终态目标）
    |   forum —— 用户在本平台输入论坛账号密码，平台经网关代验（过渡方案）
    | 文档 §九.1 已写明演进方向为前者，届时删掉本段与对应路由即可，其余不动。
    |
    | 三条硬约束：
    |   1. 对端只读：不签发论坛 token、不写库、不更新 last_login_date；
    |      平台据此**自建会话**，拿不到也不保存论坛密码；
    |   2. 密码只在内存中透传一次，绝不落库、绝不写日志、绝不进异常信息；
    |   3. 超时/5xx 属平台级故障，不能报成"账号或密码错误"——否则用户会反复重试。
    */
    'forum' => [
        // 默认复用网关地址：account-verify 由网关回环到论坛，平台不直连论坛
        'base_url' => env('FORUM_VERIFY_BASE_URL', env('GATEWAY_BASE_URL', '')),
        'app_key' => env('FORUM_APP_KEY', 'jy-workshop-svc'),
        'app_secret' => env('FORUM_APP_SECRET', ''),
        // 文档 §2.1：POST /answer/api/v1/account/verify（注意不是 /api/v1/workshop/*）
        'verify_path' => env('FORUM_VERIFY_PATH', '/answer/api/v1/account/verify'),
        // 网关该路由 S 级超时 3s，本平台必须更短，留出写响应的余量
        'timeout_ms' => (int) env('FORUM_VERIFY_TIMEOUT_MS', 2500),
        'enabled' => env('FORUM_VERIFY_ENABLED', true),
        // 未配置 app_secret 时自动 mock：本地无密钥也能跑通"确认 → 建会话"全链路
        'mock' => env('FORUM_VERIFY_MOCK', null),
        // 与 sso.issuer 同一命名空间：两条路径拿到的都是论坛签发的身份
        'issuer' => env('FORUM_ISSUER', env('SSO_ISSUER', 'jingyuan-forum')),
        'client_id' => env('FORUM_CLIENT_ID', 'jy-workshop-web'),
        // 失败节流：密码代验接口必须防止被当成撞库入口
        'throttle_max' => (int) env('FORUM_THROTTLE_MAX', 5),
        'throttle_window' => (int) env('FORUM_THROTTLE_WINDOW', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | 考试平台（准入权威源）
    |--------------------------------------------------------------------------
    | §8.3：必须回源；超时返回 pending，不得判为不合格。
    | §5.6：本平台侧超时必须短于网关总超时（1.5s vs 2s）。
    |
    | 经网关调用时 admission_path 必须用 internal 前缀：
    |   /api/v1/exam/internal/admissions  → 命中 exam-internal（L2 服务签名）
    | 而 /api/v1/exam/admissions 会命中 exam-api（L1 令牌），服务间调用会被 401 拒绝。
    */
    'exam' => [
        'base_url' => env('EXAM_BASE_URL', ''),
        'app_key' => env('EXAM_APP_KEY', 'jy-workshop-svc'),
        'app_secret' => env('EXAM_APP_SECRET', ''),
        'timeout_ms' => (int) env('EXAM_TIMEOUT_MS', 1500),
        'cache_ttl' => (int) env('EXAM_CACHE_TTL', 60),
        'projection_ttl' => (int) env('EXAM_PROJECTION_TTL', 300),
        'cache_enabled' => env('EXAM_CACHE_ENABLED', true),
        // 未配置 base_url 时自动进入 mock，避免本地开发产生真实出站请求
        'mock' => env('EXAM_MOCK', null),
        'mock_eligible' => env('EXAM_MOCK_ELIGIBLE', true),
        'mock_reason' => env('EXAM_MOCK_REASON', 'passed'),
        'admission_path' => env('EXAM_ADMISSION_PATH', '/api/v1/exam/internal/admissions'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 成果广场（Work 权威源）
    |--------------------------------------------------------------------------
    | 广场是 Work（作品/成果）的权威平台，本平台不持有该实体
    | （docs/platform/data-dictionary-mapping.md §6）。因此这里只定义
    | "把项目作为成果候选推给广场"与"查回作品状态"两件事，字段以广场契约
    | 为准，本平台只做映射，不做二次判定。
    */
    'showcase' => [
        'base_url' => env('SHOWCASE_BASE_URL', ''),
        'app_key' => env('SHOWCASE_APP_KEY', 'jy-workshop-svc'),
        'app_secret' => env('SHOWCASE_APP_SECRET', ''),
        'timeout_ms' => (int) env('SHOWCASE_TIMEOUT_MS', 2000),
        // 未配置 base_url 时自动进入 mock（只记录、不发起真实请求）
        'mock' => env('SHOWCASE_MOCK', null),
        'work_path' => env('SHOWCASE_WORK_PATH', '/api/v1/plaza/internal/works'),
        'work_query_path' => env('SHOWCASE_WORK_QUERY_PATH', '/api/v1/plaza/internal/works'),
        'auto_publish_on_submit' => env('SHOWCASE_AUTO_PUBLISH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | 幂等（§6.7）
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'enabled' => env('IDEMPOTENCY_ENABLED', true),
        'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),
        'lock_ttl' => (int) env('IDEMPOTENCY_LOCK_TTL', 60),
    ],

];

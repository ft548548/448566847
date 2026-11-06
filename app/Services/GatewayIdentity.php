<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIdentityBinding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 请求身份上下文
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §4.1、§5.3.2、§5.5、§7.1。
 *
 * 身份来源按可信度排序，先命中先用：
 *   1. 会话 Cookie（本平台签发，浏览器场景）
 *   2. 网关注入的 X-User-Id（**必须**已通过 X-Internal-Sign 验签，否则一律不认）
 *   3. 存量 token 头（联调期兼容，可用 GATEWAY_ALLOW_TOKEN_FALLBACK 关闭）
 *
 * 为什么只认验签后的 X-User-Id：上游端口若能被内网直连，绕过网关发一个
 * X-User-Id 就能冒充任何人（§5.5 开头段）。网络隔离不是充分条件。
 *
 * 本类不改动 User::auth()，因此对现有 300 多处 RPC 调用零影响。
 */
class GatewayIdentity
{
    /** 本地用户主键；0 表示尚未绑定 */
    public $userid = 0;

    /** 论坛 sub 原值（对外唯一身份） */
    public $subject = '';

    /** 统一角色（已由网关映射，业务侧不再翻译） */
    public $roles = [];

    /** 客户端标识，记审计 */
    public $clientId = '';

    /** user / service / token / session / anonymous */
    public $mode = 'anonymous';

    /** 身份来源：session / gateway / token */
    public $source = 'anonymous';

    /** 是否已经过网关验签 */
    public $trusted = false;

    /** subject 是否已绑定到本地账号 */
    public $bound = false;

    /** @var User|null 本地用户模型 */
    public $user = null;

    /**
     * 解析当前请求的身份
     *
     * @param Request $request
     * @return self
     */
    public static function resolve(Request $request): self
    {
        $identity = new self();

        // ① 会话 Cookie
        $session = WorkshopSession::resolve($request);
        if (!empty($session['data'])) {
            $data = $session['data'];
            $identity->userid = (int) ($data['userid'] ?? 0);
            $identity->subject = (string) ($data['subject'] ?? '');
            $identity->roles = self::normalizeRoles($data['roles'] ?? []);
            $identity->clientId = (string) ($data['client_id'] ?? '');
            $identity->mode = (string) ($data['mode'] ?? 'user');
            $identity->source = 'session';
            $identity->trusted = true;
            $identity->bound = $identity->userid > 0;
            if ($identity->userid > 0) {
                $identity->user = User::whereUserid($identity->userid)->first();
            }
            return $identity;
        }

        // ② 网关注入的可信头（仅验签通过才认）
        //
        // X-User-Id 即论坛 uid，也就是本地 userid —— 二者相等，不做映射（R1/R2）。
        // 绑定表在此退化为一致性校验：有绑定则核对 userid 是否等于 uid，
        // 不一致说明数据被改过，按未绑定处理并留痕，绝不静默采信。
        $trusted = (bool) $request->attributes->get('gateway.trusted', false);
        $subject = trim((string) $request->headers->get('X-User-Id', ''));
        if ($trusted && $subject !== '') {
            $identity->subject = $subject;
            $identity->roles = self::normalizeRoles($request->headers->get('X-User-Role', ''));
            $identity->clientId = trim((string) $request->headers->get('X-Client-Id', ''));
            $identity->mode = trim((string) $request->headers->get('X-Auth-Mode', 'user')) ?: 'user';
            $identity->source = 'gateway';
            $identity->trusted = true;

            $uid = ctype_digit($subject) && ltrim($subject, '0') === $subject ? (int) $subject : 0;
            if ($uid > 0) {
                $consistency = true;
                $binding = UserIdentityBinding::findActive(self::issuer(), $subject);
                if ($binding && (int) $binding->userid !== $uid) {
                    $consistency = false;
                    Log::warning('gateway identity uid/binding mismatch', [
                        'issuer' => self::issuer(),
                        'subject' => $subject,
                        'binding_userid' => (int) $binding->userid,
                    ]);
                }
                if ($consistency) {
                    $identity->userid = $uid;
                    $identity->bound = true;
                    $identity->user = User::whereUserid($uid)->first();
                }
            }
            return $identity;
        }

        // ③ 存量 token 头（联调期兼容）
        if (self::allowTokenFallback()) {
            $token = trim((string) $request->headers->get('token', ''));
            if ($token !== '') {
                $userid = (int) User::authFind('userid', $token);
                if ($userid > 0) {
                    $identity->userid = $userid;
                    $identity->mode = 'user';
                    $identity->source = 'token';
                    $identity->trusted = false;
                    $identity->bound = true;
                    $identity->user = User::whereUserid($userid)->first();
                    // subject 恒等于论坛 uid（本地主键即 uid），不从库列读
                    $identity->subject = (string) $userid;
                }
            }
        }

        return $identity;
    }

    /**
     * 是否已登录
     *
     * @return bool
     */
    public function authenticated(): bool
    {
        return $this->userid > 0;
    }

    /**
     * 是否为服务态调用（§5.3.2：service 模式不得写用户私有数据）
     *
     * @return bool
     */
    public function isService(): bool
    {
        return $this->mode === 'service';
    }

    /**
     * 角色是否命中其一
     *
     * @param array|string $roles
     * @return bool
     */
    public function hasRole($roles): bool
    {
        $roles = is_array($roles) ? $roles : explode(',', (string) $roles);
        foreach ($roles as $role) {
            $role = trim($role);
            if ($role !== '' && in_array($role, $this->roles, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 角色归一化：支持 "student,teacher" 与数组两种入参
     *
     * @param mixed $roles
     * @return array
     */
    public static function normalizeRoles($roles): array
    {
        if (is_array($roles)) {
            $list = $roles;
        } else {
            $list = explode(',', (string) $roles);
        }

        $result = [];
        foreach ($list as $role) {
            $role = trim((string) $role);
            if ($role !== '' && !in_array($role, $result, true)) {
                $result[] = $role;
            }
        }
        return $result;
    }

    /**
     * 身份签发方（防多 IdP 串号）
     *
     * @return string
     */
    public static function issuer(): string
    {
        return (string) config('platform.sso.issuer', 'jingyuan-forum');
    }

    /**
     * 是否允许 token 头兜底
     *
     * @return bool
     */
    private static function allowTokenFallback(): bool
    {
        $value = config('platform.gateway.allow_token_fallback', true);
        if (is_bool($value)) {
            return $value;
        }
        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no', ''], true);
    }
}

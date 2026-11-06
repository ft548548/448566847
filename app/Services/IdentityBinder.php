<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserIdentityBinding;
use App\Module\Base;
use Illuminate\Database\QueryException;

/**
 * sub → 本地账号的绑定与首登建号
 *
 * 依据《实训平台_统一身份与网关接入说明》V3.0 §4.1.3。
 *
 * 三条与直觉相反但必须遵守的规则：
 *   1. 不写"先查后插"：并发首登会撞唯一索引，其中一个请求 500。改为
 *      "插入 → 冲突则回查"（见 bindOrCreate）。
 *   2. 邮箱已存在**不自动关联**：身份中心与本地账号的邮箱可能被不同人持有，
 *      自动合并等于把两个账号的数据串在一起，必须人工核对。
 *   3. subject 原值存储：不 trim、不转小写、不截断、不加前缀。
 *
 * uid 契约（《统一账号体系与uid返回规则》R1/R2/R5）：
 *   forum_uid 是唯一身份主键，本地账号主键必须与之相等，不做二次编号。
 *   因此 subject 必须是十进制 uid 字符串，且显式写入 pre_users.userid，
 *   绝不走自增。subject 无法解析为 uid 时直接拒绝，不生成任何替代标识（R10）。
 *
 * 返回结构化结果而非抛异常，便于控制器直接映射为规范错误码。
 */
class IdentityBinder
{
    /**
     * 绑定或建号
     *
     * @param string $issuer 签发方
     * @param string $subject 论坛 sub 原值
     * @param array $profile email / nickname / avatar / roles
     * @return array{ok:bool,code:string,message:string,user:\App\Models\User|null,binding:\App\Models\UserIdentityBinding|null,created:bool}
     */
    public function bindOrCreate(string $issuer, string $subject, array $profile = []): array
    {
        if ($subject === '') {
            return $this->fail(PlatformResponse::VALIDATION_ERROR, 'userinfo 未返回 userId(sub)，拒绝建号');
        }

        $uid = $this->parseUid($subject);
        if ($uid === null) {
            return $this->fail(
                PlatformResponse::VALIDATION_ERROR,
                '论坛 uid 非十进制正整数，拒绝建号：本平台不以任何方式生成替代标识'
            );
        }

        $binding = UserIdentityBinding::findActive($issuer, $subject);

        if ($binding) {
            if ((int) $binding->status !== UserIdentityBinding::STATUS_ACTIVE) {
                return $this->fail(PlatformResponse::FORBIDDEN, '账号已停用，请联系管理员');
            }
            $user = User::whereUserid($binding->userid)->first();
            if (!$user) {
                return $this->fail(PlatformResponse::CONFLICT, '绑定关系指向的本地账号不存在，需人工修复');
            }
            $binding->last_login_at = now();
            $binding->save();

            return [
                'ok' => true,
                'code' => PlatformResponse::OK,
                'message' => 'binding exists',
                'user' => $user,
                'binding' => $binding,
                'created' => false,
            ];
        }

        $email = trim((string) ($profile['email'] ?? ''));
        if ($email === '') {
            // 身份中心未下发邮箱时用稳定占位，绝不拿邮箱参与 ID 生成（§8.2.2 末段）
            // 与预置账号口径一致：<uid>@forum.local
            $email = $uid . '@forum.local';
        }

        // 预置账号已存在（本平台按论坛 uid 预置了 1/4/5/7/8）：直接补绑定。
        // 不视为错误——账号主键就是 uid，本就该存在。
        $preset = User::whereUserid($uid)->first();
        if ($preset) {
            $binding = UserIdentityBinding::create([
                'issuer' => $issuer,
                'subject' => $subject,
                'userid' => $uid,
                'status' => UserIdentityBinding::STATUS_ACTIVE,
                'bound_at' => now(),
                'last_login_at' => now(),
            ]);

            return [
                'ok' => true,
                'code' => PlatformResponse::OK,
                'message' => 'preset bound',
                'user' => $preset,
                'binding' => $binding,
                'created' => false,
            ];
        }

        $exists = User::whereEmail($email)->first();
        if ($exists) {
            return $this->fail(
                PlatformResponse::CONFLICT,
                '该邮箱已存在本地账号，系统不自动关联，请管理员核对后绑定'
            );
        }

        // 插入 → 冲突则回查（并发首登）
        try {
            $user = $this->createUser($uid, $email, $profile);
            $binding = UserIdentityBinding::create([
                'issuer' => $issuer,
                'subject' => $subject,
                'userid' => $uid,
                'status' => UserIdentityBinding::STATUS_ACTIVE,
                'bound_at' => now(),
                'last_login_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
            $binding = UserIdentityBinding::findActive($issuer, $subject);
            if (!$binding) {
                return $this->fail(PlatformResponse::CONFLICT, '并发建号冲突，请重试');
            }
            $user = User::whereUserid($binding->userid)->first();
            if (!$user) {
                return $this->fail(PlatformResponse::CONFLICT, '并发建号后未找到本地账号，请重试');
            }
            return [
                'ok' => true,
                'code' => PlatformResponse::OK,
                'message' => 'binding recovered',
                'user' => $user,
                'binding' => $binding,
                'created' => false,
            ];
        }

        return [
            'ok' => true,
            'code' => PlatformResponse::OK,
            'message' => 'created',
            'user' => $user,
            'binding' => $binding,
            'created' => true,
        ];
    }

    /**
     * 按 subject 查本地用户
     *
     * @param string $issuer
     * @param string $subject
     * @return User|null
     */
    public function userBySubject(string $issuer, string $subject)
    {
        $binding = UserIdentityBinding::findActive($issuer, $subject);
        if (!$binding || (int) $binding->status !== UserIdentityBinding::STATUS_ACTIVE) {
            return null;
        }
        return User::whereUserid($binding->userid)->first();
    }

    /**
     * 创建本地账号（identity 留空 = 普通用户，§4.1.3）
     *
     * 不复用 User::reg()：它强制邮箱格式与密码策略，而 SSO 首登的邮箱可能为空、
     * 密码由系统随机生成（用户永远不用它登录）。
     *
     * userid 显式写入论坛 uid，不走自增：本地主键即 forum_uid，这是
     * 「不生成平台侧标识」在数据层的唯一落点。密码随机，用户永远不用它登录。
     *
     * @param int $uid 论坛 uid（十进制正整数）
     * @param string $email
     * @param array $profile
     * @return User
     */
    private function createUser(int $uid, string $email, array $profile): User
    {
        $encrypt = Base::generatePassword(6);
        $user = User::createInstance([
            'userid' => $uid,
            'encrypt' => $encrypt,
            'email' => $email,
            'password' => Base::md52(Base::generatePassword(16), $encrypt),
            'nickname' => (string) ($profile['nickname'] ?? ''),
            'userimg' => (string) ($profile['avatar'] ?? ''),
            'identity' => '',
            'created_ip' => Base::getIp(),
        ]);
        $user->save();
        User::AZUpdate($user->userid);

        return User::whereUserid($user->userid)->first() ?: $user;
    }

    /**
     * 解析论坛 uid
     *
     * 只接受纯十进制正整数。前导零、正负号、空白、非数字一律视为非法——
     * 若放行会产生与真实 uid 不同的第二个主键，破坏「不可变更不可复用」。
     *
     * @param string $subject
     * @return int|null 非法返回 null
     */
    public function parseUid(string $subject)
    {
        if ($subject === '' || !ctype_digit($subject)) {
            return null;
        }
        // 去掉前导零后必须与原值一致，避免 "007" 与 "7" 指向同一账号却有两个主键
        if (ltrim($subject, '0') !== $subject) {
            return null;
        }
        $uid = (int) $subject;
        if ($uid <= 0) {
            return null;
        }
        return $uid;
    }

    /**
     * 判断是否唯一键冲突
     *
     * @param QueryException $e
     * @return bool
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $message = $e->getMessage();
        return strpos($message, '1062') !== false
            || strpos($message, 'Duplicate entry') !== false
            || strpos($message, 'UNIQUE constraint failed') !== false;
    }

    /**
     * @param string $code
     * @param string $message
     * @return array
     */
    private function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'user' => null,
            'binding' => null,
            'created' => false,
        ];
    }
}

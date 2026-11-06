<?php

namespace App\Models;

/**
 * App\Models\UserIdentityBinding
 *
 * 论坛 sub ↔ 本地 userid 的映射（§4.1.2）
 *
 * 设计纪律：
 *   - subject 原值存储，任何加工都会导致跨平台查不到人（§8.2.2）；
 *   - 解绑不删行，只把 status 置 0，保留审计痕迹；
 *   - 不存邮箱/手机号，身份中心是权威源，本地留副本会过期。
 *
 * @property int $id
 * @property string $issuer 签发方
 * @property string $subject 论坛 sub 原值
 * @property int $userid 本地用户主键
 * @property int $status 1 生效 0 停用
 * @property \Illuminate\Support\Carbon|null $bound_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserIdentityBinding extends AbstractModel
{
    const STATUS_ACTIVE = 1;
    const STATUS_DISABLED = 0;

    protected $table = 'user_identity_binding';

    protected $fillable = [
        'issuer',
        'subject',
        'userid',
        'status',
        'bound_at',
        'last_login_at',
    ];

    protected $dates = [
        'bound_at',
        'last_login_at',
        'created_at',
        'updated_at',
    ];

    /**
     * 按签发方 + sub 查询有效绑定
     *
     * @param string $issuer
     * @param string $subject
     * @return self|null
     */
    public static function findActive(string $issuer, string $subject)
    {
        return self::whereIssuer($issuer)->whereSubject($subject)->first();
    }
}

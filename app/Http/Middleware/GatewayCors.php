<?php

namespace App\Http\Middleware;

use Closure;

/**
 * v1 接口的 CORS 校正（全局中间件，仅作用于 `api/v1/*`）
 *
 * 存在的唯一原因：框架自带的 CORS（`config/cors.php`）对所有响应无条件写
 * `Access-Control-Allow-Origin: *`，且它在全局栈里位于路由中间件**内层**，
 * 所以 GatewayTrust 写的白名单头会被它覆盖，v1 的 §6.8 要求（回显具体来源 +
 * 允许携带凭据）在运行时根本不生效。
 *
 * 这里把它放在全局栈里 HandleCors **之前**（即更外层），在响应回程时：
 *   1. 清掉通配头；
 *   2. 按 platform.gateway.cors_origins 白名单重新裁决。
 *
 * 对 `api/v1/*` 以外的路径完全不介入——存量接口的 CORS 行为一个字节都不变。
 * 这也顺带覆盖了 OPTIONS 预检：HandleCors 会直接返回 204，路由中间件压根不执行，
 * 只有更外层的中间件才有机会修正它的响应头。
 */
class GatewayCors
{
    /** 需要接管的路径前缀（v1 网关接口） */
    private const V1_PREFIX = 'api/v1/';

    /** 由框架全局 CORS 写入、需要被推翻的响应头 */
    private const WILDCARD_HEADERS = [
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers',
        'Access-Control-Max-Age',
    ];

    /**
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (!$this->isV1($request) || !$response instanceof \Symfony\Component\HttpFoundation\Response) {
            return $response;
        }

        // 注意：**不能**删 Access-Control-Allow-Origin。HandleCors 在 RequestHandled
        // 事件里还会补一次头，判据是"响应里还没有这个头"；删掉就等于请它补 `*`。
        // 正确做法是让 GatewayTrust::applyCors 覆盖成一个确定值（命中白名单回显来源，
        // 未命中写 `null`），其余由框架 CORS 写入的通配头则可以清掉。
        foreach (self::WILDCARD_HEADERS as $name) {
            if ($name === 'Access-Control-Allow-Origin') {
                continue;
            }
            $response->headers->remove($name);
        }

        // 追加而不是覆盖：框架 CORS 可能已经写了 Vary，覆盖会丢掉其他维度
        $response->setVary('Origin', false);
        GatewayTrust::applyCors($request, $response);

        return $response;
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private function isV1($request): bool
    {
        return strpos(ltrim((string) $request->path(), '/'), self::V1_PREFIX) === 0;
    }
}

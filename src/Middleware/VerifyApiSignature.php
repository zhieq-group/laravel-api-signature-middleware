<?php

namespace ZhiEq\ApiSignature\Middleware;

use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Http\Request;
use ZhiEq\Contracts\MiddlewareExceptRoute;
use ZhiEq\ApiSignature\Exceptions\AcceptTypeInvalidException;
use ZhiEq\ApiSignature\Exceptions\BodyFormatInvalidException;
use ZhiEq\ApiSignature\Exceptions\HeaderNonceLengthInvalidException;
use ZhiEq\ApiSignature\Exceptions\RepeatRequestException;
use ZhiEq\ApiSignature\Exceptions\RequestContentTypeInvalidException;
use ZhiEq\ApiSignature\Exceptions\RequestTimeInvalidException;
use ZhiEq\ApiSignature\Exceptions\SignatureHeaderInvalidException;
use ZhiEq\ApiSignature\Exceptions\SignatureInvalidException;
use ZhiEq\ApiSignature\Exceptions\TimestampFormatInvalidException;

class VerifyApiSignature extends MiddlewareExceptRoute
{

    protected $requiredHeaders = [
        'X-Ca-Signature-Headers',
        'X-Ca-Timestamp',
        'X-Ca-Nonce',
        'X-Ca-Signature',
    ];

    protected $signElseHeaders = [
        'accept',
        'content-md5',
        'date',
    ];

    /**
     * @param \Illuminate\Http\Request $request
     * @return string
     */

    protected function getContentEncode($request)
    {
        if (in_array($request->getMethod(), ['GET', 'DELETE'])) {
            return '';
        }
        return (empty($request->getContent()) ? '' : base64_encode(md5($request->getContent(), true)));
    }

    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws Exception
     */

    public function subHandle($request, Closure $next)
    {
        /*
        * 强制检查传入的body必须为json格式
        */
        if (!$request->wantsJson()) {
            throw new AcceptTypeInvalidException();
        }
        if (!empty($request->getContent()) && empty($request->json())) {
            throw new BodyFormatInvalidException();
        }
        if (!empty($request->getContent()) && !$request->isJson()) {
            throw new RequestContentTypeInvalidException();
        }
        /*
         * 检查签名必须的字段
         */
        foreach ($this->requiredHeaders as $headerKey) {
            if (!$request->hasHeader($headerKey)) {
                throw new SignatureHeaderInvalidException($headerKey);
            }
        }
        if (strlen($request->header('X-Ca-Nonce')) !== 36) {
            throw new HeaderNonceLengthInvalidException();
        }
        /*
         * 检验请求的时间与实际时间的偏差值，超过偏差值的请求会被拒绝，防止回放攻击
         */
        try {
            $timeDiff = (new Carbon($request->header('X-Ca-Timestamp'), 'UTC'))->diffInSeconds(Carbon::now('UTC'), false);
            if ($timeDiff > 900 || $timeDiff < -900) {
                throw new RequestTimeInvalidException();
            }
        } catch (Exception $exception) {
            logs()->error($exception->__toString());
            throw new TimestampFormatInvalidException();
        }
        /*
         * 根据请求的路径和请求的随机数进行校验，保证在15分钟内只能请求一次，结合上述时间校验防止回放攻击
         */
        $uniqueRequestStr = config('tools.api_signature_prefix') . sha1('/' . $request->path() . "\n" . $request->header('X-Ca-Nonce'));
        if (cache()->get($uniqueRequestStr)) {
            throw new RepeatRequestException();
        }
        cache()->put($uniqueRequestStr, Carbon::now()->timestamp, Carbon::now()->addMinutes(15));
        /*
         * 组装签名字符串的请求头部分
         */
        $signHeaders = explode(',', $request->header('X-Ca-Signature-Headers'));
        sort($signHeaders);
        $signHeaderString = implode("\n", collect($signHeaders)->map(function ($headerKey) use ($request) {
            return $headerKey . ':' . $request->header($headerKey);
        })->toArray());
        /*
         * 组装签名字符串的query部分
         */
        $signQuery = $request->query();
        ksort($signQuery);
        $signQueryString = implode('&', collect($signQuery)->map(function ($value, $key) {
            return $key . '=' . $value;
        })->toArray());
        /*
         * 组装签名字符串
         */
        $signString = strtoupper($request->method()) . "\n"
            . $request->header('Content-Type') . "\n"
            . $this->getContentEncode($request) . "\n"
            . $request->header('Accept') . "\n"
            . $request->header('X-Ca-Timestamp') . "\n"
            . $signHeaderString . "\n"
            . '/' . $request->path() . (empty($request->query()) ? '' : '?' . $signQueryString);
        $signSecret = config('tools.api_signature_secret');
        /*
         * 计算签名并对比请求的签名是否一致
         */
        $signature = base64_encode(hash_hmac('sha256', $signString, $signSecret, true));
        logs()->info('api signature info', [
            'signStr' => $signString,
            'signStrHash' => sha1($signString),
            'signSecret' => $signSecret,
            'sign' => $signature,
        ]);
        if ($signature !== $request->header('X-Ca-Signature')) {
            throw new SignatureInvalidException($signString);
        }
        return $next($request);
    }
}

<?php

namespace ZhiEq\ApiSignature\GuzzleMiddleware;

use Carbon\Carbon;
use function GuzzleHttp\Psr7\parse_query;
use Psr\Http\Message\RequestInterface;

class ApiSignatureGuzzleMiddleware
{
    protected $signSecret;

    public function __construct($signSecret)
    {
        $this->signSecret = $signSecret;
    }

    /**
     * @param $header
     * @return bool
     */

    public function isExcludeHeaders($header)
    {
        $excludeList = [
            'User-Agent',
            'Host',
            'Content-Length',
        ];
        return in_array($header, $excludeList);
    }

    /**
     * Called when the middleware is handled.
     *
     * @param callable $handler
     *
     * @return \Closure
     */
    public function __invoke(callable $handler)
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            foreach ($this->baseHeaders() as $headerKey => $headerValue) {
                $request = $request->withHeader($headerKey, $headerValue);
            }
            $signHeaders = [];
            foreach ($request->getHeaders() as $key => $headers) {
                if (!$this->isExcludeHeaders($key)) $signHeaders[$key] = $headers[0];
            }
            $request = $request->withHeader('X-Ca-Signature-Headers', implode(',', array_keys($signHeaders)));
            ksort($signHeaders);
            $signHeaderString = collect($signHeaders)->map(function ($headerValue, $headerKey) {
                return $headerKey . ':' . $headerValue;
            })->implode("\n");
            $signQuery = parse_query($request->getUri()->getQuery());
            ksort($signQuery);
            $signQueryString = collect($signQuery)->map(function ($queryValue, $queryKey) {
                return $queryKey . ':' . $queryValue;
            })->implode('&');
            $signString = strtoupper($request->getMethod()) . "\n"
                . $request->getHeader('Content-Type')[0] . "\n"
                . $this->getContentEncode($request) . "\n"
                . $request->getHeader('Accept')[0] . "\n"
                . $request->getHeader('X-Ca-Timestamp')[0] . "\n"
                . $signHeaderString . "\n"
                . $request->getUri()->getPath() . (empty($request->getUri()->getQuery()) ? '' : '?' . $signQueryString);
            $signature = base64_encode(hash_hmac('sha256', $signString, $this->signSecret, true));
            $request = $request->withHeader('X-Ca-Signature', $signature);
            logs()->info('request api signature', [
                'signString' => $signString,
                'signStrHash' => sha1($signString),
                'signSecret' => $this->signSecret,
                'signature' => $signature,
            ]);
            return $handler($request, $options);
        };
    }

    protected function baseHeaders()
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Ca-Timestamp' => Carbon::now('UTC')->format('Y-m-dTH:i:s') . 'Z',
            'X-Ca-Nonce' => uuid(),
        ];
    }

    /**
     * @param RequestInterface $request
     * @return string
     */

    protected function getContentEncode(RequestInterface $request)
    {
        if (in_array($request->getMethod(), ['GET', 'DELETE'])) {
            return '';
        }
        $content = $request->getBody()->getContents();
        return (empty($content) ? '' : base64_encode(md5($content, true)));
    }

}

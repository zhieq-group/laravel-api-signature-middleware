<?php

namespace ZhiEq\ApiSignature\Exceptions;

use ZhiEq\Contracts\Exception;

class TimestampFormatInvalidException extends Exception
{

    /**
     * 唯一错误代码5位数字，不能以零开头
     *
     * @return integer
     */
    protected function errorCode()
    {
        return 41205;
    }

    /**
     * 错误信息提示
     *
     * @return string
     */
    protected function message()
    {
        return 'X-Ca-Timestamp Format Must Be Like "2017-01-01T00:00:00Z" By UTC Timezone';
    }

    /**
     * 固定调试信息
     *
     * @return array|null
     */
    protected function debug()
    {
        return [];
    }

    /**
     * Http状态码
     *
     * @return int
     */
    protected function statusCode()
    {
        return 412;
    }

    /**
     * 头部信息
     *
     * @return array
     */
    protected function headers()
    {
        return [];
    }

    /**
     * 内容信息
     *
     * @return array|null
     */
    protected function data()
    {
        return [];
    }
}

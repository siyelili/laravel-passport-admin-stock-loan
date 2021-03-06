<?php

namespace App\Http\Controllers;

use Gregwar\Captcha\CaptchaBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    const PAGE_SIZE = 15;
    const CAPTCHA_PREFIX = "captcha_";
    const CAPTCHA_CACHE = "redis";
    const CODE_SUCCESS = 1;
    const CODE_FAIL = 0;


    /**
     * 获取验证码 重新获取验证码
     * @param $captchaId ,$captchaCode
     * @return bool
     */
    static function verifyCaptchaCode($captchaId, $captchaCode): bool
    {
        $cacheKey = self::CAPTCHA_PREFIX . $captchaId;
        $cachedCode = Cache::store(self::CAPTCHA_CACHE)->get($cacheKey);
        //Cache::forget($cacheKey);
        return $cachedCode == $captchaCode;
    }

    /**
     * 设置图片验证码
     * @param $captchaId
     * @return string 返回图片base64 string
     */
    static function generateCaptchaImage($captchaId): string
    {
        $builder = new CaptchaBuilder();
        $builder->build();
        $cacheKey = self::CAPTCHA_PREFIX . $captchaId;
        Cache::store(self::CAPTCHA_CACHE)->put($cacheKey, $builder->getPhrase(), 5);
        return $builder->inline();
    }

    /**
     * @param array $data 返回json 数据体
     * @param int $code_status 返回 状态
     * @param string $message 消息
     * @param \Illuminate\Http\Request|null $request 请求 用于debug
     * @return \Illuminate\Http\JsonResponse  json返回
     */
    static function jsonReturn($data = [], int $code_status = self::CODE_SUCCESS, string $message = '', int $httpStatusCode = 200)
    {
        $json['status'] = $code_status ? 1 : 0;
        $json['data'] = $data;
        $json['msg'] = $message;
        if (config('app.debug')) {
            $json['debug_sql'] = DB::getQueryLog();
        }
        return response()->json($json, $httpStatusCode);
    }


    static function fakePaginationForRelationSubQuerySearch($list)
    {
        return ['data' => $list, 'current_page' => 1, 'per_page' => count($list), 'total' => count($list)];
    }

    static protected function generateCacheKeyByReqeust()
    {
        $req = request();
        return $req->getUri() . implode('_', $req->all());
    }
}

<?php
namespace App\Http\Controllers\Web;

use App\Models\User;
use App\Services\CaptchaService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use \App\Http\Controllers;

class UserController extends Controller
{
    /**
     * 登录
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     */
    public function login(Request $request)
    {
        if ($request->isMethod('post')) {
            if (Auth::attempt(['phone' => $request->get('username'), 'password' => $request->get('password')])) {
                return $this->ajaxSuccess(['message' => '登录成功']);
            } else {
                return $this->ajaxError(['msg' => '登录失败']);
            }
        } elseif ($request->isMethod('get')) {
            return view('web.user.login', [
                'active' => 'login'
            ]);
        }
    }

    /**
     * 用户注册
     * @param Request $request
     * @return $this|\Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     * @throws \Exception
     */
    public function register(Request $request)
    {
        if ($request->isMethod('post')) {
            $codeId = $request->post('codeId');
            $username = $request->post('username');
            $password = $request->post('password');
            $captcha = $request->post('captcha');

            $validator = Validator::make($request->all(), [
                'username' => 'required|size:11',
                'password' => 'required|min:6|confirmed',
                'password_confirmation' => 'required|min:6',
                'captcha' => 'required',
            ], [
                'required' => ':attribute 不能为空.',
                'unique' => ':attribute 已存在.',
                'min' => ':attribute 长度不够.',
                'confirmed' => ':attribute 不一致',
                'size' => ':attribute 格式不合法',
            ], [
                'username' => '用户名',
                'password' => '密码',
                'password_confirmation' => '确认密码',
                'captcha' => '验证码'
            ]);

            if (empty($codeId) || empty($captcha) || !(new CaptchaService())->checkSmsCode($codeId, $captcha)) {
                return $this->ajaxError(['msg' => '验证码错误']);
            }

            if ($validator->fails()) {
                $errors = \GuzzleHttp\json_decode($validator->errors(), true);
                $msg = '';
                foreach ($errors as $v) {
                    $msg .= $v[0] . '<br/>';
                }
                return $this->ajaxError(['msg' => $msg]);
            }
            try {
                (new UserService())->webRegisterUser($username, $password, $inviteCode = '');
            } catch (\Exception $e) {
                return $this->ajaxError(['msg' => $e->getMessage()]);
            }
            return $this->ajaxSuccess(['message' => '注册成功']);
        } elseif ($request->isMethod('get')) {
            return view('web.user.register', [
                'active' => 'register'
            ]);
        }
    }

    /**
     * 验证是否存在
     * @param Request $request
     * @return static
     */
    public function isExist(Request $request)
    {
        $mobile = $request->get('phone');
        if (!preg_match("/^1[34578]{1}\d{9}$/", $mobile)) {
            return $this->ajaxError(['msg' => '手机号码不合法']);
        }
        if (User::where('phone', $mobile)->exists()) {
            return $this->ajaxError(['msg' => '手机号已被注册']);
        }
        return $this->ajaxSuccess();
    }

    /**
     * 注册获取短信
     * @param Request $request
     * @return static
     */
    public function getCode(Request $request)
    {
        $redis = Redis::connection();
        if ($redis->get('isdo')) {
            return $this->ajaxError(['msg' => '操作太频繁']);
        }
        $mobile = $request->post('username');
        if (!preg_match('/^1\d{10}$/', $mobile)) {
            return $this->ajaxError(['msg' => '请输入正确的手机号码']);
        }
        if (User::where('phone', $mobile)->exists()) {
            $codeId = (new CaptchaService())->modifyPasswordSms($mobile);
        } else {
            $codeId = (new CaptchaService())->registerSms($mobile);
        }
        if (!$codeId) {
            return $this->ajaxError(['msg' => '验证码发送失败']);
        }
        $redis->setex('isdo', 60, 'yes');
        return $this->ajaxSuccess([$codeId]);
    }

    /**
     * 忘记密码
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|static
     */
    public function forgetPwd(Request $request)
    {
        if ($request->isMethod('post')) {
            $codeId = $request->post('codeId');
            $captcha = $request->post('captcha');
            $username = $request->post('username');
            $user = User::where("phone", $username)->first();
            if (!$user) {
                return $this->ajaxError(['msg' => '用户不存在']);
            }
            if (empty($codeId) || empty($captcha) || !(new CaptchaService())->checkSmsCode($codeId, $captcha)) {
                return $this->ajaxError(['msg' => '验证码错误']);
            }
            Auth::login($user);
            return $this->ajaxSuccess();
        } else {
            return view('web.user.forgetPwd');
        }
    }


    /**
     * 修改密码
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function updatePwd(Request $request)
    {
        if ($request->isMethod('post')) {
            $validator = Validator::make($request->all(), [
                'password' => 'required|min:6|confirmed',
                'password_confirmation' => 'required|min:6',
            ], [
                'required' => ':attribute 不能为空.',
                'min' => ':attribute 长度不够.',
                'confirmed' => ':attribute 不一致',
            ], [
                'password' => '密码',
                'password_confirmation' => '确认密码',
            ]);
            if ($validator->fails()) {
                $errors = \GuzzleHttp\json_decode($validator->errors(), true);
                $msg = '';
                foreach ($errors as $v) {
                    $msg .= $v[0] . '<br/>';
                }
                return $this->ajaxError(['msg' => $msg]);
            }
            $user = Auth::user();
            $user->password = bcrypt($request->get('password'));
            if ($user->save()) {
                return $this->ajaxSuccess();
            }
            Auth::logout();
            return $this->ajaxError(['msg' => '修改失败']);
        } else {
            return view('web.user.updatePwd');
        }

    }

    /**
     * 修改密码成功跳转
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     */
    public function updatePwdSucc(Request $request)
    {
        return view('web.user.updatePwdSucc');
    }

    /**
     * 个人中心
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|static
     * @throws \Exception
     */
    public function  userCenter(Request $request)
    {
        $user = Auth::user();
        if ($request->isMethod('post')) {
            $request->offsetSet('user_id', $user->id);
            $validator = Validator::make($request->all(), [
                'qq_id' => 'numeric',
            ], [
                'numeric' => ':attribute 必须是数字.',
            ], [
                'qq_id' => 'QQ',
            ]);
            if ($validator->fails()) {
                $errors = \GuzzleHttp\json_decode($validator->errors(), true);
                $msg = '';
                foreach ($errors as $v) {
                    $msg .= $v[0] . '<br/>';
                }
                return $this->ajaxError(['msg' => $msg]);
            }


            $bool = (new UserService())->modifyUserInfo($request->all());
            if ($bool) {
                return $this->ajaxSuccess(['message' => '操作成功']);
            }
            return $this->ajaxError(['msg' => '操作失败']);

        } else {
            $user_info = (new UserService())->getUserInfo($user->id);
            if ($user_info->promotion) {
                $user_info->promotion = explode(',', $user_info->promotion);
            }
            $title = '个人中心';
            $profits = ['1' => '1000以下', '2' => '1000-5000', '3' => '5000-1W', '4' => '1W-5W', '5' => '5W-10W', '6' => '10W以上'];
            return view('web.user.userCenter', compact('title', 'profits', 'user_info'));
        }
    }

    /**
     * 账户安全
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|static
     */
    public function accountSecurity(Request $request)
    {
        if ($request->isMethod('post')) {
            return $this->ajaxSuccess();
        } else {
            $title = '账户安全';
            return view('web.user.accountSecurity', compact('title'));
        }
    }

    /**
     * 登出
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function logout(Request $request)
    {
        Auth::logout();
        return redirect(url('/'));
    }


}
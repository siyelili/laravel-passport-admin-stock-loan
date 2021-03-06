<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Model\Agent;
use App\Http\Model\AgentInfo;
use App\Http\Model\AgentProfitRateConfig;
use App\Http\Model\Employee;
use App\Http\Model\RecommendCode;
use App\Http\Model\Role;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Class AgentController 代理商
 * @package App\Http\Controllers\Api
 */
class AgentController extends Controller
{

    const AgentSelectorListCackeKey = 'agent.selector.list.cache.key';

    public function __construct()
    {
        $this->middleware("auth:api")->except(['search', 'childrenAgent']);
    }

    /*
     * 创建代理商
     */
    public function createAgent(Request $request)
    {

        $validator = \Validator::make($request->all(), [
            'bank_account' => 'required|unique:a_agent',
            'agent_name' => 'required|unique:a_agent',
            //'agent_number' => 'required|unique:a_agent',
            'owner_name' => 'required|unique:a_agent',
            'phone' => 'required|unique:a_agent',
            'phone' => 'required|unique:s_system_user',
            'name' => 'required|unique:s_system_user',//验证登陆用户名唯一
        ], [
            'agent_name.unique' => "代理商名称不能重复",
            'bank_account.unique' => "提现银行卡号重复",
            'phone.unique' => "联系人手机号码已注册",
            'name.unique' => "登陆用户名以重复",
            'owner_name.unique' => "代理商联系人姓名重复",

        ]);

        if ($validator->fails()) {
            return parent::jsonReturn([], parent::CODE_FAIL, $validator->errors()->first());
        }

        Cache::forget(self::AgentSelectorListCackeKey);

        $msg = '';
        DB::beginTransaction();
        try {


            //$user = Auth::user();
            //确定确定权限
            $parent_id = $request->input('parent_id');
            $parent_agent = Agent::find($parent_id);
            if ($parent_agent) {
                $agent_level = $parent_agent->agent_level + 1;
            } else {
                $agent_level = 1;
            }

            $data = $request->except(['password', 'confirm_password', 'day_percentage', 'month_percentage', 'commission_percentage', 'name']);
            $data['agent_level'] = $agent_level;
            $data['agent_number'] = $request->input('name');
            //创建代理商
            $instance = Agent::create($data);
            //创建登陆用户
            $agent_id = $instance->id;


            //创建配置
            //天配
            $rateWhere = [
                'agent_id' => $agent_id,
                'type' => AgentProfitRateConfig::TypeDay,
            ];
            $percentage = $request->input('day_percentage');
            AgentProfitRateConfig::updateOrCreate($rateWhere, compact('percentage'));
            //月配
            $rateWhere = [
                'agent_id' => $agent_id,
                'type' => AgentProfitRateConfig::TypeMonth,
            ];
            $percentage = $request->input('month_percentage');
            AgentProfitRateConfig::updateOrCreate($rateWhere, compact('percentage'));
            //佣金oen
            $rateWhere = [
                'agent_id' => $agent_id,
                'type' => AgentProfitRateConfig::TypeCommissionOne
            ];
            $percentage = $request->input('commission_percentage');
            AgentProfitRateConfig::updateOrCreate($rateWhere, compact('percentage'));

            //创建登陆账号
            $hashedPassword = bcrypt($request->input('password'));
            $phone = $request->input('phone');
            $name = $request->input('name');
            $userWhere = compact('phone', 'agent_id');
            $userData = [
                'password' => $hashedPassword,
                'phone' => $phone,
                'role_id' => Role::ROLE_ADMIN_AGENT,
                'name' => $name,
                'employee_name' => $request->owner_name
            ];

            Employee::updateOrCreate($userWhere, $userData);


            //生成推广码

            $recommendWhere = [
                'user_type' => RecommendCode::TYPE_AGENT,
                'user_id' => $agent_id
            ];
            $rec_code = rand(100000, 999999);
            //找到不重复的rec_code
            $flag = true;
            while ($flag) {
                $one = RecommendCode::where(compact('rec_code'))->first();
                if ($one) {
                    $flag = true;
                    $rec_code = rand(100000, 999999);
                } else {
                    $flag = false;
                }
            }
            RecommendCode::updateOrCreate($recommendWhere, compact('rec_code'));

            DB::commit();
        } catch (\Exception $e) {
            $msg .= $e->getMessage();
            DB::rollBack();
            return self::jsonReturn($instance, self::CODE_FAIL, $msg);
        }

        return self::jsonReturn($instance, self::CODE_SUCCESS, '代理商创建成功');


    }

    /**
     * 下拉列表搜索代理商
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function selectorOptionsList(Request $request)
    {
        if (Auth::user()->isSystemAdmin()) {
            $list = Agent::where('is_locked', '!=', 1)
                ->orderByDesc('updated_time')->select('id', 'agent_level', 'agent_name')
                ->get();
        } else {
            $thisAgent = Auth::user()->agent;
            $list = Agent::getAllChildrenAgentWithMyself($thisAgent);
        }


//        $list = Cache::rememberForever(self::AgentSelectorListCackeKey, function () {
//            return Agent::where('is_locked', '!=', 1)
//                ->orderByDesc('updated_time')->select('id', 'agent_level', 'agent_name')
//                ->get();
//
//        });
        return self::jsonReturn($list);
    }

    /**
     * 代理商的信息信息 配置 附加信息
     * @return string
     */
    public function info(Request $request)
    {
        //代理商基本信息
        $agent_id = $request->input('id');
        $basic = Agent::find($agent_id);
        //代理商配置信息s
        $configs = AgentProfitRateConfig::where(compact('agent_id'))->get();

        //代理商附加信息
        $info = AgentInfo::firstOrNew(['id' => $agent_id]);
        //代理商管理员
        $user = User::where(['agent_id' => $agent_id, 'role_id' => User::RoleAdmin])->first();

        $parent = Agent::find($basic->parent_id);

        return self::jsonReturn(compact('basic', 'info', 'configs', 'user', 'parent'));
    }


    /**
     * 代理商列表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(Request $request)
    {
        $per_page = $request->input('size', self::PAGE_SIZE);
        $keyword = $request->input('keyword');
        //todo:判断权限 增加预设条件
        $query = Agent::orderBy('is_locked', 'asc')->orderBy('created_time', 'desc')
            ->with('parent', 'info', 'percentages');
        if ($keyword) {
            $query->orWhere('agent_name', 'like', "%$keyword%")
                ->orWhere('agent_number', 'like', "$keyword")
                ->orWhere('id', 'like', "%$keyword%");
        }
        $data = $query->paginate($per_page);
        return self::jsonReturn($data);
    }


    public function childrenAgent(Request $request)
    {
        $thisAgent = Agent::with('parent', 'percentages', 'info')->find($request->agent_id);
        if ($thisAgent) {
            $list = Cache::remember(self::generateCacheKeyByReqeust(), 120, function () use ($thisAgent) {
                return $this->getAllChildrenAgentWithMyselfWithInfoParentPercentage($thisAgent);
            });

            return self::jsonReturn($list, self::CODE_SUCCESS);
        } else {
            return self::jsonReturn([], self::CODE_FAIL, '找不到代理商,无效ID');
        }
    }

    /**
     * 修改代理商的管理员密码
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changeAgentAdminUserPassword(Request $request)
    {
        $password = $request->input('password');
        $confirm_password = $request->input('confirm_password');
        $agent_id = $request->input('agent_id');
        $role_id = $request->input('role_id');
        $id = $request->input('id');


        if ($password != $confirm_password) {
            return self::jsonReturn([], self::CODE_FAIL, '两次输入密码不一样');
        }

        try {
            $pp = bcrypt($password);
            User::where(compact('id', 'agent_id', 'role_id'))->update(['password' => $pp]);
            return self::jsonReturn([], self::CODE_SUCCESS, '修改密码成功');

        } catch (\Exception $e) {
            $message = $e->getMessage();
            return self::jsonReturn([], self::CODE_FAIL, $message);
        }


    }

    /**
     * 修改a_agent信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAgentBasic(Request $request)
    {
        Cache::forget(self::AgentSelectorListCackeKey);
//        $validator = \Validator::make($request->all(), [
//            'bank_account' => 'required|unique:a_agent',
//            'agent_name' => 'required|unique:a_agent',
//            'agent_number' => 'required|unique:a_agent',
//            'owner_name' => 'required|unique:a_agent',
//            'phone' => 'required|unique:a_agent',
//
//        ], [
//            'agent_number.unique' => "代理商编号不能重复",
//            'agent_name.unique' => "代理商名称不能重复",
//            'bank_account.unique' => "提现银行卡号重复",
//            'phone.unique' => "联系人手机号码已注册",
//            'owner_name.unique' => "代理商联系人姓名重复",
//
//        ]);
//        if ($validator->fails()) {
//            return parent::jsonReturn([], parent::CODE_FAIL, $validator->errors()->first());
//        }

        try {
            $agent = Agent::find($request->id)->fill($request->except('id'));
            $code = $agent->save();
            return self::jsonReturn($agent, $code, '修改代理商基本信息成功');

        } catch (\Exception $eee) {
            return parent::jsonReturn([], parent::CODE_FAIL, $eee->getMessage());

        }
    }

    /**
     * 修改a_agent_extra_info 表信息
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAgentInfo(Request $request)
    {
        try {
            $agentInfo = AgentInfo::updateOrInsert($request->only('id'), $request->all());
            return self::jsonReturn($agentInfo, self::CODE_SUCCESS, '修改代理商附加信息信息成功');

        } catch (\Exception $eee) {
            return parent::jsonReturn([], parent::CODE_FAIL, $eee->getMessage());

        }
    }

    /**
     * 修改 a_agent_percentage_setting 修改代理商分成表
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAgentPercentage(Request $request)
    {


        try {
            //todo::修改归属关系表
            $agent_id = $request->input('agent_id');

            $id = $request->input('day_id');
            $type = 0;
            $percentage = $request->input('day_percentage');
            AgentProfitRateConfig::updateOrInsert(compact('agent_id', 'id', 'type'), compact('percentage'));

            $id = $request->input('month_id');
            $type = 1;
            $percentage = $request->input('day_percentage');
            AgentProfitRateConfig::updateOrInsert(compact('agent_id', 'id', 'type'), compact('percentage'));

            $id = $request->input('commission_id');
            $type = 2;
            $percentage = $request->input('commission_percentage');
            AgentProfitRateConfig::updateOrInsert(compact('agent_id', 'id', 'type'), compact('percentage'));

            return self::jsonReturn([], self::CODE_SUCCESS, '修改代理商分成配置成功');
        } catch (\Exception $eee) {
            return parent::jsonReturn([], parent::CODE_FAIL, $eee->getMessage());

        }
    }


    private function getAllChildrenAgentWithMyselfWithInfoParentPercentage(Agent $agent)
    {
        $collections = collect([$agent]);

        $parentIds = [$agent->id];
        $i = 0;
        while (count($parentIds)) {
            $childrens = Agent::whereIn('parent_id', $parentIds)->with('parent', 'percentages', 'info')->get();
            if (count($childrens)) {
                $collections = $collections->merge($childrens);
                $parentIds = $childrens->pluck('id')->all();
                $parentIds = array_values($parentIds);
            } else {
                $parentIds = null;
            }
            //双保险
            if ($i > 4) {
                $parentIds = null;
            }
            $i++;
        }
        return $collections;
    }


}
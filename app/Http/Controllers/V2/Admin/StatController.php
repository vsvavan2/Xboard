<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\Request;

class StatController extends Controller
{
    private $service;
    public function __construct(StatisticalService $service)
    {
        $this->service = $service;
    }
    public function getOverride(Request $request)
    {
        try {
            // Получаем онлайн узлов
            $onlineNodes = 0;
            try {
                if (class_exists('\App\Models\Server')) {
                    $onlineNodes = Server::all()->filter(function ($server) {
                        return !!$server->is_online;
                    })->count();
                }
            } catch (\Throwable $e) { /* ignore */ }
            // Получаем онлайн устройства и онлайн пользователей
            $onlineDevices = User::where('t', '>=', time() - 600)
                ->sum('online_count');
            $onlineUsers = User::where('t', '>=', time() - 600)
                ->count();

            // Получаем статистику трафика (если таблицы StatServer отсутствуют -> OlcRTC-only, возвращаем нули)
            $zeroTraffic = ['upload' => 0, 'download' => 0, 'total' => 0];
            $todayTraffic = $zeroTraffic;
            $monthTraffic = $zeroTraffic;
            $totalTraffic = $zeroTraffic;

            try {
                $todayStart = strtotime('today');
                $ts = StatServer::where('record_at', '>=', $todayStart)
                    ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
                    ->first();
                if ($ts) $todayTraffic = ['upload' => $ts->upload ?? 0, 'download' => $ts->download ?? 0, 'total' => $ts->total ?? 0];
            } catch (\Throwable $e) { /* OlcRTC-only */ }
            try {
                $monthStart = strtotime(date('Y-m-1'));
                $ms = StatServer::where('record_at', '>=', $monthStart)
                    ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
                    ->first();
                if ($ms) $monthTraffic = ['upload' => $ms->upload ?? 0, 'download' => $ms->download ?? 0, 'total' => $ms->total ?? 0];
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $tot = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
                    ->first();
                if ($tot) $totalTraffic = ['upload' => $tot->upload ?? 0, 'download' => $tot->download ?? 0, 'total' => $tot->total ?? 0];
            } catch (\Throwable $e) { /* ignore */ }

            $data = [
                'month_income' => (float)Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => (int)User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->count(),
                'ticket_pending_total' => (int)Ticket::where('status', 0)->count(),
                'commission_pending_total' => (int)Order::where('commission_status', 0)
                    ->whereNotNull('invite_user_id')
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => (float)Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => (float)Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => (float)CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
                'commission_last_month_payout' => (float)CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
                'online_nodes' => $onlineNodes,
                'online_devices' => (int)$onlineDevices,
                'online_users' => $onlineUsers,
                'today_traffic' => $todayTraffic,
                'month_traffic' => $monthTraffic,
                'total_traffic' => $totalTraffic,
                'mode' => 'OlcRTC-only',
            ];
            return [
                'data' => $data
            ];
        } catch (\Throwable $e) {
            return [
                'data' => [
                    'month_income' => 0,
                    'month_register_total' => 0,
                    'ticket_pending_total' => 0,
                    'commission_pending_total' => 0,
                    'day_income' => 0,
                    'last_month_income' => 0,
                    'commission_month_payout' => 0,
                    'commission_last_month_payout' => 0,
                    'online_nodes' => 0,
                    'online_devices' => 0,
                    'online_users' => 0,
                    'today_traffic' => ['upload' => 0, 'download' => 0, 'total' => 0],
                    'month_traffic' => ['upload' => 0, 'download' => 0, 'total' => 0],
                    'total_traffic' => ['upload' => 0, 'download' => 0, 'total' => 0],
                    'mode' => 'OlcRTC-only-safe',
                    'error' => $e->getMessage(),
                ]
            ];
        }
    }

    /**
     * Get order statistics with filtering and pagination
     *
     * @param Request $request
     * @return array
     */
    public function getOrder(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|in:paid_total,paid_count,commission_total,commission_count',
        ]);

        $query = Stat::where('record_type', 'd');

        // Apply date filters
        if ($request->input('start_date')) {
            $query->where('record_at', '>=', strtotime($request->input('start_date')));
        }
        if ($request->input('end_date')) {
            $query->where('record_at', '<=', strtotime($request->input('end_date') . ' 23:59:59'));
        }

        $statistics = $query->orderBy('record_at', 'DESC')
            ->get();

        $summary = [
            'paid_total' => 0,
            'paid_count' => 0,
            'commission_total' => 0,
            'commission_count' => 0,
            'start_date' => $request->input('start_date', date('Y-m-d', $statistics->last()?->record_at)),
            'end_date' => $request->input('end_date', date('Y-m-d', $statistics->first()?->record_at)),
            'avg_paid_amount' => 0,
            'avg_commission_amount' => 0
        ];

        $dailyStats = [];
        foreach ($statistics as $statistic) {
            $date = date('Y-m-d', $statistic['record_at']);

            // Update summary
            $summary['paid_total'] += $statistic['paid_total'];
            $summary['paid_count'] += $statistic['paid_count'];
            $summary['commission_total'] += $statistic['commission_total'];
            $summary['commission_count'] += $statistic['commission_count'];

            // Calculate daily stats
            $dailyData = [
                'date' => $date,
                'paid_total' => $statistic['paid_total'],
                'paid_count' => $statistic['paid_count'],
                'commission_total' => $statistic['commission_total'],
                'commission_count' => $statistic['commission_count'],
                'avg_order_amount' => $statistic['paid_count'] > 0 ? round($statistic['paid_total'] / $statistic['paid_count'], 2) : 0,
                'avg_commission_amount' => $statistic['commission_count'] > 0 ? round($statistic['commission_total'] / $statistic['commission_count'], 2) : 0
            ];

            if ($request->input('type')) {
                $dailyStats[] = [
                    'date' => $date,
                    'value' => $statistic[$request->input('type')],
                    'type' => $this->getTypeLabel($request->input('type'))
                ];
            } else {
                $dailyStats[] = $dailyData;
            }
        }

        // Calculate averages for summary
        if ($summary['paid_count'] > 0) {
            $summary['avg_paid_amount'] = round($summary['paid_total'] / $summary['paid_count'], 2);
        }
        if ($summary['commission_count'] > 0) {
            $summary['avg_commission_amount'] = round($summary['commission_total'] / $summary['commission_count'], 2);
        }

        // Add percentage calculations to summary
        $summary['commission_rate'] = $summary['paid_total'] > 0
            ? round(($summary['commission_total'] / $summary['paid_total']) * 100, 2)
            : 0;

        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'list' => array_reverse($dailyStats),
                'summary' => $summary,
            ]
        ];
    }

    /**
     * Get human readable label for statistic type
     *
     * @param string $type
     * @return string
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type
        };
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $data = $this->service->getServerRank();
        return $this->success(data: $data);
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $data = $this->service->getServerRank('yesterday');
        return $this->success($data);
    }

    public function getStatUser(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|integer'
            ]);
            $pageSize = $request->input('pageSize', 10);
            $records = StatUser::orderBy('record_at', 'DESC')
                ->where('user_id', $request->input('user_id'))
                ->paginate($pageSize);
            $data = $records->items();
            return [
                'data' => $data,
                'total' => $records->total(),
            ];
        } catch (\Throwable $e) {
            return [
                'data' => [],
                'total' => 0,
                'mode' => 'OlcRTC-only-safe',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getStatRecord(Request $request)
    {
        try {
            return [
                'data' => $this->service->getStatRecord($request->input('type'))
            ];
        } catch (\Throwable $e) {
            return ['data' => [], 'mode' => 'OlcRTC-only-safe', 'error' => $e->getMessage()];
        }
    }

    /**
     * Get comprehensive statistics data including income, users, and growth rates
     */
    public function getStats()
    {
        try {
            $currentMonthStart = strtotime(date('Y-m-01'));
            $lastMonthStart = strtotime('-1 month', $currentMonthStart);
            $twoMonthsAgoStart = strtotime('-2 month', $currentMonthStart);
            $todayStart = strtotime('today');
            $yesterdayStart = strtotime('-1 day', $todayStart);

            $onlineNodes = 0;
            try {
                if (class_exists('\App\Models\Server')) {
                    $onlineNodes = Server::all()->filter(function ($server) {
                        return !!$server->is_online;
                    })->count();
                }
            } catch (\Throwable $e) { /* ignore */ }

            $onlineDevices = User::where('t', '>=', time() - 600)->sum('online_count');
            $onlineUsers = User::where('t', '>=', time() - 600)->count();

            $zero = ['upload' => 0, 'download' => 0, 'total' => 0];
            $todayTraffic = $zero;
            $monthTraffic = $zero;
            $totalTraffic = $zero;

            try {
                $ts = StatServer::where('record_at', '>=', $todayStart)
                    ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
                    ->first();
                if ($ts) $todayTraffic = ['upload' => $ts->upload ?? 0, 'download' => $ts->download ?? 0, 'total' => $ts->total ?? 0];
            } catch (\Throwable $e) { /* OlcRTC-only */ }
            try {
                $ms = StatServer::where('record_at', '>=', $currentMonthStart)
                    ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
                    ->first();
                if ($ms) $monthTraffic = ['upload' => $ms->upload ?? 0, 'download' => $ms->download ?? 0, 'total' => $ms->total ?? 0];
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $tot = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')->first();
                if ($tot) $totalTraffic = ['upload' => $tot->upload ?? 0, 'download' => $tot->download ?? 0, 'total' => $tot->total ?? 0];
            } catch (\Throwable $e) { /* ignore */ }

            $todayIncome = (float)Order::where('created_at', '>=', $todayStart)->whereNotIn('status', [0, 2])->sum('total_amount');
            $yesterdayIncome = (float)Order::where('created_at', '>=', $yesterdayStart)->where('created_at', '<', $todayStart)->whereNotIn('status', [0, 2])->sum('total_amount');
            $currentMonthIncome = (float)Order::where('created_at', '>=', $currentMonthStart)->whereNotIn('status', [0, 2])->sum('total_amount');
            $lastMonthIncome = (float)Order::where('created_at', '>=', $lastMonthStart)->where('created_at', '<', $currentMonthStart)->whereNotIn('status', [0, 2])->sum('total_amount');
            $lastMonthCommissionPayout = (float)CommissionLog::where('created_at', '>=', $lastMonthStart)->where('created_at', '<', $currentMonthStart)->sum('get_amount');
            $currentMonthCommissionPayout = (float)CommissionLog::where('created_at', '>=', $currentMonthStart)->sum('get_amount');
            $currentMonthNewUsers = (int)User::where('created_at', '>=', $currentMonthStart)->count();
            $totalUsers = (int)User::count();
            $activeUsers = (int)User::where(function ($query) {
                $query->where('expired_at', '>=', time())->orWhereNull('expired_at');
            })->count();
            $twoMonthsAgoIncome = (float)Order::where('created_at', '>=', $twoMonthsAgoStart)->where('created_at', '<', $lastMonthStart)->whereNotIn('status', [0, 2])->sum('total_amount');
            $twoMonthsAgoCommission = (float)CommissionLog::where('created_at', '>=', $twoMonthsAgoStart)->where('created_at', '<', $lastMonthStart)->sum('get_amount');
            $lastMonthNewUsers = (int)User::where('created_at', '>=', $lastMonthStart)->where('created_at', '<', $currentMonthStart)->count();
            $monthIncomeGrowth = $lastMonthIncome > 0 ? round(($currentMonthIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : 0;
            $lastMonthIncomeGrowth = $twoMonthsAgoIncome > 0 ? round(($lastMonthIncome - $twoMonthsAgoIncome) / $twoMonthsAgoIncome * 100, 1) : 0;
            $commissionGrowth = $twoMonthsAgoCommission > 0 ? round(($lastMonthCommissionPayout - $twoMonthsAgoCommission) / $twoMonthsAgoCommission * 100, 1) : 0;
            $userGrowth = $lastMonthNewUsers > 0 ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1) : 0;
            $dayIncomeGrowth = $yesterdayIncome > 0 ? round(($todayIncome - $yesterdayIncome) / $yesterdayIncome * 100, 1) : 0;
            $ticketPendingTotal = (int)Ticket::where('status', 0)->count();
            try {
                $commissionPendingTotal = (int)Order::where('commission_status', 0)
                    ->whereNotNull('invite_user_id')
                    ->whereIn('status', [Order::STATUS_COMPLETED])
                    ->where('commission_balance', '>', 0)
                    ->count();
            } catch (\Throwable $e) {
                $commissionPendingTotal = 0;
            }

            return [
                'data' => [
                    'todayIncome' => $todayIncome,
                    'dayIncomeGrowth' => $dayIncomeGrowth,
                    'currentMonthIncome' => $currentMonthIncome,
                    'lastMonthIncome' => $lastMonthIncome,
                    'monthIncomeGrowth' => $monthIncomeGrowth,
                    'lastMonthIncomeGrowth' => $lastMonthIncomeGrowth,
                    'currentMonthCommissionPayout' => $currentMonthCommissionPayout,
                    'lastMonthCommissionPayout' => $lastMonthCommissionPayout,
                    'commissionGrowth' => $commissionGrowth,
                    'commissionPendingTotal' => $commissionPendingTotal,
                    'currentMonthNewUsers' => $currentMonthNewUsers,
                    'totalUsers' => $totalUsers,
                    'activeUsers' => $activeUsers,
                    'userGrowth' => $userGrowth,
                    'onlineUsers' => $onlineUsers,
                    'onlineDevices' => (int)$onlineDevices,
                    'ticketPendingTotal' => $ticketPendingTotal,
                    'onlineNodes' => $onlineNodes,
                    'todayTraffic' => $todayTraffic,
                    'monthTraffic' => $monthTraffic,
                    'totalTraffic' => $totalTraffic,
                    'mode' => 'OlcRTC-only',
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'data' => [
                    'todayIncome' => 0, 'dayIncomeGrowth' => 0,
                    'currentMonthIncome' => 0, 'lastMonthIncome' => 0,
                    'monthIncomeGrowth' => 0, 'lastMonthIncomeGrowth' => 0,
                    'currentMonthCommissionPayout' => 0, 'lastMonthCommissionPayout' => 0,
                    'commissionGrowth' => 0, 'commissionPendingTotal' => 0,
                    'currentMonthNewUsers' => 0, 'totalUsers' => 0, 'activeUsers' => 0,
                    'userGrowth' => 0, 'onlineUsers' => 0, 'onlineDevices' => 0,
                    'ticketPendingTotal' => 0, 'onlineNodes' => 0,
                    'todayTraffic' => ['upload' => 0, 'download' => 0, 'total' => 0],
                    'monthTraffic' => ['upload' => 0, 'download' => 0, 'total' => 0],
                    'totalTraffic' => ['upload' => 0, 'download' => 0, 'total' => 0],
                    'mode' => 'OlcRTC-only-safe',
                    'error' => $e->getMessage(),
                ]
            ];
        }
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999'
        ]);

        $type = $request->input('type');
        $startDate = $request->input('start_time', strtotime('-7 days'));
        $endDate = $request->input('end_time', time());
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;

        if ($type === 'node') {
            // Get node traffic data
            $currentData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('server_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->keyBy('id');

        } else {
            // Get user traffic data
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        $ids = $currentData->pluck('id');
        $names = $type === 'node'
            ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        foreach ($currentData as $data) {
            $previousValue = isset($previousData[$data->id]) ? $previousData[$data->id]->value : 0;
            $change = $previousValue > 0 ? round(($data->value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $data->id,
                'name' => $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }
}

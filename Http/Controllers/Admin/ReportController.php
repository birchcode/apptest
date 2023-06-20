<?php

namespace App\Http\Controllers\Admin;

use App\Models\Zone;
use App\Models\Order;
use App\Models\Expense;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Carbon;
use App\Scopes\RestaurantScope;
use App\Models\OrderTransaction;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Food;
use Rap2hpoutre\FastExcel\FastExcel;
use App\Models\SubscriptionTransaction;

class ReportController extends Controller
{
    public function order_index()
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }
        return view('admin-views.report.order-index');
    }

    public function day_wise_report(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        $from = session('from_date');
        $to = session('to_date');

         $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
       // $zone_id = $request->query('zone_id', $request->zone_id ?$request->zone_id :'all');
       // dd($zone_id);
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $order_transactions=OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->get();

        $order_transactions_list = OrderTransaction::with('order')->when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->latest()
        ->paginate(config('default_pagination'))->withQueryString();

        return view('admin-views.report.day-wise-report', compact('zone','order_transactions','order_transactions_list'));
    }

    public function food_wise_report(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;
        $foods = Food::withoutGlobalScope(RestaurantScope::class)->withCount([
            'orders' => function($query)use($from, $to) {
                $query->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
            },
        ])
        ->when(isset($zone), function($query)use($zone){
            return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
        })
        ->when(isset($restaurant), function($query)use($restaurant){
            return $query->where('restaurant_id', $restaurant->id);
        })
        ->orderBy('orders_count', 'desc')
        ->paginate(config('default_pagination'))->withQueryString();
        return view('admin-views.report.food-wise-report', compact('zone', 'restaurant', 'foods'));
    }

    public function order_transaction()
    {
        $order_transactions = OrderTransaction::latest()->paginate(config('default_pagination'));
        return view('admin-views.report.order-transactions', compact('order_transactions'));
    }


    public function set_date(Request $request)
    {
        session()->put('from_date', date('Y-m-d', strtotime($request['from'])));
        session()->put('to_date', date('Y-m-d', strtotime($request['to'])));
        return back();
    }

    public function food_search(Request $request){
        $key = explode(' ', $request['search']);

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;
        $foods = \App\Models\Food::withoutGlobalScope(RestaurantScope::class)->withCount([
            'orders as order_count' => function($query)use($from, $to) {
                $query->whereBetween('created_at', [$from, $to]);
            },
        ])
        ->when(isset($zone), function($query)use($zone){
            return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
        })
        ->when(isset($restaurant), function($query)use($restaurant){
            return $query->where('restaurant_id', $restaurant->id);
        })
        ->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('name', 'like', "%{$value}%");
            }
        })
        ->limit(25)->get();

        return response()->json(['count'=>count($foods),
            'view'=>view('admin-views.report.partials._food_table',compact('foods'))->render()
        ]);
    }
    public function day_search(Request $request){
        $key = explode(' ', $request['search']);

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $ot = \App\Models\OrderTransaction::
        where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('order_id', 'like', "%{$value}%");
            }
        })
        ->first();

        return response()->json([
            'view'=>view('admin-views.report.partials._day_table',compact('ot'))->render()
        ]);
    }

    public function food_wise_report_export(Request $request){
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;
        $foods = \App\Models\Food::withoutGlobalScope(RestaurantScope::class)->withCount([
            'orders' => function($query)use($from, $to) {
                $query->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
            },
        ])
        ->when(isset($zone), function($query)use($zone){
            return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
        })
        ->when(isset($restaurant), function($query)use($restaurant){
            return $query->where('restaurant_id', $restaurant->id);
        })
        ->orderBy('orders_count', 'desc')
        ->get();




        if($request->type == 'excel'){
            return (new FastExcel(Helpers::food_wise_report_export($foods)))->download('FoodWiseDailyReport.xlsx');
        }elseif($request->type == 'csv'){
            return (new FastExcel(Helpers::food_wise_report_export($foods)))->download('FoodWiseDailyReport.csv');
        }
    }

    public function day_wise_report_export(Request $request){
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $order_transactions=\App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->orderBy('id','desc')->get();


        if($request->type == 'excel'){
            return (new FastExcel(Helpers::export_day_wise_report($order_transactions)))->download('DayWiseDailyReport.xlsx');
        }elseif($request->type == 'csv'){
            return (new FastExcel(Helpers::export_day_wise_report($order_transactions)))->download('DayWiseDailyReport.csv');
        }
    }

    public function expense_report(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', now()->firstOfMonth()->format('Y-m-d'));
            session()->put('to_date', now()->lastOfMonth()->format('Y-m-d'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $expense = Expense::whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:29'])
        ->paginate(config('default_pagination'))->withQueryString();

        return view('admin-views.report.expense-report', compact('expense'));
    }
    public function expense_export(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', now()->firstOfMonth()->format('Y-m-d'));
            session()->put('to_date', now()->lastOfMonth()->format('Y-m-d'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $expense = Expense::whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:29'])
        ->get();

        if($request->type == 'excel'){
            return (new FastExcel($expense))->download('ExpenseReport.xlsx');
        }elseif($request->type == 'csv'){
            return (new FastExcel($expense))->download('ExpenseReport.csv');
        }
    }

    public function expense_search(Request $request){
        $key = explode(' ', $request['search']);

        $from = session('from_date');
        $to = session('to_date');

        $expense = Expense::whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:29'])
        ->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('type', 'like', "%{$value}%")->orWhere('description', 'like', "%{$value}%");
            }
        })
        ->limit(25)->get();

        return response()->json(['count'=>count($expense),
            'view'=>view('admin-views.report.partials._expense_table',compact('expense'))->render()
        ]);
    }


    public function subscription_report(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', now()->firstOfMonth()->format('Y-m-d'));
            session()->put('to_date', now()->lastOfMonth()->format('Y-m-d'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $subscriptions = SubscriptionTransaction::whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:29'])
        ->with('restaurant')
        ->paginate(config('default_pagination'))->withQueryString();

        return view('admin-views.report.subscription-report', compact('subscriptions'));
    }

    public function subscription_export(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', now()->firstOfMonth()->format('Y-m-d'));
            session()->put('to_date', now()->lastOfMonth()->format('Y-m-d'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $subscriptions = SubscriptionTransaction::whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:29'])
        ->with('restaurant')
        ->get();

        if($request->type == 'excel'){
            return (new FastExcel(Helpers::export_subscription($subscriptions)))->download('SubscriptionsReport.xlsx');
        }elseif($request->type == 'csv'){
            return (new FastExcel(Helpers::export_subscription($subscriptions)))->download('SubscriptionsReport.csv');
        }
    }

    public function subscription_search(Request $request){
        $key = explode(' ', $request['search']);

        $from = session('from_date');
        $to = session('to_date');

        $subscriptions = SubscriptionTransaction::whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:29'])
        ->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('id', 'like', "%{$value}%")
                    ->orWhere('paid_amount', 'like', "%{$value}%")
                    ->orWhere('reference', 'like', "%{$value}%");
            }
        })
        ->with('restaurant')
        ->orwhereHas('restaurant',function($query)use($key){
            foreach ($key as $v) {
                $query->where('name', 'like', "%{$v}%")
                        ->orWhere('email', 'like', "%{$v}%");
            }
        })
        ->latest()->paginate(config('default_pagination'));

        return response()->json(['count'=>count($subscriptions),
            'view'=>view('admin-views.report.partials._subscription_table',compact('subscriptions'))->render()
        ]);
    }

}

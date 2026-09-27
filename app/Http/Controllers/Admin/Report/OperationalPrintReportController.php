<?php

namespace App\Http\Controllers\Admin\Report;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerLedgerEntry;
use App\Models\User;
use App\Models\PlatformDailyMetric;
use App\Utils\Helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class OperationalPrintReportController extends Controller
{
    public function __invoke(Request $request): View
    {
        $section = $request->validate([
            'section' => 'required|in:customers,sellers,orders,shipping,refunds,reviews,finances,insurance,invoices,visits',
            'from' => 'nullable|date', 'to' => 'nullable|date', 'q' => 'nullable|string|max:100',
        ])['section'];
        $this->authorizeSection($section);
        [$columns, $rows] = $this->data($section, $request);

        return view('admin-views.report.operational-print', [
            'section' => $section, 'columns' => $columns, 'rows' => $rows,
            'filters' => $request->only(['from', 'to', 'q']),
        ]);
    }

    private function authorizeSection(string $section): void
    {
        $admin = auth('admin')->user();
        if ((int) $admin?->admin_role_id === 1) return;
        $permission = in_array($section, ['customers', 'sellers'], true) ? 'people'
            : (in_array($section, ['orders', 'shipping', 'refunds', 'invoices'], true) ? 'orders'
                : (in_array($section, ['reviews'], true) ? 'catalog' : 'reports'));
        abort_unless(Helpers::module_permission_check($permission), 403);
    }

    private function data(string $section, Request $request): array
    {
        $from = $request->get('from'); $to = $request->get('to'); $q = trim((string) $request->get('q'));
        $date = function ($query) use ($from, $to) {
            if ($from) $query->whereDate('created_at', '>=', $from);
            if ($to) $query->whereDate('created_at', '<=', $to);
            return $query;
        };

        return match ($section) {
            'customers' => [['reference','name','email','phone','date'], $date(User::query())->when($q, fn ($x) => $x->where(fn ($i) => $i->where('email','like',"%$q%")->orWhere('phone','like',"%$q%")))->latest('id')->limit(500)->get()->map(fn ($x) => ['reference'=>'C'.$x->id,'name'=>trim($x->f_name.' '.$x->l_name),'email'=>$x->email,'phone'=>$x->phone,'date'=>$x->created_at])],
            'sellers' => [['reference','name','email','phone','date'], $date(Seller::query())->when($q, fn ($x) => $x->where(fn ($i) => $i->where('email','like',"%$q%")->orWhere('phone','like',"%$q%")))->latest('id')->limit(500)->get()->map(fn ($x) => ['reference'=>'V'.$x->id,'name'=>trim($x->f_name.' '.$x->l_name),'email'=>$x->email,'phone'=>$x->phone,'date'=>$x->created_at])],
            'orders', 'invoices' => [['order','customer','seller','status','payment','amount','date'], $date(Order::query())->when($q, fn ($x) => $x->where('id','like',"%$q%")->orWhere('shipment_reference','like',"%$q%"))->latest('id')->limit(500)->get()->map(fn ($x) => ['order'=>'#'.$x->id,'customer'=>'C'.$x->customer_id,'seller'=>$x->seller_is === 'seller' ? 'V'.$x->seller_id : 'Admin','status'=>$x->order_status,'payment'=>$x->payment_status,'amount'=>$x->order_amount,'date'=>$x->created_at])],
            'shipping' => [['shipment','order','party','status','customer_cost','seller_cost','tracking','date'], $date(Order::query()->whereNotNull('shipment_reference'))->when($q, fn ($x) => $x->where('shipment_reference','like',"%$q%")->orWhere('id','like',"%$q%"))->latest('id')->limit(500)->get()->map(fn ($x) => ['shipment'=>$x->shipment_reference,'order'=>'#'.$x->id,'party'=>$x->shipping_responsible_party,'status'=>$x->shipping_operational_status,'customer_cost'=>$x->shipping_customer_cost,'seller_cost'=>$x->shipping_seller_cost,'tracking'=>$x->third_party_delivery_tracking_id,'date'=>$x->created_at])],
            'reviews' => [['product','seller','name','status','reason','date'], $date(Product::query()->where('added_by','seller'))->when($q, fn ($x) => $x->where('name','like',"%$q%")->orWhere('id','like',"%$q%"))->latest('id')->limit(500)->get()->map(fn ($x) => ['product'=>'#'.$x->id,'seller'=>'V'.$x->user_id,'name'=>$x->name,'status'=>$x->request_status,'reason'=>$x->denied_note ?? $x->seller_review_reason ?? '', 'date'=>$x->created_at])],
            'finances' => [['reference','seller','category','bucket','event','direction','amount','date'], $date(SellerLedgerEntry::query())->when($q, fn ($x) => $x->where('reference_code','like',"%$q%")->orWhere('event_type','like',"%$q%"))->latest('id')->limit(500)->get()->map(fn ($x) => ['reference'=>$x->reference_code,'seller'=>'V'.$x->seller_id,'category'=>$x->reporting_category,'bucket'=>$x->bucket,'event'=>$x->event_type,'direction'=>$x->direction,'amount'=>$x->amount,'date'=>$x->created_at])],
            'refunds' => $this->tableReport('refund_requests', ['id','order_id','seller_id','status','amount','created_at'], $from, $to, $q),
            'insurance' => $this->insuranceRows($from, $to, $q),
            'visits' => [['date','channel','page_views','requests'], Schema::hasTable('platform_daily_metrics') ? PlatformDailyMetric::query()->when($from, fn ($x) => $x->whereDate('metric_date','>=',$from))->when($to, fn ($x) => $x->whereDate('metric_date','<=',$to))->when($q, fn ($x) => $x->where('channel',$q))->latest('metric_date')->limit(500)->get()->map(fn ($x) => ['date'=>$x->metric_date?->toDateString(),'channel'=>$x->channel,'page_views'=>$x->page_views,'requests'=>$x->requests]) : collect()],
        };
    }

    private function tableReport(string $table, array $columns, ?string $from, ?string $to, string $q): array
    {
        if (! Schema::hasTable($table)) return [$columns, collect()];
        $available = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
        $query = DB::table($table)->select($available);
        if ($from) $query->whereDate('created_at','>=',$from); if ($to) $query->whereDate('created_at','<=',$to);
        if ($q && in_array('order_id',$available,true)) $query->where('order_id','like',"%$q%");
        return [$available, $query->latest('id')->limit(500)->get()->map(fn ($row) => (array) $row)];
    }

    private function insuranceRows(?string $from, ?string $to, string $q): array
    {
        $rows = collect();
        foreach ([['order_insurances','customer'], ['seller_order_insurances','seller']] as [$table,$party]) {
            if (! Schema::hasTable($table)) continue;
            $query = DB::table($table); if ($from) $query->whereDate('created_at','>=',$from); if ($to) $query->whereDate('created_at','<=',$to);
            if ($q) $query->where('order_id','like',"%$q%");
            $rows = $rows->concat($query->latest('id')->limit(250)->get()->map(fn ($x) => ['party'=>$party,'order'=>'#'.$x->order_id,'account'=>$party === 'seller' ? 'V'.$x->seller_id : 'C'.$x->customer_id,'status'=>$x->status,'payment'=>$x->payment_status,'amount'=>$x->amount,'date'=>$x->created_at]));
        }
        return [['party','order','account','status','payment','amount','date'], $rows->sortByDesc('date')->values()];
    }
}

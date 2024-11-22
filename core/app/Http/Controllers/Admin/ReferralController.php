<?php

namespace App\Http\Controllers\Admin;

use App\Models\Referral;
use Illuminate\Http\Request;
use App\Models\GeneralSetting;
use App\Http\Controllers\Controller;

class ReferralController extends Controller
{
    public function index()
    {
        $pageTitle       = 'Manage Referral';
        $referrals       = Referral::get();
        $commissionTypes = [
            'deposit_commission' => 'Deposit Commission',
            'profit_commission'  => 'Profit Commission',
            'thrift_commission'  => 'Thrift Commission',
            'easyland_commission'  => 'Easyland Commission',
            'basic_reg_commission'  => 'Basic Reg Commission',
            'ass_mbmr_reg_commission'  => 'Ass Mbmr Reg Commission',
            'ass_prtnr_reg_commission'  => 'Ass Prtnr Reg Commission',
            'rentals_commission'  => 'Rentals Commission',
            'voucher_commission'  => 'Voucher Commission',
            'fufu_commission'  => 'Fufu Commission',
            'grains_commission'   => 'Grains Commission',
            '_other_fdcom_items_commission'   => 'Other FdItem Commission',
            'fish_commission'   => 'Fish Commission',
            'ingredients_commission'   => 'Ingredients Commission',
            'custard_commission'   => 'Custard Commission',
            'leaf_commission'   => 'Leaf Commission',
            'basic_prtnr_fdreg_comm' => 'Basic Prtnr FdComm',
            'silver_prtnr_fdreg_comm' => 'Silver Prtnr FdComm',
            'gold_prtnr_fdreg_comm' => 'Gold Prtnr FdComm',
            'diamond_prtnr_fdreg_comm' => 'Diamond Prtnr FdComm',
        ];
        return view('admin.referral.index', compact('pageTitle', 'referrals', 'commissionTypes'));
    }

    public function status($type)
    {
        return GeneralSetting::changeStatus(1, $type);
    }

    public function update(Request $request)
    {
        $request->validate([
            'percent'         => 'required',
            'percent*'        => 'required|numeric',
            'commission_type' => 'required|in:deposit_commission,basic_reg_commission,profit_commission,rentals_commission,voucher_commission,thrift_commission,easyland_commission,ass_mbmr_reg_commission,ass_prtnr_reg_commission,fufu_commission,grains_commission,_other_fdcom_items_commission,fish_commission,ingredients_commission,custard_commission,basic_prtnr_fdreg_comm,silver_prtnr_fdreg_comm,gold_prtnr_fdreg_comm,diamond_prtnr_fdreg_comm,leaf_commission',
        ]);
        $type = $request->commission_type;

        Referral::where('commission_type', $type)->delete();

        for ($i = 0; $i < count($request->percent); $i++) {
            $referral                  = new Referral();
            $referral->level           = $i + 1;
            $referral->percent         = $request->percent[$i];
            $referral->commission_type = $request->commission_type;
            $referral->save();
        }

        $notify[] = ['success', 'Referral commission setting updated successfully'];
        return back()->withNotify($notify);
    }
}

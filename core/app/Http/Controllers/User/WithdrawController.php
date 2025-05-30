<?php

namespace App\Http\Controllers\User;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Lib\FormProcessor;
use App\Models\AdminNotification;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Models\WithdrawMethod;
use App\Models\Invest;
use Illuminate\Http\Request;

class WithdrawController extends Controller
{
    public function withdrawMoney()
    {
        $withdrawMethod = WithdrawMethod::where('status', Status::ENABLE)->get();
        $pageTitle = 'Withdraw Money';
        return view($this->activeTemplate . 'user.withdraw.methods', compact('pageTitle', 'withdrawMethod'));
    }

    public function withdrawStore(Request $request)
    {
        $this->validate($request, [
            'method_code' => 'required',
            'amount' => 'required|numeric',
            'wallet_type' => 'required|in:Ref Commission Wallet,Foodmall Wallet,ROI Wallet' // Validate dropdown option
        ]);
        $method = WithdrawMethod::where('id', $request->method_code)->where('status', Status::ENABLE)->firstOrFail();
        $user = auth()->user();
        $walletType = $request->wallet_type;

        // Set balance based on wallet type
        if ($walletType === 'Foodmall Wallet') {
            $user->balance = $user->direct_sales_comm + $user->referrals_sales_comm;
        }
        
        if ($walletType === 'Ref Commission Wallet') {
            $user->balance = $user->referral_balance;
        }

       if ($walletType === 'ROI Wallet') {
            $currentDate = now(); // Current date
        
            // Get all completed investments for the user
            $investments = Invest::where('user_id', $user->id)
                ->where('invest_status', Status::COMPLETED)
                ->get();
        
            $totalEligibleProfit = 0; // Sum of profits eligible for withdrawal
            $ineligibleInvestments = []; // Track investments that are ineligible
        
            foreach ($investments as $investment) {
                // Extract numeric value from invest_duration string
                preg_match('/\d+/', $investment->invest_duration, $matches);
                $durationMonths = isset($matches[0]) ? (int)$matches[0] : 0;
        
                // Get the creation date of the investment
                $createdDate = $investment->created_at;
        
                // Calculate the required completion date
                $requiredCompletionDate = $createdDate->copy()->addMonths($durationMonths);
        
                // Check if the required duration has passed
                if ($currentDate->greaterThanOrEqualTo($requiredCompletionDate)) {
                    // Add profit from eligible investments
                    $totalEligibleProfit += $investment->total_profit;
                } else {
                    // Track investments that are not yet eligible
                    $ineligibleInvestments[] = $investment->id;
                }
            }
        
            if ($totalEligibleProfit > 0) {
                // Allow withdrawal of eligible profits
                $user->balance = $totalEligibleProfit;
            } else {
                // Notify user if no investments are eligible
                $notify[] = ['error', 'No investments are eligible for profit withdrawal at this time.'];
                return back()->withNotify($notify);
            }
        
            if (!empty($ineligibleInvestments)) {
                \Log::info("Ineligible Investments: " . implode(', ', $ineligibleInvestments));
            }
        }

        if ($request->amount < $method->min_limit) {
            $notify[] = ['error', 'Your requested amount is smaller than minimum amount.'];
            return back()->withNotify($notify);
        }
        if ($request->amount > $method->max_limit) {
            $notify[] = ['error', 'Your requested amount is larger than maximum amount.'];
            return back()->withNotify($notify);
        }

        if ($request->amount > $user->balance) {
            $notify[] = ['error', 'You do not have sufficient balance for withdraw.'];
            return back()->withNotify($notify);
        }

        $charge = $method->fixed_charge + ($request->amount * $method->percent_charge / 100);
        $afterCharge = $request->amount - $charge;
        $finalAmount = $afterCharge * $method->rate;

        $withdraw = new Withdrawal();
        $withdraw->method_id = $method->id; // wallet method ID
        $withdraw->user_id = $user->id;
        $withdraw->amount = $request->amount;
        $withdraw->currency = $method->currency;
        $withdraw->rate = $method->rate;
        $withdraw->charge = $charge;
        $withdraw->final_amount = $finalAmount;
        $withdraw->after_charge = $afterCharge;
        $withdraw->trx = getTrx();
        $withdraw->save();
        session()->put('wtrx', $withdraw->trx);
        return to_route('user.withdraw.preview');
    }

    public function withdrawPreview()
    {
        $withdraw = Withdrawal::with('method', 'user')->where('trx', session()->get('wtrx'))->where('status', Status::PAYMENT_INITIATE)->orderBy('id', 'desc')->firstOrFail();
        $pageTitle = 'Withdraw Preview';
        $user = auth()->user();
        return view($this->activeTemplate . 'user.withdraw.preview', compact('pageTitle', 'withdraw', 'user'));
    }

    public function withdrawSubmit(Request $request)
    {
        $withdraw = Withdrawal::with('method', 'user')->where('trx', session()->get('wtrx'))->where('status', Status::PAYMENT_INITIATE)->orderBy('id', 'desc')->firstOrFail();

        $method = $withdraw->method;
        if ($method->status == Status::DISABLE) {
            abort(404);
        }

        $formData = $method->form->form_data;

        $formProcessor = new FormProcessor();
        $validationRule = $formProcessor->valueValidation($formData);
        $request->validate($validationRule);
        $userData = $formProcessor->processFormData($request, $formData);

        $user = auth()->user();
        if ($user->ts) {
            $response = verifyG2fa($user, $request->authenticator_code);
            if (!$response) {
                $notify[] = ['error', 'Wrong verification code'];
                return back()->withNotify($notify);
            }
        }

        if ($withdraw->amount > $user->balance) {
            $notify[] = ['error', 'Your request amount is larger then your current balance.'];
            return back()->withNotify($notify);
        }

        $withdraw->status = Status::PAYMENT_PENDING;
        $withdraw->withdraw_information = $userData;
        $withdraw->save();
        //from here, put a demarcation to separate debits from the three different wallets

        //wallet for ROI = $widget['total_profit']        = Invest::where('user_id', $user->id)->where('invest_status', Status::COMPLETED)->sum('total_profit');

        //wallet for Ref Commission = $user->referral_balance;

        //wallet for foodmall = $user->direct_sales_comm + $user->referrals_sales_comm;
        
        $user->balance  -=  $withdraw->amount;
        $user->save();

        $transaction = new Transaction();
        $transaction->user_id = $withdraw->user_id;
        $transaction->amount = $withdraw->amount;
        $transaction->post_balance = $user->balance;
        $transaction->charge = $withdraw->charge;
        $transaction->trx_type = '-';
        $transaction->details = showAmount($withdraw->final_amount) . ' ' . $withdraw->currency . ' Withdraw Via ' . $withdraw->method->name;
        $transaction->trx = $withdraw->trx;
        $transaction->remark = 'withdraw';
        $transaction->save();

        $adminNotification = new AdminNotification();
        $adminNotification->user_id = $user->id;
        $adminNotification->title = 'New withdraw request from ' . $user->username;
        $adminNotification->click_url = urlPath('admin.withdraw.details', $withdraw->id);
        $adminNotification->save();

        notify($user, 'WITHDRAW_REQUEST', [
            'method_name' => $withdraw->method->name,
            'method_currency' => $withdraw->currency,
            'method_amount' => showAmount($withdraw->final_amount),
            'amount' => showAmount($withdraw->amount),
            'charge' => showAmount($withdraw->charge),
            'rate' => showAmount($withdraw->rate),
            'trx' => $withdraw->trx,
            'post_balance' => showAmount($user->balance),
        ]);

        $notify[] = ['success', 'Withdraw request sent successfully'];
        return to_route('user.withdraw.history')->withNotify($notify);
    }

    public function withdrawLog(Request $request)
    {
        $pageTitle = "Withdraw Log";
        $withdraws = Withdrawal::where('user_id', auth()->id())->where('status', '!=', Status::PAYMENT_INITIATE);
        if ($request->search) {
            $withdraws = $withdraws->where('trx', $request->search);
        }
        $withdraws = $withdraws->with('method')->orderBy('id', 'desc')->paginate(getPaginate());
        return view($this->activeTemplate . 'user.withdraw.log', compact('pageTitle', 'withdraws'));
    }
}
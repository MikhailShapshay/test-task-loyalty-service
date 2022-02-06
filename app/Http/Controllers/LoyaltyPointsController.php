<?php

namespace App\Http\Controllers;

use App\Mail\LoyaltyPointsReceived;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointsRule;
use App\Models\LoyaltyPointsTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LoyaltyPointsController extends Controller
{
    /**
     * @var LoyaltyAccount
     */
    private $account;

    public function __construct()
    {
        $this->account = new AccountController();
    }

    /**
     * Метод Начисление баллов лояльности
     *
     * account_id        - значение поля из типа
     * account_type  - тип запроса (из массива $account_type)
     * loyalty_points_rule  - правило начисления
     * description  - описание
     * payment_id  - ID платежа
     * payment_amount  - сумма платежа
     * payment_time  - время платежа
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @author Mikhail Shapshay
     */
    public function deposit(Request $request)
    {
        $request->validate([
            'account_id' => 'required|numeric',
            'account_type' => 'required|min:4',
            'loyalty_points_rule' => 'required|min:4',
            'description' => 'required|min:2',
            'payment_id' => 'required|min:4',
            'payment_amount' => 'required',
            'payment_time' => 'required',
        ]);

        $data = $request;

        Log::info(config('loyalty_points.messages.transaction_input') . print_r($data, true));

        $type = $data['account_type'];
        $id = $data['account_id'];
        if (in_array($type, $this->account->account_type) && !empty($id)) {
            $this->account->getAccount($type, $id);
            if ($this->account) {
                if ($this->account->active) {
                    $transaction =  $this->performPaymentLoyaltyPoints($this->account->id, $data['loyalty_points_rule'], $data['description'], $data['payment_id'], $data['payment_amount'], $data['payment_time']);
                    Log::info($transaction);
                    if (!empty($this->account->email) && $this->account->email_notification) {
                        Mail::to($this->account)->send(new LoyaltyPointsReceived($transaction->points_amount, $this->account->getBalance()));
                    }
                    if (!empty($this->account->phone) && $this->account->phone_notification) {
                        // instead SMS component
                        Log::info(config('loyalty_points.messages.received') . $transaction->points_amount . config('loyalty_points.messages.balance') . $this->account->getBalance());
                    }
                    return $transaction;
                } else {
                    Log::info(config('account.messages.not_active'));
                    return response()->json(['message' => config('account.messages.not_active')], 400);
                }
            } else {
                Log::info(config('account.messages.not_found'));
                return response()->json(['message' => config('account.messages.not_found')], 400);
            }
        } else {
            Log::info(config('account.messages.parameters'));
            throw new \InvalidArgumentException(config('account.messages.parameters'));
        }
    }

    /**
     * Метод Отмены начисления баллов лояльности
     *
     * transaction_id  - ID транзакции
     * cancellation_reason  - причина отмены
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @author Mikhail Shapshay
     */
    public function cancel(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|numeric',
            'cancellation_reason' => 'required|min:2',
        ]);

        $data = $request;

        $reason = $data['cancellation_reason'];

        if ($reason == '') {
            return response()->json(['message' => config('loyalty_points.messages.cancel_reason')], 400);
        }

        if ($transaction = LoyaltyPointsTransaction::where('id', '=', $data['transaction_id'])->where('canceled', '=', 0)->first()) {
            $transaction->canceled = time();
            $transaction->cancellation_reason = $reason;
            $transaction->save();
        } else {
            return response()->json(['message' => config('loyalty_points.messages.transaction_not_found')], 400);
        }
    }

    /**
     * Метод Начисление баллов лояльности за покупку
     *
     * account_id        - значение поля из типа
     * account_type  - тип запроса (из массива $account_type)
     * description  - описание
     * points_amount  - количество баллов
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @author Mikhail Shapshay
     */
    public function withdraw(Request $request)
    {
        $request->validate([
            'account_id' => 'required|numeric',
            'account_type' => 'required|min:4',
            'points_amount' => 'required',
            'description' => 'required|min:2',
        ]);

        $data = $request;

        Log::info(config('loyalty_points.messages.withdraw_input') . print_r($data, true));

        $type = $data['account_type'];
        $id = $data['account_id'];
        if (in_array($type, $this->account->account_type) && !empty($id)) {
            $this->account->getAccount($type, $id);
            if ($this->account) {
                if ($this->account->active) {
                    if ($data['points_amount'] <= 0) {
                        Log::info(config('loyalty_points.messages.wrong_amount').': ' . $data['points_amount']);
                        return response()->json(['message' => config('loyalty_points.messages.wrong_amount')], 400);
                    }
                    if ($this->account->getBalance() < $data['points_amount']) {
                        Log::info(config('loyalty_points.messages.insufficient_funds').': ' . $data['points_amount']);
                        return response()->json(['message' => config('loyalty_points.messages.insufficient_funds')], 400);
                    }

                    $transaction = $this->withdrawLoyaltyPoints($this->account->id, $data['points_amount'], $data['description']);
                    Log::info($transaction);
                    return $transaction;
                } else {
                    Log::info(config('account.messages.not_active').': ' . $type . ' ' . $id);
                    return response()->json(['message' => config('account.messages.not_active')], 400);
                }
            } else {
                Log::info(config('account.messages.not_found').':' . $type . ' ' . $id);
                return response()->json(['message' => config('account.messages.not_found')], 400);
            }
        } else {
            Log::info(config('account.messages.parameters'));
            throw new \InvalidArgumentException(config('account.messages.parameters'));
        }
    }

    /**
     * Метод Создания транзакции
     *
     * account_id        - значение поля из типа
     * account_type  - тип запроса (из массива $account_type)
     * loyalty_points_rule  - правило начисления
     * description  - описание
     * payment_id  - ID платежа
     * payment_amount  - сумма платежа
     * payment_time  - время платежа
     *
     * @return LoyaltyPointsTransaction
     * @author Mikhail Shapshay
     */
    private static function performPaymentLoyaltyPoints($account_id, $points_rule, $description, $payment_id, $payment_amount, $payment_time)
    {
        $points_amount = 0;

        if ($pointsRule = LoyaltyPointsRule::where('points_rule', '=', $points_rule)->first()) {
            /*$points_amount = match ($pointsRule->accrual_type) {
                config('loyalty_points.accrual_type.relative') => ($payment_amount / 100) * $pointsRule->accrual_value,
                config('loyalty_points.accrual_type.absolute') => $pointsRule->accrual_value,
            };*/
        }

        return LoyaltyPointsTransaction::create([
            'account_id' => $account_id,
            'points_rule' => $pointsRule->id,
            'points_amount' => $points_amount,
            'description' => $description,
            'payment_id' => $payment_id,
            'payment_amount' => $payment_amount,
            'payment_time' => $payment_time,
        ]);
    }

    /**
     * Метод Создания транзакции покупки
     *
     * account_id        - значение поля из типа
     * description  - описание
     * payment_amount  - сумма платежа
     *
     * @return LoyaltyPointsTransaction
     * @author Mikhail Shapshay
     */
    private static function withdrawLoyaltyPoints($account_id, $points_amount, $description) {
        return LoyaltyPointsTransaction::create([
            'account_id' => $account_id,
            'points_rule' => 'withdraw',
            'points_amount' => -$points_amount,
            'description' => $description,
        ]);
    }
}

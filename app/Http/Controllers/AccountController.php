<?php

namespace App\Http\Controllers;

use App\Mail\AccountActivated;
use App\Mail\AccountDeactivated;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointsTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccountController extends Controller
{
    /**
     * @var array
     */
    public $account_type = array(
        'phone',
        'card',
        'email'
    );

    /**
     * @var LoyaltyAccount
     */
    private $account;

    /**
     * Метод Добавления аккаунта
     *
     * phone        - телефон
     * card  - номер карты
     * email      - email
     * email_notification    - признак отправки уведомлений на email
     * phone_notification    - признак отправки уведомлений на телефон
     * active      - признак активности аккаунта
     *
     * @param Request $request
     * @return LoyaltyAccount
     * @author Mikhail Shapshay
     */
    public function create(Request $request)
    {
        $request->validate([
            'phone' => 'required|min:6',
            'card' => 'required|min:12',
            'email' => 'required|email',
            'email_notification' => 'required',
            'phone_notification' => 'required',
            'active' => 'required',
        ]);
        return LoyaltyAccount::create($request->all());
    }

    /**
     * Метод Активации аккаунта
     *
     * id        - значение поля из типа
     * type  - тип запроса (из массива $account_type)
     *
     * @author Mikhail Shapshay
     */
    public function activate($type, $id)
    {
        request()->validate([
                'id' => 'required|numeric',
                'type' => 'required|min:4',
            ]
        );
        if (in_array($type, $this->account_type) && !empty($id)) {
            $this->getAccount($type, $id);
            if ($this->account) {
                if (!$this->account->active) {
                    $this->account->active = true;
                    $this->account->save();
                    $this->notify($this->account);
                }
            } else {
                return response()->json(['message' => config('account.messages.not_found')], 400);
            }
        } else {
            throw new \InvalidArgumentException(config('account.messages.parameters'));
        }

        return response()->json(['success' => true]);
    }

    /**
     * Метод Деактивации аккаунта
     *
     * id        - значение поля из типа
     * type  - тип запроса (из массива $account_type)
     *
     * @author Mikhail Shapshay
     */
    public function deactivate($type, $id)
    {
        request()->validate([
                'id' => 'required|numeric',
                'type' => 'required|min:4',
            ]
        );
        if (in_array($type, $this->account_type) && !empty($id)) {
            $this->getAccount($type, $id);
            if ($this->account) {
                if ( $this->account->active) {
                    $this->account->active = false;
                    $this->account->save();
                    $this->account->notify($this->account);
                }
            } else {
                return response()->json(['message' => config('account.messages.not_found')], 400);
            }
        } else {
            throw new \InvalidArgumentException(config('account.messages.parameters'));
        }

        return response()->json(['success' => true]);
    }

    /**
     * Метод Отправки баланса аккаунта
     *
     * id        - значение поля из типа
     * type  - тип запроса (из массива $account_type)
     *
     * @author Mikhail Shapshay
     */
    public function balance($type, $id)
    {
        request()->validate([
                'id' => 'required|numeric',
                'type' => 'required|min:4',
            ]
        );
        if (in_array($type, $this->account_type) && !empty($id)) {
            $this->getAccount($type, $id);
            if ($this->account) {
                return response()->json(['balance' => $this->getBalance()], 400);

            } else {
                return response()->json(['message' => config('account.messages.not_found')], 400);
            }
        } else {
            throw new \InvalidArgumentException(config('account.messages.parameters'));
        }
    }

    /**
     * Метод Отправки сообщения о результате
     *
     * account  - экземпляр аккаунта
     *
     * @author Mikhail Shapshay
     */
    public function notify(LoyaltyAccount $account)
    {
        if ($account->email != '' && $account->email_notification) {
            if ($account->active) {
                Mail::to($account)->send(new AccountActivated($this->getBalance()));
            } else {
                Mail::to($account)->send(new AccountDeactivated());
            }
        }

        if ($account->phone != '' && $account->phone_notification) {
            // instead SMS component
            Log::info('Account: phone: ' . $account->phone . ' ' . ($account->active ? config('account.active') : config('account.deactive')));
        }
    }

    /**
     * Метод Получения баланса аккаунта
     *
     * @author Mikhail Shapshay
     */
    public function getBalance(): float
    {
        return LoyaltyPointsTransaction::where('canceled', '=', 0)->where('account_id', '=', $this->account->id)->sum('points_amount');
    }

    /**
     * Метод Получения аккаунта
     *
     * id        - значение поля из типа
     * type  - тип запроса (из массива $account_type)
     *
     * @author Mikhail Shapshay
     */
    public function getAccount($type, $id)
    {
        request()->validate([
                'id' => 'required|numeric',
                'type' => 'required|min:4',
            ]
        );
        $this->account = LoyaltyAccount::where($type, '=', $id)->first();
    }
}

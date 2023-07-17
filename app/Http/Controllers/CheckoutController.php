<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Models\Cart;
use App\Models\Transaction;
use App\Models\TransactionDetail;

use App\Exception;

use Midtrans\Snap;
use Midtrans\Config;

class CheckoutController extends Controller
{
    public function process(Request $request)
    {
        // Save users data
        $user = Auth::user();
        $user->update($request->except('total_price'));

        //Proses Checkout
        $code = 'STORE-' . mt_rand(000000,999999);
        $carts = Cart::with(['product','user'])
                    ->where('users_id', Auth::user()->id)
                    ->get();

        //Transaction Create
        $transaction = Transaction::create([
            'users_id'=> Auth::user()->id,
            'insurance_price'=> 0,
            'shipping_price'=> 0,
            'total_price'=> $request->total_price,
            'transaction_status'=> 'PENDING',
            'code'=> $code
        ]);

        foreach($carts as $cart){
            $trx = 'TRX-' . mt_rand(000000,999999);

            TransactionDetail::create([
            'transaction_id'=> $transaction->id,
            'products_price'=> $cart->product->id,
            'price'=> $cart->product->price,
            'shipping_status'=> 'PENDING',
            'resi'=> '',
            'code'=> $trx
            ]);
        }

        //Delete Cart Data
        Cart::where('users_id', Auth::user()->id)->delete();

        //Konfigurasi Midtrans
        // Set your Merchant Server Key
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$is3ds = config('services.midtrans.is3ds');

        //Buat array untuk dikirim ke Midtrans
        $midtrans = [
            'transaction_details' => [
                'order_id' => $code,
                'gross_amount' => (int) $request -> total_price,
            ],
            'customer_details' => [
                'first_name' => Auth::user()->name,
                'email' => Auth::user()->email,
            ],
            'enabled_payments' => [
                'gopay', 'bca_va', 'permata_va', 'bank_transfer',
            ],
            'vtweb' => []
        ];

        try {
            // Get Snap Payment Page URL
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;
            
            // Redirect to Snap Payment Page
            return redirect($paymentUrl);
        }
        catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function callback(Request $request)
    {
        //Set konfigurasi Midtrans
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProduction');
        Config::$isSanitized = config('services.midtrans.isSanitized');
        Config::$id3ds = config('services.midtrans.is3ds');

        //Instance Midtrans notification
        $notification = new Notification();

        //Assign ke variable untuk memudahkan coding
        $status = $notification->transaction_status;
        $type = $notification->payment_type;
        $fraud = $notification->fraud_status;
        $order_id = $notification->order_id;

        //Cari transaksi berdasar ID
        $transaction = Transaction::findOrFail($order_id)

        //Handle notification status
        if($status == 'capture'){
            if($type == 'credit_card'){
                if($fraud == 'challenge'){
                    $transaction_status = 'PENDING';
                }
                else{
                    $transaction_status = 'SUCCESS';
                }
            }
        }

        else if($status == 'settlement'){
            $transaction_status = 'SUCCESS';
        }

        else if($status == 'pending'){
            $transaction_status = 'PENDING';
        }

        else if($status == 'deny'){
            $transaction_status = 'CANCELED';
        }

        else if($status == 'expire'){
            $transaction_status = 'CANCELED';
        }

        else if($status == 'cancel'){
            $transaction_status = 'CANCELED';
        }

        //Simpan transaksi
        $transaction = save();
    }
}

<?php

namespace Laravel\CashierConnect\Exceptions;

use Laravel\CashierConnect\Payment;

class PaymentFailure extends IncompletePayment
{
    /**
     * Create a new PaymentFailure instance.
     *
     * @param  \Laravel\Cashier\Payment  $payment
     * @return self
     */
    public static function invalidPaymentMethod(Payment $payment)
    {
        return new self(
            $payment,
            'The payment attempt failed because of an invalid payment method.'
        );
    }
}
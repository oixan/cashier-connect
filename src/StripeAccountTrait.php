<?php

namespace Laravel\CashierConnect;

use Laravel\CashierConnect\Cashier;

trait StripeAccountTrait{
    /**
     * The Stripe Connect Account.
     *
     * @var Model
     */
    protected static $stripeAccount;

    /**
     * The Stripe Connect Account Temp variable.
     *
     * @var Model
     */
    protected static $stripeAccountTemp;


    /**
     * Get the stripeAccount ID.
     *
     * @param  Model  $account
     * @return $this
     */
    public function getStripeAccount()
    {
        return static::$stripeAccount;
    }

    /**
     * Set the stripeAccount ID.
     *
     * @param  Model  $account
     * @return $this
     */
    public function setStripeAccount($account)
    {
        static::$stripeAccount = $account;

        return $this;
    }

    /**
     * Unset the stripeAccount ID.
     *
     * @param  Model  $account
     * @return $this
     */
    public function unsetStripeAccount()
    {
        static::$stripeAccount = null;

        return $this;
    }

    /**
     * Save temporaly the stripeAccount ID.
     *
     * @param  Model  $account
     * @return $this
     */
    public function pauseStripeAccount()
    {
        static::$stripeAccountTemp = static::$stripeAccount;
        $this->unsetStripeAccount();
    }

    /**
     * Restore temporaly the stripeAccount ID.
     *
     * @param  Model  $account
     * @return $this
     */
    public function resumeStripeAccount()
    {   
        $temp = static::$stripeAccountTemp;
        static::$stripeAccountTemp = null;
        return $this->setStripeAccount($temp);
    }

    /**
     * Create extra playload with STRIPE_ACCOUNT and API_KEY.
     *
     * @return array
     */
    public function buildExtraPayload(){
        return array_filter([
            "api_key" => Cashier::stripeOptions()['api_key'],
            "stripe_account" => ( $this->getStripeAccount() ? $this->getStripeAccount()->stripe_account_id: null)
        ]);
    }

}
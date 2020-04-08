<?php

namespace Laravel\CashierConnect;

use Exception;
use InvalidArgumentException;
use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Illuminate\Support\Collection;
use Stripe\Charge as StripeCharge;
use Stripe\Refund as StripeRefund;
use Stripe\Invoice as StripeInvoice;
use Stripe\Customer as StripeCustomer;
use Stripe\BankAccount as StripeBankAccount;
use Stripe\InvoiceItem as StripeInvoiceItem;
use Stripe\SetupIntent as StripeSetupIntent;
use Stripe\Error\Card as StripeCardException;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Laravel\CashierConnect\Exceptions\InvalidStripeCustomer;
use Stripe\Error\InvalidRequest as StripeErrorInvalidRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait Billable
{
    use StripeAccountTrait;

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int  $amount
     * @param  string  $paymentMethod
     * @param  array  $options
     * @return \Laravel\Cashier\Payment
     */
    public function charge($amount, $paymentMethod, array $options = [])
    {
        $options = array_merge([
            'amount' => $amount,
            'confirmation_method' => 'automatic',
            'confirm' => true,
            'currency' => $this->preferredCurrency(),
        ], $options);

        $options['payment_method'] = $paymentMethod;

        if ($this->stripe_id) {            
            $options['customer'] = $this->stripe_id;
        }

        $payment = new Payment(
            StripePaymentIntent::create( $options, Cashier::stripeOptions($this->buildExtraPayload()) )
        );

        $payment->validate();

        return $payment;    
    }

    /**
     * Refund a customer for a charge.
     *
     * @param  string  $paymentIntent
     * @param  array  $options
     * @return \Stripe\Refund
     */
    public function refund($paymentIntent, array $options = [])
    {
        $intent = StripePaymentIntent::retrieve($paymentIntent, Cashier::stripeOptions());

        return $intent->charges->data[0]->refund($options);
    }

    /**
     * Determines if the customer currently has a card on file.
     *
     * @return bool
     */
    public function hasCardOnFile()
    {
        return (bool) $this->card_brand;
    }

    /**
     * Add an invoice item to the customer's upcoming invoice.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return \Stripe\InvoiceItem
     * @throws \InvalidArgumentException
     */
    public function tab($description, $amount, array $options = [])
    {
        $this->assertCustomerExists();

        $stripe_id = $this->stripe_id;
        if ( $this->getStripeAccount() )
            $stripe_id = $this->asStripeCustomer()->id;

        $options = array_merge([
            'customer' => $stripe_id,
            'amount' => $amount,
            'currency' => $this->preferredCurrency(),
            'description' => $description,
        ], $options);

        return StripeInvoiceItem::create($options, Cashier::stripeOptions());
    }

    /**
     * Invoice the customer for the given amount and generate an invoice immediately.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $tabOptions
     * @param  array  $invoiceOptions
     * @return \Laravel\Cashier\Invoice|bool
     */
    public function invoiceFor($description, $amount, array $tabOptions = [], array $invoiceOptions = [])
    {
        $this->tab($description, $amount, $tabOptions);

        return $this->invoice($invoiceOptions);
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\CashierConnect\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan, $this->getStripeAccount());
    }

    /**
     * Determine if the Stripe model is on trial.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
               $subscription->stripe_plan === $plan;
    }

    /**
     * Determine if the Stripe model is on a "generic" trial at the model level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the Stripe model has a given subscription.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
               $subscription->stripe_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string  $subscription
     * @return \Laravel\CashierConnect\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(function ($value) {
            return $value->created_at->getTimestamp();
        })->first(function ($value) use ($subscription) {
            return $value->name === $subscription;
        });
    }

    /**
     * Get all of the subscriptions for the Stripe model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, $this->getForeignKey())->orderBy('created_at', 'desc');
    }

    /**
     * Determine if the customer's subscription has an incomplete payment.
     *
     * @param  string  $subscription
     * @return bool
     */
    public function hasIncompletePayment($subscription = 'default')
    {
        if ($subscription = $this->subscription($subscription)) {
            return $subscription->hasIncompletePayment();
        }
        return false;
    }

    /**
     * Invoice the billable entity outside of regular billing cycle.
     *
     * @param  array  $options
     * @return \Laravel\Cashier\Invoice|bool
     */
    public function invoice(array $options = [])
    {
        $this->assertCustomerExists();

        $stripe_id = $this->stripe_id;
        if ($stripe_id) {

            if ( $this->getStripeAccount() )
                $stripe_id = $this->asStripeCustomer()->id;

            $parameters = array_merge($options, ['customer' => $stripe_id]);

            try {
                /** @var \Stripe\Invoice $invoice */
                $stripeInvoice = StripeInvoice::create( $parameters, Cashier::stripeOptions($this->buildExtraPayload()) );

                $stripeInvoice = $stripeInvoice->pay();

                return new Invoice($this, $stripeInvoice);
            } catch (StripeErrorInvalidRequest $e) {
                return false;
            }catch (StripeCardException $exception) {
                    $payment = new Payment(
                        StripePaymentIntent::retrieve(
                            ['id' => $stripeInvoice->refresh()->payment_intent, 'expand' => ['invoice.subscription']],
                            Cashier::stripeOptions($this->buildExtraPayload())
                        )
                    );
        
                    $payment->validate();
            } catch (\Exception $e) {
                return false;
            } 

        }

        return true;
    }

    /**
     * Get the entity's upcoming invoice.
     *
     * @return \Laravel\CashierConnect\Invoice|null
     */
    public function upcomingInvoice()
    {
        $this->assertCustomerExists();
        
        try {
            $stripeInvoice = StripeInvoice::upcoming(['customer' => $this->stripe_id], Cashier::stripeOptions());

            return new Invoice($this, $stripeInvoice);
        } catch (StripeErrorInvalidRequest $e) {
            //
        }
    }

    /**
     * Find an invoice by ID.
     *
     * @param  string  $id
     * @return \Laravel\CashierConnect\Invoice|null
     */
    public function findInvoice($id)
    {
        try {
            $stripeInvoice = StripeInvoice::retrieve(
                $id, Cashier::stripeOptions()
            );

            $stripeInvoice->lines = StripeInvoice::retrieve($id, Cashier::stripeOptions())
                        ->lines
                        ->all(['limit' => 1000]);

            return new Invoice($this, $stripeInvoice);
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Find an invoice or throw a 404 or 403 error.
     *
     * @param  string  $id
     * @return \Laravel\CashierConnect\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException;
        }

        if ($invoice->customer !== $this->stripe_id) {
            throw new AccessDeniedHttpException;
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param  string  $id
     * @param  array  $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data)
    {
        return $this->findInvoiceOrFail($id)->download($data);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param  bool  $includePending
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $this->assertCustomerExists();

        $invoices = [];

        $parameters = array_merge(['limit' => 24], $parameters);

        $stripeInvoices = $this->asStripeCustomer()->invoices($parameters);

        // Here we will loop through the Stripe invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Stripe objects are. Then, we'll return the array.
        if (! is_null($stripeInvoices)) {
            foreach ($stripeInvoices->data as $invoice) {
                if ($invoice->paid || $includePending) {
                    $invoices[] = new Invoice($this, $invoice);
                }
            }
        }

        return new Collection($invoices);
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

     /**
     * Create a new SetupIntent instance.
     *
     * @param  array  $options
     * @return \Stripe\SetupIntent
     */
    public function createSetupIntent(array $options = [])
    {
        return StripeSetupIntent::create(
            $options, Cashier::stripeOptions($this->buildExtraPayload())
        );
    }
    /**
     * Determines if the customer currently has a payment method.
     *
     * @return bool
     */
    public function hasPaymentMethod()
    {
        return (bool) $this->card_brand;
    }

    /**
     * Get a collection of the entity's cards.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection|\Laravel\Cashier\PaymentMethod[]
     */
    public function paymentMethods($parameters = [])
    {
        $this->assertCustomerExists();
        
        $cards = [];

        $parameters = array_merge(['limit' => 24], $parameters);

        $customer = $this->asStripeCustomer();

        $paymentMethods = StripePaymentMethod::all(
            ['customer' => $customer->id, 'type' => 'card'] + $parameters,
            Cashier::stripeOptions($this->buildExtraPayload())
        );

        return collect($paymentMethods->data)->map(function ($paymentMethod) {
            return new PaymentMethod($this, $paymentMethod);
        });
    }

    /**
     * Add a payment method to the customer.
     *
     * @param  \Stripe\PaymentMethod|string  $paymentMethod
     * @return \Laravel\Cashier\PaymentMethod
     */
    public function addPaymentMethod($paymentMethod, $customer)
    {
        $this->assertCustomerExists();

        $stripePaymentMethod = $this->resolveStripePaymentMethod($paymentMethod);

        if ($stripePaymentMethod->customer !== $this->stripe_id) {
            $stripePaymentMethod = $stripePaymentMethod->attach(
                ['customer' => $customer->id], Cashier::stripeOptions($this->buildExtraPayload())
            );
        }

        return new PaymentMethod($this, $stripePaymentMethod);
    }

    /**
     * Remove a payment method from the customer.
     *
     * @param  \Stripe\PaymentMethod|string  $paymentMethod
     * @return void
     */
    public function removePaymentMethod($paymentMethod)
    {
        $this->assertCustomerExists();
        $stripePaymentMethod = $this->resolveStripePaymentMethod($paymentMethod);
        if ($stripePaymentMethod->customer === $this->stripe_id) {
            $customer = $this->asStripeCustomer();
            // If payment method was the default payment method, we'll remove it manually...
            if ($stripePaymentMethod->id === $customer->invoice_settings->default_payment_method) {
                $customer->invoice_settings = ['default_payment_method' => null];
                $customer->save(Cashier::stripeOptions());
                $this->forceFill([
                    'card_brand' => null,
                    'card_last_four' => null,
                ])->save();
            }
            $stripePaymentMethod->detach(null, Cashier::stripeOptions());
        }
    }

    /**
     * Get the default payment method for the entity.
     *
     * @return \Laravel\Cashier\PaymentMethod|\Stripe\Card|\Stripe\BankAccount|null
     */
    public function defaultPaymentMethod()
    {
        if (! $this->hasStripeId()) {
            return;
        }
        $customer = StripeCustomer::retrieve([
            'id' => $this->stripe_id,
            'expand' => [
                'invoice_settings.default_payment_method',
                'default_source',
            ],
        ], Cashier::stripeOptions());
        if ($customer->invoice_settings->default_payment_method) {
            return new PaymentMethod($this, $customer->invoice_settings->default_payment_method);
        }
        // If we can't find a payment method, try to return a legacy source...
        return $customer->default_source;
    }

    /**
     * Update customer's default payment method.
     *
     * @param  \Stripe\PaymentMethod|string  $paymentMethod
     * @return \Laravel\Cashier\PaymentMethod
     */
    public function updateDefaultPaymentMethod($_paymentMethod)
    {
        $this->assertCustomerExists();

        $this->pauseStripeAccount();

        $customer = $this->asStripeCustomer();

        $stripePaymentMethod = $this->resolveStripePaymentMethod($_paymentMethod);

        // If the customer already has the payment method as their default, we can bail out
        // of the call now. We don't need to keep adding the same payment method to this
        // model's account every single time we go through this specific process call.
        if ($stripePaymentMethod->id === $customer->invoice_settings->default_payment_method) {
            return;
        }

        $paymentMethod = $this->addPaymentMethod($stripePaymentMethod, $customer);

        $customer->invoice_settings = ['default_payment_method' => $paymentMethod->id];

        $customer->save(Cashier::stripeOptions());

        // Next we will get the default payment method for this user so we can update the
        // payment method details on the record in the database. This will allow us to
        // show that information on the front-end when updating the payment methods.
        $this->fillPaymentMethodDetails($paymentMethod);

        $this->save();

        $this->resumeStripeAccount();

        if ($this->getStripeAccount())
            $this->updateCardSharedCustomer($_paymentMethod);

        return $paymentMethod;
    }

    /**
     * Update Shared customer's credit card.
     *
     * @param  string  $customer_platform
     * @return void
     */
    public function updateCardSharedCustomer($paymentMethod)
    {
        $customer = $this->asStripeCustomer();

        if ( !$customer )
            return;

        $stripePaymentMethod = $this->resolveStripePaymentMethod($paymentMethod);

        // If the customer already has the payment method as their default, we can bail out
        // of the call now. We don't need to keep adding the same payment method to this
        // model's account every single time we go through this specific process call.
        if ($stripePaymentMethod->id === $customer->invoice_settings->default_payment_method) {
            return;
        }

        $paymentMethod = $this->addPaymentMethod($stripePaymentMethod, $customer);

        $customer->invoice_settings = ['default_payment_method' => $paymentMethod->id];

        $customer->save(Cashier::stripeOptions());

        return $paymentMethod;
    }

    /**
     * Synchronises the customer's default payment method from Stripe back into the database.
     *
     * @return $this
     */
    public function updateDefaultPaymentMethodFromStripe()
    {
        $defaultPaymentMethod = $this->defaultPaymentMethod();

        if ($defaultPaymentMethod) {
            if ($defaultPaymentMethod instanceof PaymentMethod) {
                $this->fillPaymentMethodDetails(
                    $defaultPaymentMethod->asStripePaymentMethod()
                )->save();
            } else {
                $this->fillSourceDetails($defaultPaymentMethod)->save();
            }
        } else {
            $this->forceFill([
                'card_brand' => null,
                'card_last_four' => null,
            ])->save();
        }

        return $this;
    }

    /**
     * Fills the model's properties with the payment method from Stripe.
     *
     * @param  \Laravel\Cashier\PaymentMethod|\Stripe\PaymentMethod|null  $paymentMethod
     * @return $this
     */
    protected function fillPaymentMethodDetails($paymentMethod)
    {
        if ($paymentMethod->type === 'card') {
            $this->card_brand = $paymentMethod->card->brand;
            $this->card_last_four = $paymentMethod->card->last4;
        }

        return $this;
    }

    /**
     * Fills the model's properties with the source from Stripe.
     *
     * @param  \Stripe\Card|\Stripe\BankAccount|null  $source
     * @return $this
     *
     * @deprecated Will be removed in a future Cashier update. You should use the new payment methods API instead.
     */
    protected function fillSourceDetails($source)
    {
        if ($source instanceof StripeCard) {
            $this->card_brand = $source->brand;
            $this->card_last_four = $source->last4;
        } elseif ($source instanceof StripeBankAccount) {
            $this->card_brand = 'Bank Account';
            $this->card_last_four = $source->last4;
        }

        return $this;
    }

    /**
     * Deletes the entity's payment methods.
     *
     * @return void
     */
    public function deletePaymentMethods()
    {
        $this->paymentMethods()->each(function (PaymentMethod $paymentMethod) {
            $paymentMethod->delete();
        });

        $this->updateDefaultPaymentMethodFromStripe();
    }

        /**
     * Find a PaymentMethod by ID.
     *
     * @param  string  $paymentMethod
     * @return \Laravel\Cashier\PaymentMethod|null
     */
    public function findPaymentMethod($paymentMethod)
    {
        $stripePaymentMethod = null;

        try {
            $stripePaymentMethod = $this->resolveStripePaymentMethod($paymentMethod);
        } catch (Exception $exception) {
            //
        }

        return $stripePaymentMethod ? new PaymentMethod($this, $stripePaymentMethod) : null;
    }

    /**
     * Resolve a PaymentMethod ID to a Stripe PaymentMethod object.
     *
     * @param  \Stripe\PaymentMethod|string  $paymentMethod
     * @return \Stripe\PaymentMethod
     */
    protected function resolveStripePaymentMethod($paymentMethod)
    {
        if ($paymentMethod instanceof StripePaymentMethod) {
            return $paymentMethod;
        }

        return StripePaymentMethod::retrieve(
            $paymentMethod, Cashier::stripeOptions($this->buildExtraPayload())
        );
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param  string  $coupon
     * @return void
     */
    public function applyCoupon($coupon)
    {
        $this->assertCustomerExists();

        $customer = $this->asStripeCustomer();

        $customer->coupon = $coupon;

        $customer->save();
    }

    /**
     * Determine if the Stripe model is actively subscribed to one of the given plans.
     *
     * @param  array|string  $plans
     * @param  string  $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $plans as $plan) {
            if ($subscription->stripe_plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return ! is_null($this->subscriptions->first(function ($value) use ($plan) {
            return $value->stripe_plan === $plan && $value->valid();
        }));
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasStripeId()
    {
        return ! is_null($this->stripe_id);
    }

    /**
     * Determine if the entity has a Stripe customer ID and throw an exception if not.
     *
     * @return void
     *
     * @throws \Laravel\Cashier\Exceptions\InvalidStripeCustomer
     */
    protected function assertCustomerExists()
    {
        if (! $this->stripe_id) {
            throw InvalidStripeCustomer::nonCustomer($this);
        }
    }

    /**
     * Create a Stripe customer for the given model.
     *
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function createAsStripeCustomer(array $options = [])
    {
        $options = array_key_exists('email', $options)
                ? $options
                : array_merge($options, ['email' => $this->email]);

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $this->pauseStripeAccount();
        $customer = StripeCustomer::create( $options, Cashier::stripeOptions($this->buildExtraPayload()) );   
        $this->resumeStripeAccount();

        $this->stripe_id = $customer->id;

        $this->save();

        return $customer;
    }

    /**
     * Create a Stripe Shared customer for the given Stripe model.
     *
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function createAsStripeSharedCustomer()
    {
        $this->pauseStripeAccount();
        $customerTemp = $this->asStripeCustomer();
        $paymentMethodDefault = $customerTemp->invoice_settings['default_payment_method'];
        $this->resumeStripeAccount();
        /*
        $token = StripeToken::create([
            "customer" => $this->stripe_id,
        ], $this->buildExtraPayload());
        $customer = StripeCustomer::create(['source' => $token->id], $this->buildExtraPayload());
        */
        $customerShared = \Stripe\Customer::create([
            'email' => $customerTemp->email
          ], $this->buildExtraPayload());

        $payment_method = \Stripe\PaymentMethod::create([
            'customer' => $this->stripe_id,
            'payment_method' => $paymentMethodDefault
          ], $this->buildExtraPayload());

        $payment_method->attach([
            'customer' => $customerShared->id
          ]);

        $payment_method->save();

        $customerShared->invoice_settings = ['default_payment_method' => $payment_method->id];
        
        $customerShared->save();

        return $customerShared;
    }

    /**
     * Update the underlying Stripe customer information for the model.
     *
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function updateStripeCustomer(array $options = [])
    {
        return StripeCustomer::update(
            $this->stripe_id, $options, Cashier::stripeOptions()
        );
    }

    /**
     * Get the Stripe customer instance for the current user or create one.
     *
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function createOrGetStripeCustomer(array $options = [])
    {
        if ($this->stripe_id) {
            return $this->asStripeCustomer();
        }

        return $this->createAsStripeCustomer($options);
    }

    /**
     * Get the Stripe customer for the model.
     *
     * @return \Stripe\Customer
     */
    public function asStripeCustomer()
    {
        $customer = null;
        if ( static::getStripeAccount() ){
            $customers = StripeCustomer::all(["limit" => 1, "email" => $this->email], $this->buildExtraPayload())->data;
            if ( count($customers) > 0 )
                return StripeCustomer::retrieve($customers[0]->id, $this->buildExtraPayload());

            $customer = StripeCustomer::retrieve($this->stripe_id, Cashier::stripeOptions() );
            if ( $customer )
                $customer = $this->createAsStripeSharedCustomer();
        }else{
            $customer = StripeCustomer::retrieve($this->stripe_id, $this->buildExtraPayload() );
        }
     
        return $customer;
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    public function preferredCurrency()
    {
        return config('cashier.currency');
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int|float
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Get the application Fee percentage to apply to the subscription.
     *
     * @return int|float
     */
    public function applicationFeePercent()
    {
        return 0;
    }

}

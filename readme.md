
## Introduction

Laravel Cashier Connect add support for Stripe Connect Subscription to laravel Cashier (based on v9.2). 

# Documentation

1) Install the package
``` 
composer require "oixan/cashier-connect"
```

2) Follow the instructions of cashier in the [official guide](https://laravel.com/docs/5.8/billing#configuration "Instructions")

3) Add fieid in 'users' table

```php
Schema::table('users', function ($table) {
    $table->string('stripe_account_id')->nullable();
});
```

4) Add the Trait to User model 
 ```use Billable```  from ```use Laravel\CashierConnect\Billable```

## Create your .env file

You will need to set the Stripe **Testing** Secret env variable before your `vendor/bin/phpunit` call in order to run the Cashier Connect tests:

    STRIPE_SECRET=<your Stripe secret>

Please note that due to the fact that actual API requests against Stripe are being made, these tests take a few minutes to run.

## How this package work.

All the default cashier methods remain the same if not direct support by CashierConnect.

This package assume you created yours Stripe Accounts directly or through API then assign the Account id to **user->stripe_account_id**.

Every user can be a customers or an Stripe Account.

## Basic Method Added to User model

```
$user = user from db or wathever;
$user->getStripeAccount(); // Check if every cashier call will go to Stripe Account
$user->setStripeAccount($userAccount); // Pass a User instance then every cashier call will go to Stripe Account
$user->unsetStripeAccount; // Remove the userAccount, cashier will work with default setting.
$user->pauseStripeAccount // Pause the userAccount, cashier will work with default setting.
$user->resumeStripeAccount  // Resume the previous userAccount, cashier call will go to Stripe Account
```

*** IMPORTANT *** any further call after setStripeAccount() will be direct to Stripe Account so remember to call unsetStripeAccount() if needed.

## Methods Supperted (Version 0.1.0).

The ``` setStripeAccount($userAccount) ``` method make every subsequent call goes from your Platform to Stripe Account passed like parameter. If you need to change the userAccount or pause the StripeAccount call see the previous section.

Creating Subscriptions
 ```php
    $user->setStripeAccount($userAccount)->newSubscription('main', 'premium')->create($token);
 ```
 Add user detail
  ```php
 $user->newSubscription('main', 'monthly')->create($token, [
    'email' => $email,
]);
 ```
 
 Checking Subscription Status
 ```php
 if ($user->subscribed('main')) {
    //
}
 ```
 
 Subscribe to plan
  ```php
 if ($user->subscribedToPlan('monthly', 'main')) {
    //
}
```

Reccuring check
  ```php
if ($user->subscription('main')->recurring()) {
    //
}
```

Cancelled Subscription Status

  ```php
if ($user->subscription('main')->cancelled()) {
    //
}

if ($user->subscription('main')->onGracePeriod()) {
    //
}

if ($user->subscription('main')->ended()) {
    //
}
```

Changing Plans
 ```php
$user->subscription('main')->swap('provider-plan-id');
```

Subscription Quantity
 ```php
$user->subscription('main')->incrementQuantity();

// Add five to the subscription's current quantity...
$user->subscription('main')->incrementQuantity(5);

$user->subscription('main')->decrementQuantity();

// Subtract five to the subscription's current quantity...
$user->subscription('main')->decrementQuantity(5);
```

Subscription Ancor Date
```php
$user->newSubscription('main', 'premium')
     ->anchorBillingCycleOn( Carbon::parse('first day of next month'))
     ->create($token);
```

Subscription Trials
```php
$user->newSubscription('main', 'monthly')
            ->trialDays(10)
            ->create($token);

$user->newSubscription('main', 'monthly')
        ->trialUntil(Carbon::now()->addDays(10))
        ->create($token);

$user->onTrial('main')

$user->subscription('main')->onTrial()
```

Cancelling Subscriptions
```php
$user->subscription('main')->cancel();

if ($user->subscription('main')->onGracePeriod()) {
    //
}

$user->subscription('main')->cancelNow();
```

Resume Subscriptions
```php
$user->subscription('main')->resume();
```

**\*All cashier methods not in list are not tested to work with Stripe Account**

## License

Laravel Cashier Connect is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

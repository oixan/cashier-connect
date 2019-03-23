
## Introduction

Laravel Cashier Connect add support for Stripe Connect Subscription to laravle Cashier. 

## Documentation

...

## Running Cashier's Tests

You will need to set the Stripe **Testing** Secret env variable before your `vendor/bin/phpunit` call in order to run the Cashier Connect tests:

    STRIPE_SECRET=<your Stripe secret> vendor/bin/phpunit

Please note that due to the fact that actual API requests against Stripe are being made, these tests take a few minutes to run.


## License

Laravel Cashier Connect is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

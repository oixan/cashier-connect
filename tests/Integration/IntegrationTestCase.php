<?php

namespace Laravel\CashierConnect\Tests\Integration;

use Dotenv\Dotenv;
use Stripe\Stripe;
use Stripe\ApiResource;
use Stripe\Error\InvalidRequest;
use Illuminate\Support\Facades\DB;
use Laravel\CashierConnect\Tests\TestCase;
use Laravel\CashierConnect\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class IntegrationTestCase extends TestCase
{
    /**
     * @var string
     */
    protected static $stripePrefix = 'cashier-test-';

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        $dotenv = Dotenv::create(__DIR__ . "/../..");
        $dotenv->load();

        Stripe::setApiKey(getenv('STRIPE_SECRET'));
    }

    public function setUp(): void
    {
        parent::setUp();

        Eloquent::unguard();

        $this->loadLaravelMigrations();

        $this->artisan('migrate')->run();

        //dd(DB::connection()->getConfig(null));
    }

    protected static function deleteStripeResource(ApiResource $resource)
    {
        try {
            $resource->delete();
        } catch (InvalidRequest $e) {
            //
        }
    }

    protected function createCustomer($description = 'taylor'): User
    {
        return User::create([
            'email' => "{$description}@cashier-test.com",
            'name' => 'Taylor Otwell',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);
    }

    protected function createCustomer2($description = 'taylor2', $stripe_account_id = ''): User
    {
        return User::create([
            'email' => "{$description}@cashier-test.com",
            'name' => 'Taylor Otwell Stripe Account',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'stripe_account_id' => $stripe_account_id
        ]);
    }
}
<?php

namespace Laravel\CashierConnect\Tests\Integration;

use DateTime;
use Stripe\Plan;
use Stripe\Token;
use Carbon\Carbon;
use Stripe\Coupon;
use Stripe\Stripe;
use Stripe\Product;
use Stripe\ApiResource;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Laravel\CashierConnect\Billable;
use PHPUnit\Framework\TestCase;
use Stripe\Error\InvalidRequest;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Laravel\CashierConnect\Http\Controllers\WebhookController;

class CashierConnectTest extends TestCase
{
    public function test1()
    {
        $this->assertTrue(true);
    }
}
<?php

namespace Laravel\CashierConnect\Tests\Fixtures;

use Laravel\CashierConnect\Billable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Model;

class User extends Model
{
    use Billable, Notifiable;
}
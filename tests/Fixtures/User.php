<?php

namespace Laravel\CashierConnect\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use Illuminate\Notifications\Notifiable;
use Laravel\CashierConnect\Billable;

class User extends Model
{
    use Billable, Notifiable;
}
<?php

namespace Laravel\CashierConnect\Http\Middleware;

use Closure;
use Stripe\WebhookSignature;
use Stripe\Error\SignatureVerification;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as Config;

class VerifyWebhookSignature
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The configuration repository instance.
     *
     * @var \Illuminate\Contracts\Config\Repository
     */
    protected $config;

    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Contracts\Config\Repository  $config
     * @return void
     */
    public function __construct(Application $app, Config $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Illuminate\Http\Response
     */
    public function handle($request, Closure $next)
    {
        $abort_stripe = false;
        $abort_stripe_connect = false;
        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                $this->config->get('cashier.webhook.secret'),
                $this->config->get('cashier.webhook.tolerance')
            );
        } catch (SignatureVerification $exception) {
            $abort_stripe = true;
        }

        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                $this->config->get('cashier.webhook.connect_secret'),
                $this->config->get('cashier.webhook.tolerance')
            );
        } catch (SignatureVerification $exception) {
            $abort_stripe_connect = true;
        }

        if ($abort_stripe && $abort_stripe_connect)
            $this->app->abort(403);

        return $next($request);
    }
}

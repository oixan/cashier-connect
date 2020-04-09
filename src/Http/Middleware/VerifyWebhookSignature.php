<?php

namespace Laravel\CashierConnect\Http\Middleware;

use Closure;
use Stripe\WebhookSignature;
use Stripe\Error\SignatureVerification;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Config\Repository as Config;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VerifyWebhookSignature
{
    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Illuminate\Http\Response
     * 
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function handle($request, Closure $next)
    {
        $abort_stripe = false;
        $abort_stripe_connect = false;
        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('cashier.webhook.secret'),
                config('cashier.webhook.tolerance')
            );
        } catch (SignatureVerificationException $exception) {
            $abort_stripe = true;
        }

        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                config('cashier.webhook.connect_secret'),
                config('cashier.webhook.tolerance')
            );
        } catch (SignatureVerificationException $exception) {
            $abort_stripe_connect = true;
        }

        if ($abort_stripe && $abort_stripe_connect)
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);

        return $next($request);
    }
}

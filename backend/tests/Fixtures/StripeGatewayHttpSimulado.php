<?php

namespace Tests\Fixtures;

use Clinica\business\services\pagos\ConfiguracionStripe;
use Clinica\business\services\pagos\StripeGateway;
use Stripe\HttpClient\CurlClient;

class StripeGatewayHttpSimulado extends StripeGateway
{
    public function __construct(ConfiguracionStripe $configuracion, public StripeHttpSimulado $http)
    {
        parent::__construct($configuracion);
    }

    protected function transporte(): CurlClient
    {
        return $this->http;
    }
}

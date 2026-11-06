<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Stripe\HttpClient\CurlClient;

class StripeHttpSimulado extends CurlClient
{
    public array $respuestas = [];

    public array $peticiones = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        if (DB::transactionLevel() !== 0 || $this->respuestas === []) {
            throw new \LogicException('Petición SDK no simulada o dentro de una transacción.');
        }
        $this->peticiones[] = compact('method', 'absUrl', 'headers', 'params', 'maxNetworkRetries');

        return [json_encode(array_shift($this->respuestas), JSON_THROW_ON_ERROR), 200, []];
    }
}

<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Clinica\business\services\pagos\PayPalPagoService;
use Clinica\business\services\pagos\StripePagoService;
use Clinica\data\models\ObligacionPago;
use Clinica\presentation\dtos\citas\DisponibilidadResource;
use Clinica\presentation\dtos\pagos\ObligacionPagoResource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/** Pruebas puras de presentación: sin arrancar Laravel, SQL, red ni migraciones. */
class PagosCapacidadesPresentacionTest extends TestCase
{
    private ?Container $anterior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anterior = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['pagos' => ['incorporacion_habilitada' => false]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->anterior);
        parent::tearDown();
    }

    private function request(string $rol, string $metodo = 'turnos'): Request
    {
        $r = Request::create('/api/reservas/disponibilidad');
        $r->setUserResolver(fn () => (object) ['rol' => (object) ['nombre' => $rol], 'getKey' => '1']);
        $r->setRouteResolver(fn () => new class($metodo)
        {
            public function __construct(private string $metodo) {}

            public function getActionMethod(): string { return $this->metodo; }
        });

        return $r;
    }

    public function test_reserva_expone_incorporacion_y_monto_solo_para_personal(): void
    {
        foreach ([false, true] as $habilitada) {
            config(['pagos.incorporacion_habilitada' => $habilitada]);
            foreach (['Administrador', 'Recepcionista', 'Paciente'] as $rol) {
                $v = (new DisponibilidadResource(['turnos' => []]))->toArray($this->request($rol));
                self::assertSame($habilitada, $v['incorporacion_financiera_habilitada']);
                self::assertSame($habilitada && $rol !== 'Paciente', $v['requiere_monto_bob']);
            }
        }
    }

    public function test_reprogramacion_no_adquiere_requisito_de_monto(): void
    {
        config(['pagos.incorporacion_habilitada' => true]);
        $v = (new DisponibilidadResource(['turnos' => []]))->toArray($this->request('Administrador', 'reprogramacion'));
        self::assertArrayNotHasKey('requiere_monto_bob', $v);
    }

    public function test_obligacion_expone_cada_elegibilidad_sin_habilitar_proveedores(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$stripe, $paypal]) {
            foreach (['stripe' => [StripePagoService::class, $stripe], 'paypal' => [PayPalPagoService::class, $paypal]] as $p => [$class, $puede]) {
                $service = $this->createMock($class);
                $service->expects(self::once())->method('elegibilidad')->willReturn(['puede_iniciar_pago' => $puede, 'revision_pago' => $puede ? $p.'-revision' : null]);
                Container::getInstance()->instance($class, $service);
            }
            $request = $this->request('Paciente');
            $request->setUserResolver(fn () => new class
            {
                public object $rol;
                public function __construct() { $this->rol = (object) ['nombre' => 'Paciente']; }
                public function getKey(): string { return '1'; }
            });
            $v = (new ObligacionPagoResource($this->obligacion()))->toArray($request);
            self::assertSame(array_values(array_filter(['stripe', 'paypal'], fn ($p) => $p === 'stripe' ? $stripe : $paypal)), $v['proveedores_disponibles']);
            self::assertSame($stripe || $paypal, $v['puede_iniciar_pago']);
            self::assertSame($stripe ? 'stripe-revision' : ($paypal ? 'paypal-revision' : null), $v['revision_pago']);
        }
    }

    public function test_administrador_no_recibe_proveedores_ni_revision(): void
    {
        $v = (new ObligacionPagoResource($this->obligacion()))->toArray($this->request('Administrador'));
        self::assertSame([], $v['proveedores_disponibles']);
        self::assertFalse($v['puede_iniciar_pago']);
        self::assertNull($v['revision_pago']);
    }

    private function obligacion(): object
    {
        $datos = new class
        {
            public mixed $calculado_at;
            public mixed $vencimiento_at;
            public mixed $emitida_en;
            public object $cita;
            public bool $liquidada = false;
            public bool $exigible = true;
            public int $centavos_bob = 7000;
            public int $centavos_usd = 1000;
            public int $tasa_numerador = 7;
            public int $tasa_denominador = 1;
            public function __construct()
            {
                $this->calculado_at = $this->vencimiento_at = $this->emitida_en = CarbonImmutable::parse('2026-11-06T12:00:00Z');
                $this->cita = new class($this->calculado_at)
                {
                    public string $codigo_cita = 'CITA-FICTICIA';
                    public string $estado = 'Programada';
                    public function __construct(public mixed $inicio_at) {}
                    public function getKey(): string { return '9'; }
                };
            }
            public function getKey(): string { return '11111111-1111-4111-8111-111111111111'; }
            public function relationLoaded(string $name): bool { return false; }
        };
        $modelo = $this->getMockBuilder(ObligacionPago::class)->disableOriginalConstructor()
            ->onlyMethods(['getKey', 'getAttribute', 'relationLoaded'])->getMock();
        $modelo->expects(self::atLeastOnce())->method('getKey')->willReturn($datos->getKey());
        $modelo->expects(self::atLeastOnce())->method('getAttribute')->willReturnCallback(fn ($key) => $datos->{$key} ?? null);
        $modelo->expects(self::atLeastOnce())->method('relationLoaded')->willReturn(false);

        return $modelo;
    }
}

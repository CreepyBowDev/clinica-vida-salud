<?php

namespace Clinica\data\repositories\usuarios;

use Clinica\data\models\TokenAcceso;
use Clinica\data\models\Usuario;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class SesionRepository
{
    public function __construct(private AuthManager $auth, private SessionManager $sessions) {}

    private function guard(): SessionGuard
    {
        return $this->auth->guard('web');
    }

    private function store(): Store
    {
        return $this->sessions->driver();
    }

    public function usuarioId(): int|string|null
    {
        return $this->guard()->id();
    }

    private function contexto(Usuario $usuario, bool $normal = true): array
    {
        return [
            'rol' => (string) $usuario->id_rol,
            'paciente' => $usuario->rol->nombre === 'Paciente' ? (string) $usuario->paciente?->getKey() : null,
            'medico' => $usuario->rol->nombre === 'Médico' ? (string) $usuario->medico?->getKey() : null,
            'normal' => $normal,
        ];
    }

    public function coincide(Usuario $usuario): bool
    {
        $huella = $this->store()->get('password_hash_web');

        return is_string($huella)
            && hash_equals($this->guard()->hashPasswordForCookie($usuario->getAuthPassword()), $huella)
            && $this->store()->get('contexto_autenticacion') === $this->contexto($usuario, ! $usuario->cambio_clave_obligatorio);
    }

    public function credencialTemporalId(): int|string|null
    {
        $id = $this->store()->get('credencial_temporal_id');

        return is_int($id) || is_string($id) ? $id : null;
    }

    public function vigente(Usuario $usuario): bool
    {
        return DB::table(config('session.table'))->where('id', $this->store()->getId())
            ->where('user_id', $usuario->getKey())
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->timestamp)
            ->exists();
    }

    public function actualizarActor(Usuario $usuario): void
    {
        $this->guard()->setUser($usuario);
    }

    public function iniciar(Usuario $usuario, ?TokenAcceso $credencial = null): void
    {
        if (config('session.driver') !== 'database'
            || ! config('session.encrypt')
            || ! $this->store()->getHandler() instanceof DatabaseSessionHandler
            || DB::connection(config('session.connection')) !== DB::connection()) {
            throw new RuntimeException('La autenticación requiere sesiones database cifradas en la conexión transaccional.');
        }
        // El guard instalado regenera ID/CSRF e inicializa password_hash_web como HMAC.
        $this->guard()->login($usuario, false);
        $this->store()->put('contexto_autenticacion', $this->contexto($usuario, $credencial === null));
        $this->store()->forget('credencial_temporal_id');
        if ($credencial !== null) {
            $this->store()->put('credencial_temporal_id', (string) $credencial->getKey());
        }
        $this->store()->save();
        if (! DB::table(config('session.table'))->where('id', $this->store()->getId())
            ->where('user_id', $usuario->getKey())->exists()) {
            throw new RuntimeException('No se confirmó la persistencia de la sesión.');
        }
    }

    public function cerrar(): void
    {
        $id = $this->store()->getId();
        $this->guard()->logoutCurrentDevice();
        $this->store()->invalidate();
        $this->auth->guard('sanctum')->forgetUser();
        $this->store()->regenerateToken();
        if (DB::table(config('session.table'))->where('id', $id)->exists()) {
            throw new RuntimeException('No se confirmó la invalidación de la sesión.');
        }
    }

    public function limpiarTrasFallo(): void
    {
        // Limpiar memoria sin confirmar escrituras fuera de la transacción fallida.
        $this->guard()->forgetUser();
        $this->store()->flush();
        // auth:sanctum conserva su propio actor en memoria, además del guard web.
        $this->auth->guard('sanctum')->forgetUser();
        $this->store()->regenerateToken();
    }

    public function revocarUsuario(int|string $id, ?string $excepto = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La revocación por usuario requiere una transacción coordinada con su cuenta.');
        }
        $query = DB::table(config('session.table'))->where('user_id', $id);
        if ($excepto !== null) {
            $query->where('id', '<>', $excepto);
        }
        $query->delete();
    }
}

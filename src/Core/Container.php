<?php

declare(strict_types=1);

namespace ImaginaPay\Core;

use ImaginaPay\Exceptions\ContainerException;

/**
 * Contenedor de inyección de dependencias ligero.
 *
 * Registra factorías por identificador de clase/interface y resuelve
 * instancias bajo demanda. Sin autowiring por reflexión: todo servicio
 * se registra explícitamente en Plugin::registerServices().
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * Registra una factoría que produce una instancia nueva en cada resolución.
     *
     * @param callable(self): object $factory
     */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = false;
        unset($this->instances[$id]);
    }

    /**
     * Registra una factoría cuya instancia se comparte (singleton).
     *
     * @param callable(self): object $factory
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = true;
        unset($this->instances[$id]);
    }

    /**
     * Registra una instancia ya construida.
     */
    public function instance(string $id, object $value): void
    {
        $this->instances[$id] = $value;
        $this->shared[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }

    /**
     * Resuelve un servicio por su identificador.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     * @throws ContainerException Si el servicio no está registrado o el tipo no coincide.
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            $existing = $this->instances[$id];

            if (!$existing instanceof $id) {
                throw new ContainerException(sprintf('El servicio "%s" no es una instancia del tipo esperado.', $id));
            }

            return $existing;
        }

        if (!isset($this->factories[$id])) {
            throw new ContainerException(sprintf('Servicio no registrado en el contenedor: "%s".', $id));
        }

        $object = ($this->factories[$id])($this);

        if (!$object instanceof $id) {
            throw new ContainerException(sprintf('La factoría de "%s" devolvió un tipo incompatible.', $id));
        }

        if ($this->shared[$id]) {
            $this->instances[$id] = $object;
        }

        return $object;
    }
}

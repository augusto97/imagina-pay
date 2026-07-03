<?php

declare(strict_types=1);

namespace ImaginaPay\Support;

/**
 * Valor monetario inmutable en unidad mínima (centavos).
 *
 * Regla innegociable: dinero SIEMPRE en enteros. COP se normaliza
 * igualmente a centavos (pesos * 100) aunque no use decimales.
 */
final class Money implements \JsonSerializable
{
    private const MINOR_UNITS = 100;

    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

    public static function of(int $amount, string $currency): self
    {
        $currency = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException(sprintf('Moneda inválida: "%s". Se espera un código ISO de 3 letras.', $currency));
        }

        if ($amount < 0) {
            throw new \InvalidArgumentException('El monto no puede ser negativo.');
        }

        return new self($amount, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->amount > $this->amount) {
            throw new \InvalidArgumentException('El resultado de la resta no puede ser negativo.');
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException('El factor de multiplicación no puede ser negativo.');
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    /**
     * Formato es-CO: "$ 49.900 COP" (COP omite decimales en cero), "$ 12,99 USD".
     */
    public function format(): string
    {
        $units = intdiv($this->amount, self::MINOR_UNITS);
        $cents = $this->amount % self::MINOR_UNITS;
        $formattedUnits = number_format($units, 0, ',', '.');

        if ($this->currency === 'COP' && $cents === 0) {
            return sprintf('$ %s COP', $formattedUnits);
        }

        return sprintf('$ %s,%02d %s', $formattedUnits, $cents, $this->currency);
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(sprintf(
                'No se pueden operar montos de monedas distintas (%s vs %s).',
                $this->currency,
                $other->currency,
            ));
        }
    }
}

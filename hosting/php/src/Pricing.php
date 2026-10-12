<?php
declare(strict_types=1);
namespace MotorWorld\Hosting;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

final class Pricing
{
    public static function calculate(mixed $cost, array $settings, mixed $override = null): array
    {
        $cost = self::amount($cost, 'invalid_cost', 'Il costo IVA esclusa deve essere finito e non negativo.');
        if ($cost->compare(DecimalValue::of('0')) < 0) {
            throw new AppError('invalid_cost', 'Il costo IVA esclusa deve essere finito e non negativo.');
        }
        if ($override !== null) {
            $override = self::amount($override, 'invalid_price', 'Il prezzo scelto deve essere positivo e finito.');
            if ($override->compare(DecimalValue::of('0')) <= 0) {
                throw new AppError('invalid_price', 'Il prezzo scelto deve essere positivo e finito.');
            }
        }
        $one = DecimalValue::of('1');
        $target = DecimalValue::of('.20');
        $vat = $one->add(DecimalValue::of($settings['vat_rate'] ?? '.22'));
        $rate = DecimalValue::of($settings['commission_rate']);
        $denominator = $one->sub($rate->mul($vat));
        if ($denominator->compare(DecimalValue::of('0')) <= 0) {
            throw new AppError('impossible_commission', 'Commissioni e IVA impediscono un prezzo calcolabile: riduci la commissione.');
        }
        $shipping = DecimalValue::of($settings['shipping_cost']);
        $fixed = DecimalValue::of($settings['commission_fixed'] ?? '0');
        $raw = $cost->mul($one->add(DecimalValue::of($settings['product_markup'])))
            ->add($shipping->mul($one->add(DecimalValue::of($settings['shipping_markup']))))
            ->add($fixed)->div($denominator)->mul($vat);
        $final = self::ending($override ?? $raw);
        $net = $final->div($vat);
        $fee = $final->mul($rate)->add($fixed);
        $profit = $net->sub($cost)->sub($shipping)->sub($fee);
        $margin = $profit->div($net);
        $minimum = null;
        $diagnostics = [];
        if ($denominator->compare($target) > 0) {
            $theoretical = $cost->add($shipping)->add($fixed)->mul($vat)->div($denominator->sub($target));
            $minimum = self::ending($theoretical);
            if ($minimum->compare($theoretical) < 0) {
                $minimum = $minimum->add($one);
            }
            $minNet = $minimum->div($vat);
            if ($minNet->sub($cost)->sub($shipping)->sub($minimum->mul($rate))->sub($fixed)->div($minNet)->compare($target) < 0) {
                $minimum = $minimum->add($one);
            }
        } else {
            $diagnostics[] = Support::diagnostic('warning', 'minimum_unreachable', 'Il margine del 20% è irraggiungibile con questi parametri.', ['hint' => 'Riduci commissioni o costi.']);
        }
        $below = $margin->compare($target) < 0;
        if ($below) {
            $diagnostics[] = Support::diagnostic('warning', 'margin_below_20', 'Margine sotto 20% dopo la terminazione ,90.', ['hint' => 'Valuta il prezzo minimo suggerito. Il prezzo non è aumentato automaticamente.']);
        }
        return ['calculated_gross' => (string) $raw, 'final_gross' => (string) $final, 'net_revenue' => (string) $net,
            'commission' => (string) $fee, 'profit' => (string) $profit, 'margin' => (string) $margin,
            'minimum_gross' => $minimum === null ? null : (string) $minimum, 'below_minimum' => $below, 'diagnostics' => $diagnostics];
    }

    public static function endIn90(mixed $value): string
    {
        $value = self::amount($value, 'invalid_price', 'Il prezzo deve essere un importo finito non negativo.');
        if ($value->compare(DecimalValue::of('0')) < 0) {
            throw new AppError('invalid_price', 'Il prezzo deve essere un importo finito non negativo.');
        }
        return (string) self::ending($value);
    }

    public static function monetary(mixed $value, ?int $scale = null): string
    {
        $decimal = DecimalValue::of($value);
        $formatted = (string) $decimal->number()->toScale($scale ?? max(2, -$decimal->exponent), RoundingMode::HALF_EVEN);
        return $decimal->number()->isZero() && str_starts_with(trim((string) $value), '-') ? '-' . $formatted : $formatted;
    }

    public static function multiply(mixed $left, mixed $right): string
    {
        return (string) DecimalValue::of($left)->mul(DecimalValue::of($right));
    }

    private static function amount(mixed $value, string $code, string $message): DecimalValue
    {
        try {
            return DecimalValue::of($value);
        } catch (\InvalidArgumentException $error) {
            throw new AppError($code, $message);
        }
    }

    private static function ending(DecimalValue $value): DecimalValue
    {
        $integral = $value->exponent >= 0 ? $value : DecimalValue::of((string) $value->number()->toScale(0, RoundingMode::FLOOR));
        return $integral->add(DecimalValue::of('.90'));
    }
}

/** Python Decimal's coefficient/exponent representation, with 28 digit context.
 * Brick performs integer/decimal arithmetic; this adapter retains negative scales
 * and rounds each operation, including intermediate ones, with ROUND_HALF_EVEN.
 */
final class DecimalValue
{
    private const PRECISION = 28;

    private function __construct(public readonly BigInteger $coefficient, public readonly int $exponent) {}

    public static function of(mixed $value): self
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('Decimals must be strings or integers.');
        }
        $value = trim((string) $value);
        if (!preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D', $value, $parts)) {
            throw new \InvalidArgumentException('Invalid finite decimal.');
        }
        $fraction = ($parts[4] ?? '') !== '' ? $parts[4] : ($parts[3] ?? '');
        $exp = (int) ($parts[5] ?? 0) - strlen($fraction);
        // Avoid inputs whose expanded representation could exhaust hosting memory.
        if (abs($exp) > 10000 || strlen($value) > 20000) {
            throw new \InvalidArgumentException('Decimal exponent or precision is too large.');
        }
        $digits = ltrim(($parts[2] ?? '') . $fraction, '0');
        $digits = $digits === '' ? '0' : $digits;
        return new self(BigInteger::of(($parts[1] === '-' ? '-' : '') . $digits), $exp);
    }

    public function number(): BigDecimal
    {
        return $this->exponent < 0 ? BigDecimal::ofUnscaledValue($this->coefficient, -$this->exponent)
            : BigDecimal::of((string) $this->coefficient . 'E+' . $this->exponent);
    }

    public function compare(self $other): int { return $this->number()->compareTo($other->number()); }

    public function add(self $other): self
    {
        $exp = min($this->exponent, $other->exponent);
        $a = $this->coefficient->multipliedBy(self::power($this->exponent - $exp));
        $b = $other->coefficient->multipliedBy(self::power($other->exponent - $exp));
        return self::rounded($a->plus($b), $exp);
    }

    public function sub(self $other): self
    {
        return $this->add(new self($other->coefficient->negated(), $other->exponent));
    }

    public function mul(self $other): self
    {
        return self::rounded($this->coefficient->multipliedBy($other->coefficient), $this->exponent + $other->exponent);
    }

    public function div(self $other): self
    {
        if ($other->coefficient->isZero()) {
            throw new \DivisionByZeroError('Decimal division by zero.');
        }
        $preferred = $this->exponent - $other->exponent;
        if ($this->coefficient->isZero()) {
            return new self(BigInteger::zero(), $preferred);
        }
        $a = $this->coefficient->abs();
        $b = $other->coefficient->abs();
        $magnitude = strlen((string) $a) - strlen((string) $b);
        $compare = $magnitude >= 0 ? $a->compareTo($b->multipliedBy(self::power($magnitude)))
            : $a->multipliedBy(self::power(-$magnitude))->compareTo($b);
        if ($compare < 0) { --$magnitude; }
        $exp = $preferred + $magnitude - (self::PRECISION - 1);
        $shift = $preferred - $exp;
        $numerator = $shift >= 0 ? $this->coefficient->multipliedBy(self::power($shift)) : $this->coefficient;
        $denominator = $shift < 0 ? $other->coefficient->multipliedBy(self::power(-$shift)) : $other->coefficient;
        $coefficient = BigDecimal::of($numerator)->dividedBy($denominator, 0, RoundingMode::HALF_EVEN)->toBigInteger();
        $result = self::rounded($coefficient, $exp);
        // An exact division removes padding only up to the preferred exponent.
        if ($result->number()->multipliedBy($other->number())->compareTo($this->number()) === 0) {
            $digits = (string) $result->coefficient;
            $exp = $result->exponent;
            while ($exp < $preferred && str_ends_with($digits, '0')) {
                $digits = substr($digits, 0, -1); ++$exp;
            }
            $result = new self(BigInteger::of($digits), $exp);
        }
        return $result;
    }

    private static function rounded(BigInteger $coefficient, int $exp): self
    {
        $digits = strlen(ltrim((string) $coefficient, '-'));
        while (!$coefficient->isZero() && $digits > self::PRECISION) {
            $difference = $digits - self::PRECISION;
            $coefficient = BigDecimal::of($coefficient)->dividedBy(self::power($difference), 0, RoundingMode::HALF_EVEN)->toBigInteger();
            $exp += $difference;
            $digits = strlen(ltrim((string) $coefficient, '-'));
        }
        return new self($coefficient, $exp);
    }

    private static function power(int $exp): BigInteger { return BigInteger::of('1' . str_repeat('0', $exp)); }

    public function __toString(): string
    {
        $raw = (string) $this->coefficient;
        $negative = str_starts_with($raw, '-');
        $digits = ltrim($raw, '-');
        $adjusted = strlen($digits) - 1 + $this->exponent;
        $sign = $negative ? '-' : '';
        if ($this->exponent > 0 || $adjusted < -6) {
            $mantissa = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '');
            return $sign . $mantissa . 'E' . ($adjusted >= 0 ? '+' : '') . $adjusted;
        }
        return (string) $this->number();
    }
}

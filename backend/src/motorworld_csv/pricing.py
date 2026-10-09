from decimal import Decimal, ROUND_FLOOR, localcontext
from .domain import Diagnostic, PriceResult, PriceSettings
from .errors import DomainError


def end_in_90(value: Decimal) -> Decimal:
    if not value.is_finite() or value < 0:
        raise DomainError('invalid_price', 'Il prezzo deve essere un importo finito non negativo.')
    return value.to_integral_value(rounding=ROUND_FLOOR) + Decimal('.90')


def calculate_price(cost: Decimal, settings: PriceSettings, override_gross: Decimal | None = None) -> PriceResult:
    if not cost.is_finite() or cost < 0:
        raise DomainError('invalid_cost', 'Il costo IVA esclusa deve essere finito e non negativo.')
    if override_gross is not None and (not override_gross.is_finite() or override_gross <= 0):
        raise DomainError('invalid_price', 'Il prezzo scelto deve essere positivo e finito.')
    with localcontext() as ctx:
        ctx.prec = 28
        one, target = Decimal('1'), Decimal('.20')
        vat_factor = one + settings.vat_rate
        denominator = one - settings.commission_rate * vat_factor
        if denominator <= 0:
            raise DomainError('impossible_commission', 'Commissioni e IVA impediscono un prezzo calcolabile: riduci la commissione.')
        shipping, fixed = settings.shipping_cost, settings.commission_fixed
        raw = (cost * (one + settings.product_markup) + shipping * (one + settings.shipping_markup) + fixed) / denominator * vat_factor
        final = end_in_90(raw if override_gross is None else override_gross)
        net = final / vat_factor
        fee = final * settings.commission_rate + fixed
        profit = net - cost - shipping - fee
        margin = profit / net
        minimum = None
        diagnostics = []
        if denominator > target:
            theoretical = (cost + shipping + fixed) * vat_factor / (denominator - target)
            minimum = end_in_90(theoretical)
            if minimum < theoretical:
                minimum += one
            min_net = minimum / vat_factor
            if (min_net - cost - shipping - minimum * settings.commission_rate - fixed) / min_net < target:
                minimum += one
        else:
            diagnostics.append(Diagnostic(level='warning', code='minimum_unreachable',
                message='Il margine del 20% è irraggiungibile con questi parametri.', hint='Riduci commissioni o costi.'))
        if margin < target:
            diagnostics.append(Diagnostic(level='warning', code='margin_below_20',
                message='Margine sotto 20% dopo la terminazione ,90.', hint='Valuta il prezzo minimo suggerito. Il prezzo non è aumentato automaticamente.'))
        return PriceResult(calculated_gross=raw, final_gross=final, net_revenue=net, commission=fee,
            profit=profit, margin=margin, minimum_gross=minimum, below_minimum=margin < target, diagnostics=diagnostics)

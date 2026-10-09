from decimal import Decimal as D
import pytest
from motorworld_csv.domain import PriceSettings
from motorworld_csv.pricing import calculate_price, end_in_90
from motorworld_csv.errors import DomainError

def settings(**changes):
    return PriceSettings(**(dict(shipping_cost='5', product_markup='.20', shipping_markup='0',
        commission_rate='.10', commission_fixed='0', vat_rate='.22', quantity=1) | changes))

@pytest.mark.parametrize('raw', ['188.94', '188.999', '188.01'])
def test_rounding_keeps_original_euros(raw):
    assert end_in_90(D(raw)) == D('188.90')

def test_margin_uses_gross_commission_and_net_revenue():
    p = calculate_price(D('100'), settings(), D('188.90'))
    assert p.below_minimum and D('.19986') < p.margin < D('.19987')
    assert p.commission == D('18.890') and p.minimum_gross == D('189.90')
    assert calculate_price(D('100'), settings(), p.minimum_gross).margin >= D('.20')

def test_minimum_is_explicit():
    p = calculate_price(D('100'), settings())
    assert p.final_gross == D('173.90') and p.below_minimum
    assert p.minimum_gross == D('189.90')

def test_impossible_commission():
    with pytest.raises(DomainError):
        calculate_price(D('100'), settings(commission_rate='.90'))
    p = calculate_price(D('100'), settings(commission_rate='.70'))
    assert p.minimum_gross is None
    assert any(d.code == 'minimum_unreachable' for d in p.diagnostics)

@pytest.mark.parametrize('cost', ['-1', 'NaN', 'Infinity'])
def test_invalid_cost(cost):
    with pytest.raises(DomainError):
        calculate_price(D(cost), settings())

def test_invalid_quantity():
    with pytest.raises(ValueError):
        settings(quantity=0)

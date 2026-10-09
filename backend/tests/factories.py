from motorworld_csv.domain import Product, Compatibility, PriceSettings

def product(**changes):
    return Product(**(dict(id='product-1', supplier_id='demo', code='00123', name='Pastiglie freno',
        brand='Ferodo', family='Pastiglie Freno', department='Impianto Frenante', cost='100') | changes))

def vehicle(**changes):
    return Compatibility(**(dict(id='fitment-1', product_id='product-1', make='Honda', model='CBR 600',
        vehicle_type='moto') | changes))

def price_settings(**changes):
    return PriceSettings(**(dict(shipping_cost='5', product_markup='.20', shipping_markup='0',
        commission_rate='.10', commission_fixed='0', vat_rate='.22', quantity=1) | changes))

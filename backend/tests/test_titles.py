from factories import product,vehicle
from motorworld_csv.titles import propose_title,validate_title,validate_prestashop_reference,normalize_title

def test_title_limit_and_normalization():
    assert not validate_title('A'*80)
    assert validate_title('A'*81)[0].level=='error'
    assert normalize_title('  Cafe\u0301   Nero ')=='Café Nero'
    assert not validate_title('😀'*80)

def test_title_has_manual_model_and_never_blindly_truncates():
    title=propose_title(product(name='Pastiglie freno'),vehicle())
    assert 'Honda' in title and 'CBR 600' in title and 'Ferodo' in title
    long=propose_title(product(name='X'*81),vehicle())
    assert len(long)>80

def test_reference_rejects_invalid_chars_and_length():
    assert validate_prestashop_reference('abc;def')[0].level=='error'
    assert validate_prestashop_reference('X'*65)
    assert not validate_prestashop_reference('00123')

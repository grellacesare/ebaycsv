"""Compare the shipped PHP domain against the real Python domain.

Run with backend/.venv/bin/python scripts/test_php_domain.py. PHP defaults to
the local php:8.3-apache Docker image; --php-command supports an installed PHP.
No generated reference fixtures or supplier files are retained in the checkout.
"""
from __future__ import annotations

import argparse
from decimal import Decimal
import json
from pathlib import Path
import random
import shlex
import subprocess
import sys
import unicodedata
from uuid import NAMESPACE_URL, uuid5

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "backend/src"))
from motorworld_csv.categories.catalog import load_catalog
from motorworld_csv.categories.matching import normalize_key, resolve_category
from motorworld_csv.categories.models import CategoryCatalog, CategoryRule, MarketplaceCategory
from motorworld_csv.compatibility import listing_identity, validate_compatibilities
from motorworld_csv.domain import Compatibility, ListingOverrides, PriceSettings, Product
from motorworld_csv.errors import DomainError
from motorworld_csv.exporting.contracts import csv_columns, draft_to_row, snapshot_fields
from motorworld_csv.pipeline import compile_drafts
from motorworld_csv.platforms.descriptions import public_https_url, render_description
from motorworld_csv.platforms.profiles import EbayProfile, PrestashopProfile, validate_platform_profile
from motorworld_csv.pricing import calculate_price, end_in_90
from motorworld_csv.titles import normalize_title, propose_title, validate_prestashop_reference, validate_title


def serial(value):
    if hasattr(value, "model_dump"):
        return value.model_dump(mode="json")
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, (list, tuple)):
        return [serial(v) for v in value]
    if isinstance(value, dict):
        return {k: serial(v) for k, v in value.items()}
    return value


def fixtures():
    cases = []
    def add(name, op, args, function):
        entry = {"name": name, "op": op, "args": serial(args)}
        try:
            entry["result"] = serial(function())
        except DomainError as error:
            entry["error"] = {"code": error.code, "message": str(error)}
        cases.append(entry)

    p = Product(id="product-1", supplier_id="demo", code="00123", name="Pastiglie freno", brand="Ferodo", family="Pastiglie Freno", department="Impianto Frenante", cost="100")
    fit = Compatibility(id="fitment-1", product_id=p.id, make="Honda", model="CBR 600", vehicle_type="moto")
    second = fit.model_copy(update={"id": "fitment-2", "model": "CB 500", "vehicle_type": "scooter", "year_from": 2000, "year_to": 2024})
    settings = PriceSettings(shipping_cost="5", product_markup=".20", shipping_markup="0", commission_rate=".10", commission_fixed="0", vat_rate=".22", quantity=1)
    catalog = load_catalog(ROOT / "backend/src/motorworld_csv/resources")
    ebay = EbayProfile(free_shipping_confirmed=True)
    ps = PrestashopProfile(tax_rule_id="1", free_shipping_confirmed=True)

    prices = ["0", "0.00", "-0.00", ".00000001", "100", "99.9999999999999999999999999999", "188.999", "1E+30", "1.234567890123456789012345678E-20"]
    rng = random.Random(71295)
    prices += [str(Decimal(rng.randrange(0, 100000000)) / Decimal("10000")) for _ in range(50)]
    variations = [{}, {"commission_rate": ".70"}, {"commission_rate": ".9"}, {"vat_rate": "0", "commission_rate": "0", "shipping_cost": "0"}, {"shipping_markup": ".05", "commission_fixed": ".30", "product_markup": ".3212345678901234567890123456"}]
    for vi, changes in enumerate(variations):
        s = PriceSettings.model_validate(settings.model_dump() | changes)
        for i, cost in enumerate(prices):
            override = "188.90" if i % 7 == 0 else None
            add(f"pricing-{vi}-{i}", "pricing", [cost, s, override], lambda cost=cost, s=s, override=override: calculate_price(Decimal(cost), s, Decimal(override) if override else None))
    for value in ["-1", "NaN", "Infinity"]:
        add(f"invalid-cost-{value}", "pricing", [value, settings], lambda value=value: calculate_price(Decimal(value), settings))
    for value in ["0", "-1", "NaN", "Infinity"]:
        add(f"invalid-override-{value}", "pricing", ["100", settings, value], lambda value=value: calculate_price(Decimal("100"), settings, Decimal(value)))
    for value in ["188.94", "188.999", "188.01", "0", "1E+30", "-1", "NaN"]:
        add(f"end90-{value}", "end90", [value], lambda value=value: end_in_90(Decimal(value)))

    for i, title in enumerate(["  Cafe\u0301   Nero ", "😀" * 80, "😀" * 81, "X" * 81, "a\x00b", "a\x7fb", "a\t\nb", "\u2003 A\u00a0B \u0085", "", "\u001cA\u001fB", "🧑‍🔧"]):
        add(f"normalize-title-{i}", "normalizeTitle", [title], lambda title=title: normalize_title(title))
        add(f"validate-title-{i}", "validateTitle", [title], lambda title=title: validate_title(title))
        add(f"normalize-key-{i}", "normalizeKey", [title], lambda title=title: normalize_key(title))
    for name in ["Pastiglie FERODO", "Ricambio Straße", "Cafe\u0301"]:
        product = p.model_copy(update={"name": name, "brand": "STRASSE"})
        add(f"title-{name}", "title", [product, fit], lambda product=product: propose_title(product, fit))
    for reference in ["00123", "😀" * 64, "😀" * 65, "abc;def", "foo<", "", "x\x00y"]:
        add(f"reference-{reference}", "validateReference", [reference], lambda reference=reference: validate_prestashop_reference(reference))
    for i, fits in enumerate([[fit, second], [fit, fit.model_copy(update={"id": "dup", "model": " CBR  600 "})], [fit, fit.model_copy(update={"model": "other"})], [fit.model_copy(update={"model": " ", "year_from": 2020, "year_to": 2019})]]):
        add(f"compatibilities-{i}", "compatibilities", [fits], lambda fits=fits: validate_compatibilities(fits))
    for supplier, code in [("demo", "00123"), ("à\u0301", "😀000"), ("other", "00123")]:
        product = p.model_copy(update={"supplier_id": supplier, "code": code})
        add(f"uuid-{supplier}", "productId", [supplier, code], lambda supplier=supplier, code=code: str(uuid5(NAMESPACE_URL, supplier + "\0" + code)))
        for destination in ["ebay", "prestashop"]:
            for variant in [None, fit, second]:
                add(f"identity-{supplier}-{destination}-{variant.id if variant else 'base'}", "identity", [product, variant, destination], lambda product=product, variant=variant, destination=destination: listing_identity(product, variant, destination))
    add("catalog", "catalog", [], lambda: catalog)
    for destination, cls in [("ebay", EbayProfile), ("prestashop", PrestashopProfile)]:
        add(f"profile-{destination}", "profile", [destination], lambda cls=cls: cls())
        add(f"columns-{destination}", "columns", [destination], lambda destination=destination: csv_columns(destination))
    for i, profile in enumerate([ebay, ps, EbayProfile(location=" ", condition_id="x", store_category="X", logo_url="https://localhost/x"), PrestashopProfile(tax_rule_id="0")]):
        add(f"validate-profile-{i}", "validateProfile", [profile], lambda profile=profile: validate_platform_profile(profile))
    # Python's isdigit accepts superscript/circled numerals as well as Nd.
    for codepoint in range(0x110000):
        character = chr(codepoint)
        if character.isdigit() and (unicodedata.category(character) != "Nd" or character in "١𑽑𞓱"):
            profile = ebay.model_copy(update={"condition_id": character})
            add(f"profile-digit-{codepoint:x}", "validateProfile", [profile], lambda profile=profile: validate_platform_profile(profile))
    for value in ["٠١", "００１", "٠٠"]:
        profile = ps.model_copy(update={"tax_rule_id": value})
        add(f"tax-rule-{value}", "validateProfile", [profile], lambda profile=profile: validate_platform_profile(profile))
    rule = CategoryRule(supplier_id="demo", family="Carburatori", context="scooter", destination="ebay", category_id="82205")
    generic = rule.model_copy(update={"context": "unspecified", "category_id": "177773"})
    ambiguous = CategoryCatalog(ebay=[MarketplaceCategory(id=str(i), path="Moto: ricambi > Luci", context="moto") for i in (1, 2)])
    for i, (product, variant, destination, cats, rules, override) in enumerate([
        (p, fit, "ebay", catalog, [], None), (p, None, "ebay", catalog, [], None),
        (p.model_copy(update={"family": "Carburatori"}), second, "ebay", catalog, [generic, rule], None),
        (p.model_copy(update={"family": "Carburatori"}), second, "ebay", catalog, [], "177773"),
        (p.model_copy(update={"family": "Moto: ricambi > Luci"}), fit, "ebay", ambiguous, [], None),
        (p, None, "prestashop", catalog, [], "1179"), (p, None, "prestashop", catalog, [], "999999"),
        (p, None, "prestashop", catalog, [], None)]):
        args = [product, variant, destination, cats, rules, override]
        add(f"category-{i}", "category", args, lambda args=args: resolve_category(*args))
    urls = ["https://example.com/a", "http://example.com/a", "https://localhost/a", "https://x.local/a", "https://10.0.0.1/a", "https://127.0.0.1/a", "https://8.8.8.8/a", "https://[::1]/a", "https://[2606:4700:4700::1111]/a", "https://user:pass@example.com/a", "https://example.com:444/a", "https://example.com:443/a", "https://example.invalid/a", "https://test/a", "https://100.64.0.1/a", "https://192.0.0.9/a", "https://192.0.0.10/a", "https://192.0.0.11/a", "https://198.19.0.1/a", "https://[::ffff:192.168.0.1]/a", "https://[64:ff9b:1::]/a", "https://[2002::]/a", "https://foo.internal/a", "https://example.com:bad/a"]
    for i, url in enumerate(urls):
        add(f"url-{i}", "publicUrl", [url], lambda url=url: public_https_url(url))
    rich = p.model_copy(update={"name": "<Ricambio & 'test'>", "image_urls": ["https://example.com/a,b.png"], "extra": {"description": "prima\n<seconda>&", "features": "a\nb\u2028c", "box": "vite\nvite", "notes": "<note>\nfunziona", "mpn": "000&x", "info-agg": "<more>"}})
    for i, (product, fits, profile) in enumerate([(p, [fit], ebay), (p, [], ebay), (rich, [second], ebay.model_copy(update={"logo_url": "https://example.com/logo.png"})), (rich, [fit, second], ps), (rich, [], ps), (p, [fit, second], ebay), (p.model_copy(update={"image_urls": ["file:///etc/passwd"]}), [], ebay)]):
        args = [product, fits, profile, "Titolo <test> & 'x'", "0001", 7]
        add(f"description-{i}", "description", args, lambda args=args: render_description(*args))
    for i, (product, variant, profile) in enumerate([(p, fit, ebay), (rich, second, ebay), (p, None, ps), (rich, None, ps)]):
        add(f"snapshot-{i}", "snapshot", [product, variant, profile], lambda product=product, variant=variant, profile=profile: snapshot_fields(product, variant, profile))
    for destination, profile in [("ebay", ebay), ("prestashop", ps)]:
        product = p.model_copy(update={"ean": "0", "cost": Decimal("-0.00")})
        add(f"snapshot-zero-{destination}", "snapshot", [product, None, profile], lambda product=product, profile=profile: snapshot_fields(product, None, profile))
        args = [product, [fit], settings, profile, catalog, [], {}]
        add(f"compile-zero-{destination}", "compile", args, lambda args=args: compile_drafts(*args))
    compile_cases = [(p, [fit, second], ebay, {}), (p, [fit, second], ps, {}), (p, [], ebay, {listing_identity(p, None, "ebay"): ListingOverrides(listing_id=listing_identity(p, None, "ebay"), category_id="177925")}), (rich, [fit], ebay, {}), (rich.model_copy(update={"ean": "not-ean", "cost": None}), [fit], ps, {}), (p.model_copy(update={"family": "Carburatori", "extra": {"ebay_category_id": "177773"}}), [fit, second], ebay, {}), (p, [fit, second], ebay, {listing_identity(p, second, "ebay"): ListingOverrides(listing_id=listing_identity(p, second, "ebay"), title="😀" * 81, gross_price="188.90")})]
    for i, (product, fits, profile, overrides) in enumerate(compile_cases):
        args = [product, fits, settings, profile, catalog, [], overrides]
        add(f"compile-{i}", "compile", args, lambda args=args: compile_drafts(*args))
        for j, draft in enumerate(compile_drafts(*args)):
            if draft.valid:
                add(f"draft-row-{i}-{j}", "draftRow", [draft, product, profile], lambda draft=draft, product=product, profile=profile: draft_to_row(draft, product, profile))
    return cases


def first_difference(expected, actual, path=""):
    if isinstance(expected, dict) and isinstance(actual, dict):
        if expected.keys() != actual.keys():
            return f"{path}: keys expected {sorted(expected)}, actual {sorted(actual)}"
        for key in expected:
            found = first_difference(expected[key], actual[key], path + "/" + key)
            if found:
                return found
    elif isinstance(expected, list) and isinstance(actual, list):
        if len(expected) != len(actual):
            return f"{path}: length expected {len(expected)}, actual {len(actual)}"
        for i, (left, right) in enumerate(zip(expected, actual)):
            found = first_difference(left, right, path + f"/{i}")
            if found:
                return found
    elif expected != actual or type(expected) is not type(actual):
        return f"{path}: expected {expected!r}, actual {actual!r}"
    return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php-command", help="Command that executes the PHP fixture runner")
    options = parser.parse_args()
    command = shlex.split(options.php_command) if options.php_command else ["docker", "run", "--rm", "-i", "-v", f"{ROOT}:/app:ro", "-w", "/app", "php:8.3-apache", "php", "hosting/php/tests/domain.php"]
    cases = fixtures()
    completed = subprocess.run(command, input=json.dumps([{k: v for k, v in case.items() if k not in ("result", "error")} for case in cases], ensure_ascii=False), text=True, capture_output=True)
    if completed.returncode:
        print(completed.stderr or completed.stdout, file=sys.stderr)
        return completed.returncode
    actual = json.loads(completed.stdout)
    failures = []
    if len(actual) != len(cases):
        failures.append(f"Expected {len(cases)} cases, actual {len(actual)}")
    for expected, observed in zip(cases, actual):
        expected = {k: v for k, v in expected.items() if k not in ("op", "args")}
        difference = first_difference(expected, observed)
        if difference:
            failures.append(f"{expected['name']}{difference}")
    if failures:
        print("\n".join(failures[:30]), file=sys.stderr)
        print(f"FAILED: {len(failures)} of {len(cases)} differential cases", file=sys.stderr)
        return 1
    print(f"PASS: {len(cases)} PHP/Python domain differential cases")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

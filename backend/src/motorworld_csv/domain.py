from decimal import Decimal
from typing import Callable, Literal
from uuid import uuid4
from pydantic import BaseModel, ConfigDict, Field


class Model(BaseModel):
    model_config = ConfigDict(extra='forbid', allow_inf_nan=False)


class SourceRef(Model):
    file: str
    row: int
    columns: dict[str, str] = Field(default_factory=dict)


class Diagnostic(Model):
    level: Literal['error', 'warning', 'info']
    code: str
    message: str
    hint: str = ''
    product_id: str | None = None
    listing_id: str | None = None
    source: SourceRef | None = None
    field: str | None = None
    value: str | None = None


class Packaging(Model):
    quantity: Decimal | None = None
    gross_weight: Decimal | None = None
    volume: Decimal | None = None
    weight_unit: str | None = None
    source: SourceRef


class Product(Model):
    id: str = Field(default_factory=lambda: str(uuid4()))
    supplier_id: str
    code: str
    name: str = ''
    brand: str = ''
    family: str = ''
    department: str = ''
    cost: Decimal | None = None
    ean: str | None = None
    image_urls: list[str] = Field(default_factory=list)
    available: bool | None = None
    packaging: list[Packaging] = Field(default_factory=list)
    extra: dict[str, str] = Field(default_factory=dict)
    sources: list[SourceRef] = Field(default_factory=list)
    diagnostics: list[Diagnostic] = Field(default_factory=list)


class Compatibility(Model):
    id: str = Field(default_factory=lambda: str(uuid4()))
    product_id: str
    make: str = ''
    model: str = ''
    displacement: str = ''
    year_from: int | None = None
    year_to: int | None = None
    vehicle_type: Literal['moto', 'scooter', 'other', 'unspecified'] = 'unspecified'


class PriceSettings(Model):
    shipping_cost: Decimal = Field(ge=0)
    product_markup: Decimal = Field(ge=0)
    shipping_markup: Decimal = Field(ge=0)
    commission_rate: Decimal = Field(ge=0, le=1)
    commission_fixed: Decimal = Field(default=Decimal('0'), ge=0)
    vat_rate: Decimal = Field(default=Decimal('.22'), ge=0, le=1)
    quantity: int = Field(gt=0)


class PriceResult(Model):
    calculated_gross: Decimal
    final_gross: Decimal
    net_revenue: Decimal
    commission: Decimal
    profit: Decimal
    margin: Decimal
    minimum_gross: Decimal | None
    below_minimum: bool
    diagnostics: list[Diagnostic] = Field(default_factory=list)


class ListingOverrides(Model):
    listing_id: str
    title: str | None = None
    reference: str | None = None
    gross_price: Decimal | None = None
    category_id: str | None = None


class ListingDraft(Model):
    id: str
    product_id: str
    compatibility_id: str | None = None
    destination: Literal['ebay', 'prestashop']
    reference: str
    title: str
    category_ids: list[str] = Field(default_factory=list)
    price: PriceResult | None = None
    quantity: int = 1
    description_html: str = ''
    fields: dict[str, str] = Field(default_factory=dict)
    diagnostics: list[Diagnostic] = Field(default_factory=list)
    valid: bool = False
    exported: bool = False
    stale: bool = False


class Progress(Model):
    stage: str
    completed: int
    total: int
    unit: str = 'righe'


ProgressCallback = Callable[[Progress], None]

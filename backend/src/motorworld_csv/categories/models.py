from typing import Literal
from pydantic import Field
from ..domain import Model, Diagnostic

class MarketplaceCategory(Model):
    id: str
    path: str
    context: Literal['moto','scooter','shared','other','unknown'] = 'unknown'

class StoreCategory(Model):
    id: str
    name: str
    description: str = ''
    position: int = 0
    shown: bool = True

class CategoryRule(Model):
    supplier_id: str
    family: str
    context: str = 'unspecified'
    destination: Literal['ebay','prestashop']
    category_id: str

class CategoryCatalog(Model):
    ebay: list[MarketplaceCategory] = Field(default_factory=list)
    prestashop: list[StoreCategory] = Field(default_factory=list)
    seed_rules: list[dict[str,str]] = Field(default_factory=list)
    version: str = ''

class CategoryDecision(Model):
    category_ids: list[str] = Field(default_factory=list)
    method: str = 'unresolved'
    reason: str = ''
    diagnostics: list[Diagnostic] = Field(default_factory=list)

from pathlib import Path
from typing import Literal
from pydantic import Field, model_validator
from ..domain import Model, Product, Diagnostic

Role = Literal['catalog', 'prices', 'packaging']

class FileBinding(Model):
    role: Role
    encoding: str = 'utf-8'
    delimiter: str = ';'
    decimal_separator: Literal['.', ','] = '.'
    thousands_separator: str | None = None
    column_map: dict[str,str]
    weight_unit: str | None = None
    packaging_basis_confirmed: bool = False

    @model_validator(mode='after')
    def validate_binding(self):
        if len(self.delimiter) != 1 or 'code' not in self.column_map:
            raise ValueError('Scegli un delimitatore singolo e la colonna del codice prodotto.')
        if self.thousands_separator == self.decimal_separator:
            raise ValueError('Separatore decimale e delle migliaia devono essere distinti.')
        return self

class ImportProfile(Model):
    id: str
    supplier_id: str
    name: str
    bindings: list[FileBinding]

    @model_validator(mode='after')
    def validate_profile(self):
        roles = [b.role for b in self.bindings]
        if 'catalog' not in roles or len(roles) != len(set(roles)):
            raise ValueError('Serve un binding anagrafica e un solo binding per ruolo.')
        if sum('cost' in b.column_map for b in self.bindings) != 1:
            raise ValueError('Scegli esplicitamente una sola colonna del costo IVA esclusa.')
        return self

class InputSource(Model):
    id: str
    path: Path
    role: Role

class CsvInspection(Model):
    columns: list[str] = Field(default_factory=list)
    preview: list[dict[str,str]] = Field(default_factory=list)
    record_count: int = 0
    encoding: str | None = None
    delimiter: str | None = None
    proposed_map: dict[str,str] = Field(default_factory=dict)
    diagnostics: list[Diagnostic] = Field(default_factory=list)

class ImportResult(Model):
    products: list[Product]
    diagnostics: list[Diagnostic]
    source_counts: dict[str,int]

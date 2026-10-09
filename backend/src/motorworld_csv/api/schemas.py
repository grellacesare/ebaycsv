from typing import Literal, Annotated
from decimal import Decimal
from pydantic import Field
from ..domain import Model, Product, Compatibility, PriceSettings, Diagnostic
from ..platforms.profiles import EbayProfile, PrestashopProfile
from ..importing.models import CsvInspection, Role
from ..exporting.contracts import Artifact, ExportRecord

Destination=Literal['ebay','prestashop']
Profile=Annotated[EbayProfile | PrestashopProfile,Field(discriminator='destination')]

class UploadInfo(Model):
    id: str
    name: str
    size: int
    inspection: CsvInspection

class UploadResult(Model):
    uploads: list[UploadInfo]

class StoredUpload(Model):
    id: str
    name: str
    size: int

class UploadAssignment(Model):
    upload_id: str
    role: Role

class ImportRequest(Model):
    profile_id: str
    uploads: list[UploadAssignment]
    request_key: str = Field(min_length=1,max_length=200)

class ImportSummary(Model):
    id: str
    created_at: str
    product_count: int
    source_counts: dict[str,int]
    diagnostics: list[Diagnostic]

class SettingsBundle(Model):
    platform_profile: Profile
    price_settings: PriceSettings | None = None

class SettingsUpdate(Model):
    platform_profile: Profile
    price_settings: PriceSettings

class PricePreviewRequest(Model):
    cost: Decimal
    price_settings: PriceSettings
    override_gross: Decimal | None = None

class CompatibilityUpdate(Model):
    rows: list[Compatibility]

class ProductEdits(Model):
    name: str | None = None
    brand: str | None = None
    family: str | None = None
    department: str | None = None
    cost: Decimal | None = None
    ean: str | None = None
    image_urls: list[str] | None = None
    available: bool | None = None
    extra: dict[str,str] | None = None

class ProductUpdate(Model):
    updates: ProductEdits
    resolve_fields: list[str] = Field(default_factory=list)

class ValidateRequest(Model):
    destination: Destination
    request_key: str = Field(min_length=1,max_length=200)

class ExportRequest(Model):
    import_id: str
    destination: Destination
    request_key: str = Field(min_length=1,max_length=200)
    selection_mode: Literal['new_valid','selected'] = 'new_valid'
    draft_ids: list[str] = Field(default_factory=list)
    query: str = ''
    category_id: str | None = None
    available: bool | None = None
    state: str | None = None

class ExportSummary(Model):
    id: str
    import_id: str
    destination: str
    included_count: int
    excluded_count: int
    import_error_count: int = 0
    import_warning_count: int = 0
    artifacts: list[Artifact]
    diagnostics: list[Diagnostic]
    created_at: str

def summarize_export(record: ExportRecord) -> ExportSummary:
    return ExportSummary(id=record.id,import_id=record.import_id,destination=record.destination,
        included_count=len(record.included_ids),excluded_count=len(record.excluded_ids),artifacts=record.artifacts,
        import_error_count=record.snapshot.get('import_error_count',0),import_warning_count=record.snapshot.get('import_warning_count',0),
        diagnostics=record.diagnostics,created_at=record.created_at)

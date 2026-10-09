import json
from pathlib import Path
from tempfile import TemporaryDirectory
from motorworld_csv.api.app import create_app

if __name__=='__main__':
    with TemporaryDirectory(prefix='mw-openapi-') as directory:
        schema=create_app(Path(directory)).openapi()
    target=Path(__file__).resolve().parents[1]/'frontend'/'openapi.json'
    target.write_text(json.dumps(schema,ensure_ascii=False,indent=2)+'\n')

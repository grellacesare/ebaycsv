import { defineConfig } from '@playwright/test';
import { resolve } from 'node:path';
export default defineConfig({testDir:'e2e',workers:1,timeout:60000,use:{baseURL:'http://127.0.0.1:5173',browserName:'chromium',launchOptions:{executablePath:process.env.MW_CHROMIUM_PATH || '/usr/bin/chromium'},trace:'retain-on-failure'},webServer:[
  {command:'uv run --project ../backend --frozen uvicorn motorworld_csv.api.app:create_app --factory --host 127.0.0.1 --port 8000',url:'http://127.0.0.1:8000/api/health',reuseExistingServer:false,env:{UV_CACHE_DIR:resolve('../var/cache/uv'),MW_DATA_DIR:resolve('../var/e2e-data-'+process.pid)}},
  {command:'npm run dev -- --port 5173 --strictPort',url:'http://127.0.0.1:5173',reuseExistingServer:false}
]});

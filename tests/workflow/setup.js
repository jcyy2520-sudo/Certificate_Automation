import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

export default function setup() {
    for (const directory of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
        mkdirSync(path.join(workflowEnvironment.LARAVEL_STORAGE_PATH, directory), { recursive: true });
    }
    execFileSync('php', ['tests/workflow/seed.php'], { env: workflowEnvironment, stdio: 'pipe' });
}

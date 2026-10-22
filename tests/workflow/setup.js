import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

export default function setup() {
    execFileSync('php', ['tests/workflow/seed.php'], { env: workflowEnvironment, stdio: 'pipe' });
}

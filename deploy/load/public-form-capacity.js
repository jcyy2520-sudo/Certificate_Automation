import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';

const formUrl = (__ENV.FORM_URL || '').replace(/\/$/, '');
const mode = __ENV.MODE || 'access';
const rate = Number(__ENV.RATE || 25);
const duration = __ENV.DURATION || '2m';
const preAllocatedVUs = Number(__ENV.PREALLOCATED_VUS || 100);
const maxVUs = Number(__ENV.MAX_VUS || 500);

if (!formUrl) {
    throw new Error('FORM_URL is required.');
}

if (!['access', 'submit'].includes(mode)) {
    throw new Error('MODE must be access or submit.');
}

export const options = {
    scenarios: {
        public_forms: {
            executor: 'constant-arrival-rate',
            rate,
            timeUnit: '1s',
            duration,
            preAllocatedVUs,
            maxVUs,
        },
    },
    thresholds: {
        checks: ['rate>0.99'],
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1000', 'p(99)<2000'],
        dropped_iterations: ['count==0'],
    },
};

function csrfToken(response) {
    return response.html().find('meta[name="csrf-token"]').attr('content');
}

export default function () {
    const page = http.get(formUrl, {
        tags: { operation: 'open-form' },
        redirects: 0,
    });
    const token = csrfToken(page);
    const unique = `${exec.vu.idInTest}-${exec.scenario.iterationInTest}`;
    const email = `load-${unique}@example.invalid`;

    check(page, {
        'form page is available': (response) => response.status === 200,
        'CSRF token is present': () => Boolean(token),
        'admin navigation is absent': (response) => !response.body.includes('/admin'),
    });

    if (!token) {
        return;
    }

    const target = mode === 'access' ? `${formUrl}/access/request` : formUrl;
    const payload = mode === 'access'
        ? { _token: token, email }
        : {
            _token: token,
            full_name: `Capacity Test ${unique}`,
            email,
            privacy_acknowledged: '1',
        };
    const response = http.post(target, payload, {
        tags: { operation: mode },
        redirects: 0,
    });

    check(response, {
        [`${mode} request is accepted`]: (result) => result.status === 302,
    });
}

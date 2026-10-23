const administratorRestriction = 'Access to this webinar has been restricted by an administrator. Contact your administrator if you need access.';

/** Keep administrator restrictions distinct from the existing password unlock. */
export async function webinarRestrictionMessage(response) {
    if (response.status !== 423) return null;
    if (response.headers.get('content-type')?.includes('application/json')) {
        const body = await response.json();
        return body.message || 'Webinar access needs attention. Refresh the page to continue.';
    }
    return administratorRestriction;
}

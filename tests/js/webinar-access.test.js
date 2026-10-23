import { describe, expect, it } from 'vitest';
import { webinarRestrictionMessage } from '../../resources/js/webinar-access';

describe('webinar restriction feedback', () => {
    it('uses the administrator explanation for a restricted HTML request', async () => {
        expect(await webinarRestrictionMessage(new Response('', { status: 423, headers: { 'Content-Type': 'text/html' } })))
            .toContain('restricted by an administrator');
    });

    it('retains the separate password-unlock instruction from JSON', async () => {
        const response = Response.json({ message: 'Unlock this webinar to continue.' }, { status: 423 });
        expect(await webinarRestrictionMessage(response)).toBe('Unlock this webinar to continue.');
    });

    it('preserves the administrator JSON message', async () => {
        const message = 'Access to this webinar has been restricted by an administrator. Contact your administrator if you need access.';
        expect(await webinarRestrictionMessage(Response.json({ message }, { status: 423 }))).toBe(message);
    });

    it('leaves unrelated responses available to their existing handlers', async () => {
        const response = Response.json({ message: 'Validation failed.' }, { status: 422 });
        expect(await webinarRestrictionMessage(response)).toBeNull();
        expect(response.bodyUsed).toBe(false);
    });
});

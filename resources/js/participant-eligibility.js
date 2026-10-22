export function initParticipantEligibility(root = document, signal) {
    root.querySelectorAll('[data-eligibility-review-decision]').forEach(form => {
        const decision = form.elements.decision;
        const correction = form.elements.correction_type;
        const issue = form.elements.import_issue_id;
        const update = () => {
            const approving = decision.value === 'approved';
            form.querySelector('[data-eligibility-correction]').hidden = !approving;
            correction.disabled = !approving;
            const applyingImport = approving && correction.value === 'existing_import';
            form.querySelector('[data-eligibility-import]').hidden = !applyingImport;
            issue.disabled = !applyingImport;
            issue.required = applyingImport;
        };
        decision.addEventListener('change', update, { signal });
        correction.addEventListener('change', update, { signal });
        update();
    });
}

import { initCertificateEditor, initCertificateTemplateEditor } from './certificate-editor';
import { initCertificatePdfUpload } from './certificate-pdf-upload';
import { initFormAvailability } from './form-availability';
import { initFormResponses } from './form-responses';
import { initAdminUI } from './admin-ui';
import { initAccountControls } from './account-controls';
import { initFormBuilder } from './form-builder';
import { initWebinarSettings } from './webinar-settings';
import { initNavigation } from './navigation';
import { initTheme } from './theme';
import { initPageUsability } from './page-usability';
import { initStudioRecipients } from './studio-recipients';

let pageController;
function initializePage() {
    pageController = new AbortController();
    const signal = pageController.signal;
    initAdminUI(signal);
    initAccountControls(signal);
    initCertificateTemplateEditor(document, signal);
    initCertificateEditor(document, signal);
    initStudioRecipients(document, signal);
    initCertificatePdfUpload();
    initFormAvailability();
    initFormResponses(document, signal);
    initFormBuilder();
    initWebinarSettings();
    initPageUsability(signal);
}
document.addEventListener('app:before-render', () => pageController?.abort());
initTheme();
initializePage();
initNavigation(initializePage);

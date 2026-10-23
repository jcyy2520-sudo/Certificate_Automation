<dialog data-send-progress-dialog aria-labelledby="certificate-progress-title" class="m-auto w-[min(95vw,44rem)] rounded-xl border border-slate-200 bg-white p-6 text-slate-900 shadow-xl backdrop:bg-slate-950/40">
    <h2 id="certificate-progress-title" class="text-lg font-semibold">Certificate sending progress</h2>
    <p class="mt-2 text-sm text-slate-600">Keep this page open. Each recipient is processed separately. Closing the page stops further recipients after the current request.</p>
    <p class="mt-4 font-semibold" data-progress-summary role="status" aria-live="polite"></p>
    <progress class="mt-3 h-3 w-full" data-progress-bar value="0" max="1" aria-label="Recipients processed"></progress>
    <p class="mt-3 text-sm" data-progress-current aria-live="polite"></p>
    <ul class="mt-4 max-h-72 overflow-y-auto" data-progress-list aria-label="Individual sending results"></ul>
    <div class="mt-5 flex flex-wrap justify-end gap-3">
        <button type="button" class="button-secondary" data-progress-stop>Stop after current recipient</button>
        <button type="button" class="button-primary" data-progress-close disabled>Close results</button>
    </div>
</dialog>

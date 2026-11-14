export function saveFeedback(target, message, state = 'saved', toast = false) {
    if (target) {
        target.textContent = message;
        target.dataset.state = state;
        target.setAttribute('role', 'status');
    }
    if (!toast) return;
    let notice = document.getElementById('entry-save-toast');
    if (!notice) {
        notice = document.createElement('div');
        notice.id = 'entry-save-toast';
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        document.body.append(notice);
    }
    clearTimeout(notice.dismissTimer);
    const text = document.createElement('span');
    text.textContent = message;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'entry-toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '\u00d7';
    close.addEventListener('click', () => {
        clearTimeout(notice.dismissTimer);
        notice.hidden = true;
    });
    notice.replaceChildren(text, close);
    notice.dataset.state = state;
    notice.hidden = false;
    if (state !== 'error') notice.dismissTimer = setTimeout(() => { notice.hidden = true; }, 3200);
}

export const savedTime = () => new Date().toLocaleTimeString([], {hour12:false});

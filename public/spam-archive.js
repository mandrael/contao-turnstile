// Posteingang der Spam-Ablage: Aktionen per fetch statt Neuladen. Ohne JavaScript funktionieren dieselben
// Formulare normal (POST + Redirect).
document.addEventListener('submit', async (event) => {
    const form = event.target;

    if (!form.matches?.('.tsa-actions, .tsa-bulk')) {
        return;
    }

    const button = event.submitter;

    if (button?.dataset.confirm && !confirm(button.dataset.confirm)) {
        event.preventDefault();

        return;
    }

    event.preventDefault();

    const body = new FormData(form);
    body.set('tsa_action', button?.value ?? '');
    form.querySelectorAll('button').forEach((b) => (b.disabled = true));

    let data = null;
    let rejected = false;

    try {
        const response = await fetch(form.action, { method: 'POST', body, headers: { 'X-Tsa-Fetch': '1' } });
        rejected = response.status >= 400;
        data = await response.json();
    } catch (e) {
        data = null;
    }

    if (!data?.ok && !rejected) {
        // Ausgang unklar (Netzfehler, keine lesbare Antwort): nicht erneut senden, sonst droht beim Zustellen
        // ein zweiter Versand. Neu laden zeigt den echten Stand.
        const note = document.createElement('p');
        note.className = 'tsa-error';
        note.textContent = form.dataset.unclear ?? 'Error';
        form.after(note);

        return;
    }

    if (!data?.ok) {
        // Abgewiesen (Sitzung abgelaufen, Token ungültig o. Ä.): normal absenden, Contao zeigt den Grund.
        form.querySelectorAll('button').forEach((b) => (b.disabled = false));
        const fallback = document.createElement('input');
        fallback.type = 'hidden';
        fallback.name = 'tsa_action';
        fallback.value = button?.value ?? '';
        form.append(fallback);
        form.submit();

        return;
    }

    const ids = (form.elements.ids?.value ?? '').split(',');
    const cards = ids.map((id) => document.getElementById('tsa-' + id)).filter(Boolean);

    // Bearbeitete Einträge schrumpfen auf eine Zeile; die Sammelaktion verschwindet danach.
    cards.forEach((card) => {
        const head = card.querySelector('header strong')?.textContent ?? '';
        card.className = 'tsa-card tsa-done';
        card.textContent = head + ' · ' + (button?.dataset.done || data.text);
    });

    if (form.classList.contains('tsa-bulk')) {
        form.remove();
    }

    const counts = data.counts ?? {};
    counts.all = (counts.unreviewed ?? 0) + (counts.spam ?? 0) + (counts.ham ?? 0);
    document.querySelectorAll('.tsa-tabs [data-count]').forEach((el) => (el.textContent = counts[el.dataset.count] ?? 0));
});

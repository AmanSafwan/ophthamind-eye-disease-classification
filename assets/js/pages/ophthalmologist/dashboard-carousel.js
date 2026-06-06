(function () {
    const track = document.getElementById('pbiTrack');
    if (!track) return;

    const slides = track.querySelectorAll('.pbi-slide');
    const total = slides.length;
    let index = 0;

    const btnPrev = document.getElementById('pbiPrev');
    const btnNext = document.getElementById('pbiNext');
    const label = document.getElementById('pbiSlideLabel');
    const dotsWrap = document.getElementById('pbiDots');

    function renderDots() {
        if (!dotsWrap) return;
        dotsWrap.innerHTML = '';
        for (let i = 0; i < total; i++) {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'pbi-dot' + (i === index ? ' active' : '');
            dot.setAttribute('aria-label', 'Slide ' + (i + 1));
            dot.addEventListener('click', () => goTo(i));
            dotsWrap.appendChild(dot);
        }
    }

    function update() {
        track.style.transform = 'translateX(-' + (index * 100) + '%)';
        if (label) label.textContent = (index + 1) + ' / ' + total;
        dotsWrap?.querySelectorAll('.pbi-dot').forEach((d, i) => {
            d.classList.toggle('active', i === index);
        });
        document.querySelectorAll('.pbi-slicer[data-goto]').forEach((btn) => {
            const goto = parseInt(btn.getAttribute('data-goto'), 10);
            btn.classList.toggle('active', goto === index);
        });
    }

    function goTo(i) {
        index = (i + total) % total;
        update();
    }

    btnPrev?.addEventListener('click', () => goTo(index - 1));
    btnNext?.addEventListener('click', () => goTo(index + 1));

    let touchStartX = 0;
    track.parentElement?.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    track.parentElement?.addEventListener('touchend', (e) => {
        const diff = e.changedTouches[0].screenX - touchStartX;
        if (Math.abs(diff) > 50) {
            goTo(diff < 0 ? index + 1 : index - 1);
        }
    }, { passive: true });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') goTo(index - 1);
        if (e.key === 'ArrowRight') goTo(index + 1);
    });

    document.querySelectorAll('.pbi-slicer[data-goto]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const i = parseInt(btn.getAttribute('data-goto'), 10);
            if (!Number.isNaN(i)) goTo(i);
        });
    });

    renderDots();
    update();
})();

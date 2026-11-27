'use strict';

const lightbox = document.getElementById('image-lightbox');
if (lightbox && typeof lightbox.showModal === 'function') {
    const image = lightbox.querySelector('img');
    const caption = lightbox.querySelector('figcaption');

    for (const preview of document.querySelectorAll('.image-preview')) {
        preview.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

            event.preventDefault();
            caption.textContent = preview.dataset.imageTitle;
            image.src = preview.href;
            lightbox.showModal();
        });
    }

    lightbox.addEventListener('click', event => {
        if (event.target === lightbox) lightbox.close();
    });
    lightbox.addEventListener('close', () => image.removeAttribute('src'));
}

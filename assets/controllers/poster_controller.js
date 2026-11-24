import { Controller } from '@hotwired/stimulus';

/*
 * Envoi d'une image (/admin : affiche, photo d'un lieu) : l'image choisie est réduite dans le navigateur
 * (une image d'impression pèse plusieurs Mo, au-delà de la limite de beaucoup
 * de serveurs), puis montrée en aperçu avant l'envoi.
 * En cas d'échec, le fichier d'origine part tel quel : le serveur le réduit aussi.
 */
export default class extends Controller {
    static targets = ['input', 'preview', 'submit', 'status'];
    static values = {
        maxWidth: { type: Number, default: 1200 },
        message: { type: String, default: 'Aperçu : cliquez sur « Remplacer l’affiche » pour la mettre en ligne.' },
    };

    async pick() {
        const file = this.inputTarget.files[0];
        if (!file) return;

        this.submitTarget.disabled = true;
        this.statusTarget.textContent = 'Préparation de l’image…';
        let image = file;
        try {
            image = await this.reduce(file);
            const transfer = new DataTransfer();
            transfer.items.add(image);
            this.inputTarget.files = transfer.files;
        } catch (e) {
            // image illisible ici : le serveur dira ce qui ne va pas
        }
        if (this.url) URL.revokeObjectURL(this.url);
        this.url = URL.createObjectURL(image);
        this.previewTarget.src = this.url;
        this.previewTarget.hidden = false;
        this.statusTarget.textContent = this.messageValue;
        this.submitTarget.disabled = false;
    }

    async reduce(file) {
        const bitmap = await createImageBitmap(file);
        const width = Math.min(bitmap.width, this.maxWidthValue);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = Math.round((bitmap.height * width) / bitmap.width);
        const context = canvas.getContext('2d');
        context.imageSmoothingQuality = 'high';
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const blob = await new Promise((done) => canvas.toBlob(done, 'image/jpeg', 0.92));

        return new File([blob], 'image.jpg', { type: 'image/jpeg' });
    }

    disconnect() {
        if (this.url) URL.revokeObjectURL(this.url);
    }
}

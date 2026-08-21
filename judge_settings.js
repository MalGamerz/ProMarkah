    document.getElementById('photoInput')?.addEventListener('change', function (e) {
        const file = e.target.files[0];
        const fileLabel = document.getElementById('photoFileLabel');
        if (!file) {
            if (fileLabel) fileLabel.textContent = 'Pilih Gambar';
            return;
        }
        if (fileLabel) fileLabel.textContent = file.name;

        const preview = document.getElementById('photoPreview');
        const icon = document.getElementById('photoPreviewIcon');
        const reader = new FileReader();
        reader.onload = function (evt) {
            preview.src = evt.target.result;
            preview.style.display = 'block';
            if (icon) icon.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });

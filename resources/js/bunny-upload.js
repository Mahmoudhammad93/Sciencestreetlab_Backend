// resources/js/bunny-upload.js
import * as tus from 'tus-js-client';

document.addEventListener('alpine:init', () => {
    Alpine.data('bunnyVideoUpload', ({ state }) => ({
        state,
        uploading: false,
        progress: 0,
        error: null,

        async handleFileSelect(e) {
            const file = e.target.files[0];
            if (!file) return;

            this.error = null;
            this.uploading = true;
            this.progress = 0;

            try {
                const creds = await this.$wire.getBunnyUploadCredentials(file.name);

                new tus.Upload(file, {
                    endpoint: 'https://video.bunnycdn.com/tusupload',
                    retryDelays: [0, 3000, 5000, 10000, 20000],
                    headers: {
                        AuthorizationSignature: creds.signature,
                        AuthorizationExpire: creds.expiration,
                        VideoId: creds.videoId,
                        LibraryId: creds.libraryId,
                    },
                    metadata: { filetype: file.type, title: file.name },
                    onProgress: (sent, total) => {
                        this.progress = Math.round((sent / total) * 100);
                    },
                    onError: (err) => {
                        this.error = 'Upload failed: ' + err.message;
                        this.uploading = false;
                    },
                    onSuccess: () => {
                        this.state = creds.videoId;
                        this.uploading = false;
                    },
                }).start();
            } catch (err) {
                this.error = 'Could not start upload: ' + err.message;
                this.uploading = false;
            }
        },
    }));
});
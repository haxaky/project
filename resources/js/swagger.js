import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

const container = document.getElementById('swagger-ui');

SwaggerUIBundle({
    dom_id: '#swagger-ui',
    url: container.dataset.specUrl,
    deepLinking: true,
    filter: true,
    docExpansion: 'list',
    displayRequestDuration: true,
    validatorUrl: null,
    withCredentials: true,
    requestInterceptor: async (request) => {
        const target = new URL(request.url, window.location.origin);
        if (target.origin === window.location.origin) {
            request.credentials = 'same-origin';
            request.headers = request.headers || {};
            // Swagger may supply lowercase accept; duplicate headers can make Laravel prefer HTML.
            for (const name of Object.keys(request.headers)) {
                if (name.toLowerCase() === 'accept') delete request.headers[name];
            }
            request.headers.Accept = 'application/json';
            if (!['GET', 'HEAD', 'OPTIONS'].includes((request.method || 'GET').toUpperCase())) {
                // Login/logout rotate the session token; always obtain the current one.
                const response = await fetch(container.dataset.csrfUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error('Không lấy được CSRF token. Hãy tải lại trang Swagger.');
                request.headers['X-CSRF-TOKEN'] = (await response.json()).token;
            }
        }
        return request;
    },
});
